<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Shared\Support\BusinessClock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Membuang baris log lama yang sudah tidak dibutuhkan.
 *
 * ============================================================================
 *  KENAPA INI PERLU, DAN KENAPA BUKAN PARTISI
 * ============================================================================
 *  Tabel di bawah tumbuh terus dan tidak pernah menyusut sendiri.
 *  `order_status_logs` yang paling cepat: sekitar enam baris per order.
 *
 *  Alternatif yang lazim adalah partisi bulanan lalu `DROP PARTITION` — jauh
 *  lebih murah daripada DELETE. Itu TIDAK dipakai di sini, dan keputusannya
 *  sadar: partisi menambah beban operasional nyata (partisi baru harus dibuat
 *  sebelum bulannya tiba, dan yang lupa membuatnya mendapat insert yang gagal),
 *  dan Fase 1 di satu kota tidak menghasilkan volume yang menuntutnya.
 *
 *  DELETE bertahap dengan LIMIT sudah cukup, dan bisa dijalankan kapan pun
 *  tanpa persiapan.
 * ============================================================================
 *
 * ============================================================================
 *  DIHAPUS BERTAHAP, BUKAN SEKALI JALAN
 * ============================================================================
 *  `DELETE FROM order_status_logs WHERE created_at < ...` pada tabel dengan
 *  jutaan baris menahan lock dan menumpuk WAL sampai selesai — dan selama itu
 *  setiap penulisan status order ikut menunggu.
 *
 *  Yang dilakukan di sini: batch 5.000 baris, berhenti kalau sudah tidak ada.
 *  Setiap batch adalah transaksi sendiri, jadi lock-nya dilepas di antaranya.
 * ============================================================================
 *
 * ============================================================================
 *  YANG TIDAK PERNAH DIHAPUS
 * ============================================================================
 *    wallet_transactions   append-only dan ditegakkan trigger database. Ini
 *                          catatan keuangan; menghapusnya berarti buku besar
 *                          yang tidak bisa direkonsiliasi lagi.
 *    orders                riwayat penumpang, dan dasar sengketa.
 *    audit_logs            justru yang paling dibutuhkan saat ada
 *                          investigasi — dan investigasi selalu tentang
 *                          kejadian di masa lalu.
 *
 *  Ketiganya sengaja tidak ada di daftar tabel di bawah. Kalau ada yang
 *  menambahkannya, baca dulu bagian ini.
 * ============================================================================
 */
class PruneLogsCommand extends Command
{
    protected $signature = 'antaride:prune-logs
                            {--dry-run : Hitung saja, jangan hapus apa pun.}
                            {--batch=5000 : Jumlah baris per batch.}';

    protected $description
        = 'Membuang log operasional lama (status order, tawaran, idempotency, login admin, webhook)';

    /**
     * Tabel yang dipangkas, beserta umur simpannya.
     *
     * Umurnya bisa diubah lewat config, bukan konstanta di sini — retensi adalah
     * kebijakan, dan kebijakan berubah tanpa menyentuh kode.
     *
     * @return array<string, array{column: string, days: int, why: string}>
     */
    private function tabel(): array
    {
        return [
            'order_status_logs' => [
                'column' => 'created_at',
                'days' => (int) config('antaride.retention.order_status_logs_days', 90),
                'why' => 'Riwayat perubahan status. Dipakai sengketa, dan sengketa '
                    .'praktis selalu diajukan dalam hitungan hari.',
            ],

            'order_offers' => [
                'column' => 'offered_at',
                'days' => (int) config('antaride.retention.order_offers_days', 60),
                'why' => 'Tawaran yang sudah dijawab atau kadaluarsa. Rasio '
                    .'penerimaan driver sudah diringkas ke driver_daily_metrics, '
                    .'jadi baris mentahnya tidak lagi dibutuhkan.',
            ],

            /*
             * TIDAK ADA `driver_locations` di daftar ini, dan itu benar.
             *
             * Ping GPS driver hanya masuk Redis lewat GEOADD — tidak pernah ke
             * Postgres. Itu keputusan arsitektur: seribu driver dengan ping tiap
             * empat detik adalah 250 tulis per detik yang isinya dua angka, dan
             * Postgres bukan tempat yang tepat untuk itu.
             *
             * Redis membuang sendiri lewat TTL, jadi tidak ada yang perlu
             * dipangkas.
             */

            'admin_login_attempts' => [
                'column' => 'created_at',
                'days' => (int) config('antaride.retention.admin_login_attempts_days', 180),
                'why' => 'Catatan percobaan masuk panel. Disimpan lebih lama dari '
                    .'yang lain karena inilah yang dibaca saat ada dugaan akun '
                    .'admin dibobol — dan dugaan itu selalu muncul belakangan.',
            ],

            'payment_webhook_logs' => [
                'column' => 'created_at',
                'days' => (int) config('antaride.retention.payment_webhook_logs_days', 180),
                'why' => 'Callback mentah dari gateway. Dipakai rekonsiliasi dengan '
                    .'laporan penyelesaian gateway, yang siklusnya bulanan.',
            ],

            'idempotency_keys' => [
                'column' => 'created_at',
                'days' => (int) config('antaride.retention.idempotency_keys_days', 7),
                'why' => 'Kunci idempotency hanya berguna selama percobaan ulang '
                    .'masih mungkin. Tujuh hari sudah jauh di luar itu.',
            ],
        ];
    }

    public function handle(): int
    {
        $batch = max(100, (int) $this->option('batch'));
        $kering = (bool) $this->option('dry-run');

        if ($kering) {
            $this->warn('Mode --dry-run: tidak ada baris yang dihapus.');
            $this->newLine();
        }

        $totalDihapus = 0;

        foreach ($this->tabel() as $nama => $aturan) {
            if (! $this->tabelAda($nama)) {
                // Tabel yang belum ada dilewati tanpa menggagalkan perintahnya.
                // Yang memicunya: migrasi yang belum jalan di lingkungan baru.
                $this->line("  {$nama}: dilewati (tabel belum ada)");

                continue;
            }

            $batasWaktu = BusinessClock::now()->subDays($aturan['days']);

            $jumlah = $this->hitung($nama, $aturan['column'], $batasWaktu);

            if ($jumlah === 0) {
                $this->line("  {$nama}: tidak ada yang perlu dibuang");

                continue;
            }

            if ($kering) {
                $this->line(sprintf(
                    '  %s: %s baris lebih tua dari %d hari',
                    $nama,
                    number_format($jumlah),
                    $aturan['days'],
                ));

                continue;
            }

            $dihapus = $this->pangkas($nama, $aturan['column'], $batasWaktu, $batch);

            $totalDihapus += $dihapus;

            $this->line(sprintf(
                '  %s: %s baris dibuang (lebih tua dari %d hari)',
                $nama,
                number_format($dihapus),
                $aturan['days'],
            ));
        }

        if (! $kering && $totalDihapus > 0) {
            $this->newLine();
            $this->info(number_format($totalDihapus).' baris dibuang seluruhnya.');
        }

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------

    private function tabelAda(string $nama): bool
    {
        return DB::getSchemaBuilder()->hasTable($nama);
    }

    private function hitung(string $tabel, string $kolom, mixed $batas): int
    {
        /*
         * COUNT(*) di sini disengaja, walaupun mahal pada tabel besar.
         *
         * Yang dihitung adalah baris LAMA — bagian yang justru paling jarang
         * disentuh, dan indeks pada kolom waktunya membuat rentangnya terbatas.
         * Estimasi `reltuples` yang dipakai di tempat lain tidak bisa dipakai
         * di sini karena tidak bisa disaring per rentang waktu.
         */
        return (int) DB::table($tabel)->where($kolom, '<', $batas)->count();
    }

    private function pangkas(string $tabel, string $kolom, mixed $batas, int $batch): int
    {
        $total = 0;

        /*
         * Batas 500 putaran, bukan `while (true)`.
         *
         * Kalau ada yang salah — misalnya kolom waktunya null pada baris yang
         * terus muncul lagi — loop tanpa batas akan berjalan sampai proses
         * dimatikan, sambil terus menulis WAL. Dengan batas, perintahnya
         * berhenti dan sisanya dibuang pada jalan berikutnya.
         */
        for ($putaran = 0; $putaran < 500; $putaran++) {
            /*
             * DELETE dengan subquery LIMIT, bukan `->limit()` langsung.
             *
             * PostgreSQL TIDAK mendukung LIMIT pada DELETE. Query builder
             * Laravel menerima `->limit()` di sini tanpa mengeluh, lalu
             * MENGABAIKANNYA di PostgreSQL — dan yang terjadi adalah DELETE
             * seluruh tabel dalam satu transaksi, tepat yang coba dihindari.
             */
            $dihapus = DB::affectingStatement(
                "DELETE FROM {$tabel} WHERE ctid IN (
                    SELECT ctid FROM {$tabel} WHERE {$kolom} < ? LIMIT {$batch}
                )",
                [$batas],
            );

            $total += $dihapus;

            if ($dihapus < $batch) {
                break;
            }
        }

        return $total;
    }
}
