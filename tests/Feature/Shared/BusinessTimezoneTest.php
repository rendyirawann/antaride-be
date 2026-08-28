<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Domain\Catalog\Models\SurgeRule;
use App\Domain\Merchant\Models\MerchantOperatingHour;
use App\Domain\Shared\Support\BusinessClock;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Mengunci pemisahan antara zona penyimpanan dan zona bisnis.
 *
 * ============================================================================
 *  BUG YANG DITANGKAP TEST INI
 * ============================================================================
 *  Aplikasi berjalan di UTC, dan itu benar untuk penyimpanan. Tapi keputusan
 *  bisnis dibuat dalam WIB: "jam pulang kerja" adalah 17:00 WIB, bukan 17:00
 *  UTC.
 *
 *  Sebelum BusinessClock ada, perbandingan jam dilakukan langsung pada waktu
 *  UTC. Akibatnya, dan ini sudah dibuktikan sebelum diperbaiki:
 *
 *    - Aturan surge berjadwal 17:00-19:30 mengembalikan FALSE pada 17:30 WIB,
 *      karena UTC-nya 10:30. Surge jam sibuk tidak akan pernah menyala.
 *    - Jam buka merchant bergeser tujuh jam. Warung yang buka 08:00-20:00 WIB
 *      akan tampak buka 15:00-03:00 WIB.
 *    - "Pelanggaran hari ini" dihitung sejak jam 7 pagi WIB, bukan tengah
 *      malam.
 *    - Nomor order berganti tanggal pada jam 7 pagi WIB.
 *
 *  Tidak ada satu pun error yang muncul untuk semua itu. Yang terlihat hanya
 *  fitur yang tidak pernah aktif.
 * ============================================================================
 */
class BusinessTimezoneTest extends TestCase
{
    /**
     * Penyimpanan HARUS tetap UTC. Kalau ini berubah, seluruh timestamp yang
     * sudah tersimpan jadi salah tafsir.
     */
    public function test_zona_penyimpanan_tetap_utc(): void
    {
        $this->assertSame(
            'UTC',
            config('app.timezone'),
            'app.timezone bukan UTC. Timestamp yang disimpan jadi bergantung setelan server.',
        );

        $this->assertSame('UTC', now()->timezoneName);
    }

    /**
     * Zona bisnis HARUS terpisah dan bukan UTC.
     */
    public function test_zona_bisnis_terpisah_dari_zona_penyimpanan(): void
    {
        $this->assertSame('Asia/Jakarta', BusinessClock::timezone());

        $this->assertNotSame(
            config('app.timezone'),
            BusinessClock::timezone(),
            'Zona bisnis sama dengan zona penyimpanan; pemisahannya hilang.',
        );
    }

    public function test_business_clock_mengonversi_utc_ke_wib(): void
    {
        // 10:30 UTC adalah 17:30 WIB.
        $utc = Carbon::parse('2026-03-02 10:30:00', 'UTC');

        $this->assertSame('17:30:00', BusinessClock::timeOfDay($utc));
        $this->assertSame('2026-03-02', BusinessClock::date($utc));
    }

    /**
     * Tengah malam WIB adalah 17:00 UTC hari sebelumnya.
     *
     * Ini yang membuat startOfToday() tidak bisa diganti now()->startOfDay().
     */
    public function test_awal_hari_bisnis_dikembalikan_dalam_utc(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-02 03:00:00', 'UTC'));

        $start = BusinessClock::startOfToday();

        // 03:00 UTC = 10:00 WIB tanggal 2. Tengah malam WIB tanggal 2 adalah
        // 17:00 UTC tanggal 1.
        $this->assertSame('2026-03-01 17:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $start->timezoneName);

        Carbon::setTestNow();
    }

    /**
     * Hari dalam seminggu dihitung dalam zona bisnis.
     *
     * 17:00 UTC hari Minggu sudah tengah malam hari Senin di WIB. Tanpa
     * konversi, jadwal surge Senin tidak akan aktif pada jam-jam awal Senin.
     */
    public function test_hari_dalam_seminggu_memakai_zona_bisnis(): void
    {
        // Minggu 1 Maret 2026, 18:00 UTC = Senin 2 Maret 01:00 WIB.
        $utc = Carbon::parse('2026-03-01 18:00:00', 'UTC');

        $this->assertSame(0, $utc->dayOfWeek, 'Pra-syarat test: UTC-nya Minggu.');
        $this->assertSame(1, BusinessClock::dayOfWeek($utc), 'Zona bisnis seharusnya sudah Senin.');
    }

    // -------------------------------------------------------------------------
    // Surge: bug yang memicu seluruh perbaikan ini
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: bool, 2: string}>
     */
    public static function surgeMoments(): array
    {
        return [
            // Senin, jadwal 17:00-19:30 WIB
            'tepat mulai' => ['2026-03-02 17:00:00', true, 'batas awal inklusif'],
            'jam pulang kerja' => ['2026-03-02 17:30:00', true, 'kasus yang dulunya gagal'],
            'hampir selesai' => ['2026-03-02 19:29:00', true, 'masih di dalam'],
            'tepat selesai' => ['2026-03-02 19:30:00', false, 'batas akhir eksklusif'],
            'sebelum mulai' => ['2026-03-02 16:59:00', false, 'belum masuk'],
            'tengah malam senin' => ['2026-03-02 00:30:00', false, 'jam yang salah'],
            'selasa jam sama' => ['2026-03-03 17:30:00', false, 'hari yang salah'],
        ];
    }

    #[DataProvider('surgeMoments')]
    public function test_jadwal_surge_memakai_jam_wib(string $wibTime, bool $expected, string $why): void
    {
        $rule = new SurgeRule;
        $rule->trigger_type = 'schedule';
        $rule->day_of_week = 1; // Senin
        $rule->start_time = '17:00:00';
        $rule->end_time = '19:30:00';
        $rule->multiplier = '1.30';

        // Waktu yang masuk ke method SELALU UTC, seperti di produksi.
        $utc = Carbon::parse($wibTime, 'Asia/Jakarta')->utc();

        $this->assertSame(
            $expected,
            $rule->scheduleCovers($utc),
            sprintf(
                '%s WIB (%s UTC): %s. Alasan: %s',
                Carbon::parse($wibTime, 'Asia/Jakarta')->format('D H:i'),
                $utc->format('D H:i'),
                $expected ? 'seharusnya aktif' : 'seharusnya tidak aktif',
                $why,
            ),
        );
    }

    /**
     * Jadwal yang melewati tengah malam tetap benar di zona bisnis.
     *
     * Jadwal Jumat 22:00-02:00 harus aktif pada Sabtu 01:00 WIB.
     */
    public function test_jadwal_surge_melewati_tengah_malam(): void
    {
        $rule = new SurgeRule;
        $rule->trigger_type = 'schedule';
        $rule->day_of_week = 5; // Jumat
        $rule->start_time = '22:00:00';
        $rule->end_time = '02:00:00';
        $rule->multiplier = '1.50';

        $cases = [
            ['2026-03-06 22:30:00', true, 'Jumat 22:30 WIB'],
            ['2026-03-07 01:00:00', true, 'Sabtu 01:00 WIB, masih jadwal Jumat'],
            ['2026-03-07 02:30:00', false, 'Sabtu 02:30 WIB, sudah lewat'],
            ['2026-03-06 21:00:00', false, 'Jumat 21:00 WIB, belum mulai'],
        ];

        foreach ($cases as [$wib, $expected, $label]) {
            $utc = Carbon::parse($wib, 'Asia/Jakarta')->utc();

            $this->assertSame($expected, $rule->scheduleCovers($utc), $label);
        }
    }

    // -------------------------------------------------------------------------
    // Jam operasional merchant
    // -------------------------------------------------------------------------

    public function test_jam_buka_merchant_memakai_jam_wib(): void
    {
        $hour = new MerchantOperatingHour;
        $hour->day_of_week = 1;
        $hour->open_time = '08:00:00';
        $hour->close_time = '20:00:00';
        $hour->is_closed = false;

        $cases = [
            ['2026-03-02 08:00:00', true, 'tepat buka'],
            ['2026-03-02 12:00:00', true, 'tengah hari'],
            ['2026-03-02 19:59:00', true, 'hampir tutup'],
            ['2026-03-02 20:00:00', false, 'tepat tutup, eksklusif'],
            ['2026-03-02 07:59:00', false, 'belum buka'],
            ['2026-03-02 03:00:00', false, 'dini hari'],
        ];

        foreach ($cases as [$wib, $expected, $label]) {
            $utc = Carbon::parse($wib, 'Asia/Jakarta')->utc();

            $this->assertSame($expected, $hour->covers($utc), "{$label} ({$wib} WIB)");
        }
    }

    /**
     * Warung malam yang buka sampai jam 2 pagi tetap buka jam 1 pagi.
     */
    public function test_jam_buka_merchant_melewati_tengah_malam(): void
    {
        $hour = new MerchantOperatingHour;
        $hour->day_of_week = 5;
        $hour->open_time = '18:00:00';
        $hour->close_time = '02:00:00';
        $hour->is_closed = false;

        $satuPagi = Carbon::parse('2026-03-07 01:00:00', 'Asia/Jakarta')->utc();
        $sianganHari = Carbon::parse('2026-03-06 14:00:00', 'Asia/Jakarta')->utc();

        $this->assertTrue($hour->covers($satuPagi), 'Warung malam seharusnya buka jam 1 pagi.');
        $this->assertFalse($hour->covers($sianganHari), 'Warung malam seharusnya tutup jam 2 siang.');
    }

    public function test_hari_tutup_selalu_tertutup(): void
    {
        $hour = new MerchantOperatingHour;
        $hour->day_of_week = 0;
        $hour->open_time = '08:00:00';
        $hour->close_time = '20:00:00';
        $hour->is_closed = true;

        $tengahHari = Carbon::parse('2026-03-01 12:00:00', 'Asia/Jakarta')->utc();

        $this->assertFalse($hour->covers($tengahHari));
    }

    // -------------------------------------------------------------------------
    // Penjaga: tidak boleh ada perbandingan jam pada waktu mentah
    // -------------------------------------------------------------------------

    /**
     * Tidak boleh ada kode yang membandingkan jam atau batas hari tanpa melewati
     * BusinessClock.
     *
     * Ini penjaga terhadap kambuhnya bug yang sama. Menambahkan satu
     * `now()->startOfDay()` baru di job agregasi metrik akan menghasilkan
     * kesalahan tujuh jam yang tidak terlihat, dan test ini yang menangkapnya.
     */
    public function test_tidak_ada_perbandingan_waktu_yang_melewati_business_clock(): void
    {
        $forbidden = [
            'now()->startOfDay()' => 'pakai BusinessClock::startOfToday()',
            'now()->toDateString()' => 'pakai BusinessClock::date()',
            '->whereDate(' => 'pakai BusinessClock::dayRange() dengan whereBetween',
        ];

        $violations = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path())
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            // BusinessClock sendiri memang memakai primitif itu; di situlah
            // tempatnya.
            if (str_contains($file->getPathname(), 'BusinessClock.php')) {
                continue;
            }

            $lines = file($file->getPathname());
            $relative = str_replace([base_path().DIRECTORY_SEPARATOR, '\\'], ['', '/'], $file->getPathname());

            foreach ($lines as $number => $line) {
                // Komentar tidak dihitung; komentar yang MENYEBUT pola terlarang
                // justru biasanya menjelaskan kenapa tidak dipakai.
                $trimmed = ltrim($line);

                if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')) {
                    continue;
                }

                foreach ($forbidden as $pattern => $advice) {
                    if (str_contains($line, $pattern)) {
                        $violations[] = "{$relative}:".($number + 1)."  {$pattern}  ->  {$advice}";
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            count($violations)." tempat membandingkan waktu tanpa zona bisnis:\n  "
            .implode("\n  ", $violations),
        );
    }
}
