<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Metrics\Actions\AggregateDailyMetrics;
use App\Domain\Shared\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Meringkas hari operasional ke tabel metrics.
 *
 * ============================================================================
 *  DIJALANKAN DUA KALI DENGAN TUJUAN BERBEDA
 * ============================================================================
 *    Tiap 15 menit    hari INI, supaya dashboard tidak tertinggal sampai
 *                     tengah malam. Angkanya belum final, dan itu tidak apa-apa
 *                     — grafik tren memang untuk melihat arah.
 *
 *    01:30 WIB        hari KEMARIN, setelah batas hari bisnis benar-benar
 *                     lewat. Ini yang jadi angka final.
 *
 *  Keduanya idempoten (upsert), jadi menjalankan yang satu tidak merusak yang
 *  lain — dan hari yang sama boleh dihitung berapa kali pun.
 * ============================================================================
 */
class AggregateMetricsCommand extends Command
{
    protected $signature = 'antaride:aggregate-metrics
                            {--date= : Tanggal yang diagregasi (YYYY-MM-DD). Bawaannya kemarin.}
                            {--today : Agregasi hari ini alih-alih kemarin.}
                            {--days= : Agregasi ulang N hari terakhir, untuk mengisi data lama.}';

    protected $description = 'Meringkas order harian ke metrics_daily dan driver_daily_metrics';

    public function handle(AggregateDailyMetrics $action): int
    {
        $tanggal = $this->tanggalYangDiminta();

        if ($tanggal === null) {
            return self::FAILURE;
        }

        $jumlahHari = (int) ($this->option('days') ?? 1);

        if ($jumlahHari < 1) {
            $this->error('--days harus 1 atau lebih.');

            return self::FAILURE;
        }

        /*
         * Batas 400 hari.
         *
         * Bukan pembatasan yang berarti — sekitar 13 bulan sudah lebih dari
         * cukup untuk mengisi data lama. Yang dijaganya adalah salah ketik:
         * `--days=3650` akan berjalan berjam-jam sambil menahan koneksi
         * database, dan yang menjalankannya biasanya tidak menyadarinya sampai
         * ada yang mengeluh panel lambat.
         */
        if ($jumlahHari > 400) {
            $this->error('--days maksimal 400. Untuk lebih dari itu, jalankan bertahap.');

            return self::FAILURE;
        }

        $gagal = 0;

        for ($i = 0; $i < $jumlahHari; $i++) {
            $hari = $tanggal->subDays($i);

            try {
                $hasil = $action->handle($hari);

                $this->line(sprintf(
                    '  %s  %d baris harian, %d baris driver',
                    $hari->toDateString(),
                    $hasil['daily_rows'],
                    $hasil['driver_rows'],
                ));
            } catch (Throwable $e) {
                /*
                 * Satu hari yang gagal TIDAK menghentikan sisanya.
                 *
                 * Saat mengisi data lama, satu hari yang datanya rusak akan
                 * menggagalkan seluruh pengisian kalau exception-nya dibiarkan
                 * naik — dan yang terjadi biasanya bukan perbaikan, tapi
                 * pengisian yang tidak pernah diselesaikan.
                 *
                 * Exit code tetap FAILURE supaya scheduler dan CI tetap tahu ada
                 * yang salah.
                 */
                $gagal++;

                $this->error(sprintf(
                    '  %s  GAGAL: %s',
                    $hari->toDateString(),
                    $e->getMessage(),
                ));

                report($e);
            }
        }

        if ($gagal > 0) {
            $this->newLine();
            $this->error("{$gagal} dari {$jumlahHari} hari gagal diagregasi.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function tanggalYangDiminta(): ?CarbonImmutable
    {
        $diminta = $this->option('date');

        if ($diminta !== null) {
            try {
                // Diurai di zona BISNIS, bukan UTC. `--date=2026-03-01` berarti
                // 1 Maret menurut WIB — yang memang yang dimaksud orang yang
                // mengetiknya.
                return CarbonImmutable::createFromFormat(
                    'Y-m-d',
                    (string) $diminta,
                    BusinessClock::timezone(),
                )->startOfDay();
            } catch (Throwable) {
                $this->error('Format --date harus YYYY-MM-DD.');

                return null;
            }
        }

        // Dibungkus CarbonImmutable supaya `subDays()` di loop tidak memutasi
        // tanggal awalnya. Carbon dari BusinessClock bersifat mutable.
        return CarbonImmutable::instance(
            $this->option('today')
                ? BusinessClock::now()
                : BusinessClock::now()->subDay(),
        )->startOfDay();
    }
}
