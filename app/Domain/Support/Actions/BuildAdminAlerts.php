<?php

declare(strict_types=1);

namespace App\Domain\Support\Actions;

use App\Domain\Ordering\Enums\OrderStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Notifikasi backoffice, DITURUNKAN dari keadaan sekarang.
 *
 * ============================================================================
 *  TIDAK DISIMPAN SEBAGAI BARIS, DAN ITU KEPUTUSAN YANG DISENGAJA
 * ============================================================================
 *  Notifikasi mobile disimpan di tabel `notifications` karena isinya PERISTIWA:
 *  "driver ditemukan" tetap benar besok pagi.
 *
 *  Notifikasi backoffice isinya PEKERJAAN YANG BELUM SELESAI, dan itu berbeda
 *  sifatnya. Baris "2 approval menunggu" yang dibuat kemarin tetap berbunyi
 *  begitu walaupun keduanya sudah disetujui — dan tim ops akan mengejar
 *  pekerjaan yang sudah beres, lalu berhenti mempercayai loncengnya.
 *
 *  Yang diturunkan dari keadaan TIDAK BISA basi. Approval yang sudah disetujui
 *  hilang dari hitungan pada refresh berikutnya, tanpa ada yang perlu menandai
 *  apa pun.
 *
 *  Konsekuensinya, dan ini diterima: tidak ada "sudah dibaca". Angkanya turun
 *  saat pekerjaannya diselesaikan, bukan saat seseorang menutup loncengnya. Itu
 *  justru yang diinginkan untuk daftar pekerjaan.
 * ============================================================================
 *
 * ============================================================================
 *  DI-CACHE 30 DETIK
 * ============================================================================
 *  Lonceng ada di SETIAP halaman panel, jadi query-nya jalan di setiap
 *  perpindahan halaman. Lima hitungan agregat per pemuatan halaman, dikalikan
 *  seluruh staf ops yang membuka panel sepanjang hari.
 *
 *  30 detik: cukup pendek supaya approval baru terlihat hampir seketika, cukup
 *  panjang supaya menjelajah panel tidak menghitung ulang di setiap klik.
 *
 *  Cache-nya GLOBAL, bukan per admin. Hitungannya sama untuk semua orang — dan
 *  cache per admin berarti sepuluh staf menghasilkan sepuluh perhitungan yang
 *  hasilnya identik.
 * ============================================================================
 */
final readonly class BuildAdminAlerts
{
    private const CACHE_KEY = 'antaride:admin-alerts';

    private const CACHE_TTL_SECONDS = 30;

    /**
     * @return array{total: int, items: list<array<string, mixed>>}
     */
    public function handle(): array
    {
        /** @var array{total: int, items: list<array<string, mixed>>} */
        return Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->hitung(),
        );
    }

    /**
     * Buang cache-nya.
     *
     * Dipanggil setelah tindakan yang MENGURANGI pekerjaan — approval disetujui,
     * tiket ditutup. Tanpa ini, staf yang baru menyetujui approval masih melihat
     * angka lama sampai 30 detik berikutnya, dan itu terbaca sebagai tindakannya
     * tidak tersimpan.
     */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array{total: int, items: list<array<string, mixed>>}
     */
    private function hitung(): array
    {
        $items = [];

        foreach ($this->sumber() as $sumber) {
            if (! DB::getSchemaBuilder()->hasTable($sumber['table'])) {
                // Tabel yang belum ada dilewati, bukan menggagalkan seluruh
                // lonceng. Yang memicunya: migrasi yang belum jalan di
                // lingkungan baru — dan panel yang tidak bisa dibuka karena
                // loncengnya gagal jauh lebih buruk daripada lonceng yang
                // kekurangan satu baris.
                continue;
            }

            $jumlah = (int) ($sumber['count'])();

            if ($jumlah === 0) {
                // Baris bernilai nol TIDAK ditampilkan.
                //
                // "0 approval menunggu" tidak memberi tahu apa pun, dan lima
                // baris nol membuat satu baris yang benar-benar ada isinya jadi
                // sulit ditemukan.
                continue;
            }

            $items[] = [
                'key' => $sumber['key'],
                'label' => str_replace(':count', (string) $jumlah, $sumber['label']),
                'count' => $jumlah,
                'url' => $this->url($sumber['route']),
                'icon' => $sumber['icon'],
                'severity' => $sumber['severity'],
            ];
        }

        return [
            'total' => array_sum(array_column($items, 'count')),
            'items' => $items,
        ];
    }

    /**
     * Alamat tujuan, dengan penjaga kalau route-nya belum ada.
     *
     * ========================================================================
     *  ROUTE YANG HILANG TIDAK BOLEH MENJATUHKAN SELURUH PANEL
     * ========================================================================
     *  `route()` MELEMPAR pada nama yang tidak terdaftar. Dan lonceng ini ada di
     *  SETIAP halaman panel — jadi satu nama route yang salah ketik berarti
     *  seluruh backoffice tidak bisa dibuka, bukan hanya loncengnya yang kosong.
     *
     *  Yang belum ada halamannya sekarang: `admin.tickets.index`. Tabel
     *  `tickets` sudah ada dan sudah terisi lewat API, tapi layar backoffice-nya
     *  belum dibuat. Alert-nya tetap didaftarkan supaya begitu halaman itu ada,
     *  dia langsung berfungsi tanpa menyentuh berkas ini.
     *
     *  Sampai itu ada, tujuannya jatuh ke dashboard — bukan ke tautan mati yang
     *  menghasilkan 404 saat ditekan.
     * ========================================================================
     */
    private function url(string $route): string
    {
        return Route::has($route)
            ? route($route)
            : route('admin.dashboard');
    }

    /**
     * Sumber notifikasi, terurut dari yang paling menuntut perhatian.
     *
     * Urutannya bukan selera: approval menahan uang, order macet menahan
     * penumpang di jalan. Keduanya di atas tiket bantuan, yang bisa menunggu
     * satu jam tanpa ada yang dirugikan.
     *
     * @return list<array<string, mixed>>
     */
    private function sumber(): array
    {
        return [
            [
                'key' => 'approvals',
                'table' => 'approval_requests',
                'label' => ':count permintaan approval menunggu',
                'route' => 'admin.finance.withdrawals',
                'icon' => 'ki-shield-tick',
                'severity' => 'danger',

                /*
                 * Approval MENAHAN UANG: penarikan saldo driver, penyesuaian
                 * manual, refund. Yang menunggu di sini adalah orang yang
                 * uangnya belum bisa dipakai.
                 *
                 * Tujuannya `admin.finance.withdrawals`, BUKAN halaman approval
                 * tersendiri — halaman itu belum ada, dan penarikan saldo adalah
                 * jenis approval yang paling sering muncul. Di situ pula tombol
                 * setuju dan tolaknya berada.
                 */
                'count' => fn (): int => DB::table('approval_requests')
                    ->where('status', 'pending')
                    ->count(),
            ],

            [
                'key' => 'stuck_orders',
                'table' => 'orders',
                'label' => ':count order macet lebih dari 10 menit',
                'route' => 'admin.orders.index',
                'icon' => 'ki-time',
                'severity' => 'danger',

                /*
                 * Order yang mencari driver lebih dari 10 menit.
                 *
                 * Ini yang paling mendesak dari seluruh daftar: ada penumpang
                 * yang sedang menunggu SEKARANG, dan setiap menit tambahan
                 * adalah menit dia berdiri di pinggir jalan.
                 */
                'count' => fn (): int => DB::table('orders')
                    ->whereIn('status', [
                        OrderStatus::Created->value,
                        OrderStatus::Searching->value,
                    ])
                    ->where('requested_at', '<', now()->subMinutes(10))
                    ->count(),
            ],

            [
                'key' => 'fare_reviews',
                'table' => 'orders',
                'label' => ':count order butuh review ongkos',
                'route' => 'admin.orders.index',
                'icon' => 'ki-bill',
                'severity' => 'warning',

                // Order yang jarak sebenarnya jauh berbeda dari estimasi.
                // Ongkosnya belum final sampai ditinjau, dan driver menunggu
                // kepastian pendapatannya.
                'count' => fn (): int => DB::table('orders')
                    ->where('needs_fare_review', true)
                    ->count(),
            ],

            [
                'key' => 'driver_verifications',
                'table' => 'driver_documents',
                'label' => ':count dokumen driver menunggu verifikasi',
                'route' => 'admin.drivers.verification',
                'icon' => 'ki-user-tick',
                'severity' => 'warning',

                // Driver yang dokumennya belum diverifikasi tidak bisa bekerja.
                // Setiap hari tertunda adalah hari dia tidak berpenghasilan.
                'count' => fn (): int => DB::table('driver_documents')
                    ->where('status', 'pending')
                    ->count(),
            ],

            [
                'key' => 'open_tickets',
                'table' => 'tickets',
                'label' => ':count tiket bantuan belum ditangani',
                'route' => 'admin.tickets.index',
                'icon' => 'ki-message-question',
                'severity' => 'info',

                'count' => fn (): int => DB::table('tickets')
                    ->where('status', 'open')
                    ->count(),
            ],
        ];
    }
}
