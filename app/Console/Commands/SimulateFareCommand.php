<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Catalog\Models\PricingRule;
use App\Domain\Catalog\Models\ServiceType;
use App\Domain\Catalog\Models\Zone;
use App\Domain\Pricing\Actions\ResolvePricingRule;
use App\Domain\Pricing\Calculator\FareCalculator;
use App\Domain\Pricing\DTOs\FareBreakdown;
use App\Domain\Pricing\DTOs\RouteResult;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Shared\ValueObjects\Polyline;
use Illuminate\Console\Command;

/**
 * Simulator tarif.
 *
 * Menyetujui tarif tanpa melihat dampaknya adalah cara paling umum tarif salah
 * masuk produksi. Angka per km terlihat masuk akal sendiri-sendiri, tapi
 * gabungannya pada jarak 8 km bisa menghasilkan ongkos yang tidak ada yang mau
 * membayar, dan itu baru diketahui setelah order berhenti masuk.
 *
 * Perintah ini menampilkan rincian ongkos untuk beberapa jarak sekaligus, jadi
 * bentuk kurva tarifnya terlihat, bukan hanya satu titik.
 *
 * Logika yang sama akan dipakai halaman simulator di panel admin, memanggil
 * FareCalculator yang sama. Itu penting: simulator yang memakai perhitungan
 * sendiri akan menunjukkan angka yang berbeda dari yang benar-benar ditagih,
 * dan itu lebih buruk daripada tidak punya simulator.
 */
class SimulateFareCommand extends Command
{
    protected $signature = 'antaride:simulate-fare
        {--service= : Kode layanan, misal ride_bike}
        {--zone= : Kode zona, misal MDN-KOTA. Kosong berarti tarif default}
        {--distance= : Jarak dalam meter untuk satu simulasi}
        {--duration= : Durasi dalam detik untuk satu simulasi}
        {--surge=1.00 : Pengali surge}
        {--discount=0 : Potongan promo dalam Rupiah}';

    protected $description = 'Simulasikan ongkos untuk tarif yang sedang berlaku';

    /**
     * Jarak dan durasi yang mewakili perjalanan nyata di kota.
     *
     * Durasinya bukan asal: kecepatan rata-rata sepeda motor di Medan pada jam
     * biasa sekitar 22 km/jam sudah termasuk lampu merah, dan angka di bawah
     * mengikuti itu. Simulasi dengan durasi yang tidak realistis akan
     * menyembunyikan pengaruh komponen biaya waktu.
     *
     * @var array<int, array{0: int, 1: int, 2: string}>
     */
    private const PROFILES = [
        [1_000, 200, 'sangat pendek, dalam satu kelurahan'],
        [2_500, 450, 'pendek'],
        [5_000, 850, 'menengah'],
        [8_000, 1_350, 'jarak paling umum'],
        [12_000, 2_000, 'lintas kecamatan'],
        [20_000, 3_300, 'jauh'],
        [35_000, 4_800, 'ke bandara'],
    ];

    public function handle(
        ResolvePricingRule $resolver,
        FareCalculator $calculator,
    ): int {
        $service = $this->resolveService();

        if ($service === null) {
            return self::FAILURE;
        }

        $zone = $this->resolveZone();

        if ($zone === false) {
            return self::FAILURE;
        }

        try {
            $rule = $resolver->handle($service->id, $zone?->id);
        } catch (\Throwable $e) {
            $this->error('  '.$e->getMessage());

            return self::FAILURE;
        }

        $surge = (string) $this->option('surge');
        $discount = Money::of((int) $this->option('discount'));

        $this->printRule($service, $zone, $rule, $surge, $discount);

        $profiles = $this->profiles();

        $rows = [];

        foreach ($profiles as [$distance, $duration, $label]) {
            $fare = $calculator->calculate(
                new RouteResult($distance, $duration, Polyline::empty()),
                $rule,
                $surge,
                $discount,
            );

            $perKm = $distance > 0
                ? (int) round($fare->total->amount / ($distance / 1000))
                : 0;

            $rows[] = [
                number_format($distance / 1000, 1, ',', '.').' km',
                (int) ceil($duration / 60).' mnt',
                $fare->total->format(),
                'Rp '.number_format($perKm, 0, ',', '.'),
                $fare->driverEarning->format(),
                $fare->commission->format(),
                $this->flags($fare),
            ];
        }

        $this->table(
            ['Jarak', 'Durasi', 'Penumpang bayar', 'Per km efektif', 'Driver terima', 'Komisi', 'Catatan'],
            $rows,
        );

        // Rincian lengkap untuk satu jarak, supaya komponennya terlihat.
        $this->printBreakdown($calculator, $rule, $surge, $discount, $profiles);

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------

    private function resolveService(): ?ServiceType
    {
        $code = $this->option('service');

        if ($code === null) {
            $available = ServiceType::query()->orderBy('sort_order')->pluck('code')->all();

            if ($available === []) {
                $this->error('  Belum ada layanan. Jalankan: php artisan db:seed');

                return null;
            }

            $code = $this->choice('Layanan mana?', $available, 0);
        }

        $service = ServiceType::query()->code((string) $code)->first();

        if ($service === null) {
            $this->error("  Layanan dengan kode \"{$code}\" tidak ditemukan.");

            return null;
        }

        return $service;
    }

    /**
     * @return Zone|null|false false berarti kode zona salah
     */
    private function resolveZone(): Zone|null|false
    {
        $code = $this->option('zone');

        if ($code === null) {
            return null;
        }

        $zone = Zone::query()->where('code', $code)->first();

        if ($zone === null) {
            $this->error("  Zona dengan kode \"{$code}\" tidak ditemukan.");

            return false;
        }

        return $zone;
    }

    /**
     * @return array<int, array{0: int, 1: int, 2: string}>
     */
    private function profiles(): array
    {
        $distance = $this->option('distance');

        if ($distance === null) {
            return self::PROFILES;
        }

        $duration = $this->option('duration');

        if ($duration === null) {
            // Perkiraan dari kecepatan rata-rata 22 km/jam, supaya komponen
            // biaya waktu tidak diabaikan hanya karena durasinya tidak diisi.
            $duration = (int) round(((int) $distance / 1000) / 22 * 3600);
            $this->line("  Durasi tidak diisi, diperkirakan {$duration} detik dari kecepatan 22 km/jam.");
        }

        return [[(int) $distance, (int) $duration, 'sesuai permintaan']];
    }

    private function printRule(
        ServiceType $service,
        ?Zone $zone,
        PricingRule $rule,
        string $surge,
        Money $discount,
    ): void {
        $this->newLine();
        $this->line('  <options=bold>Tarif yang dipakai</>');
        $this->line('  '.str_repeat('-', 66));

        $this->line(sprintf('  Layanan           : %s (%s)', $service->name, $service->code));
        $this->line(sprintf(
            '  Zona              : %s',
            $rule->isDefault()
                ? 'tarif default (berlaku untuk semua zona tanpa tarif khusus)'
                // `??` sudah memakai semantik isset(), jadi akses properti pada
                // null tidak melempar dan `?->` di sini tidak menambah apa pun.
                : ($zone->name ?? 'zona '.$rule->zone_id),
        ));
        $this->line(sprintf(
            '  Berlaku sejak     : %s%s',
            $rule->effective_from->format('d M Y H:i'),
            $rule->effective_until === null
                ? ' (belum ada penggantinya)'
                : ' sampai '.$rule->effective_until->format('d M Y H:i'),
        ));

        $this->newLine();
        $this->line(sprintf('  Tarif buka pintu  : %s', Money::of($rule->base_fare)->format()));
        $this->line(sprintf('  Per kilometer     : %s', Money::of($rule->per_km)->format()));
        $this->line(sprintf('  Per menit         : %s', Money::of($rule->per_minute)->format()));
        $this->line(sprintf('  Jarak gratis      : %s m', number_format($rule->free_distance_m, 0, ',', '.')));
        $this->line(sprintf('  Ongkos minimum    : %s', Money::of($rule->minimum_fare)->format()));
        $this->line(sprintf('  Biaya aplikasi    : %s', Money::of($rule->platform_fee)->format()));
        $this->line(sprintf('  Komisi platform   : %s%%', $rule->commission_percent));

        $this->line(sprintf(
            '  Batas Kemenhub    : %s',
            $rule->min_fare_regulated === null && $rule->max_fare_regulated === null
                ? 'BELUM DIISI'
                : sprintf(
                    '%s sampai %s',
                    $rule->min_fare_regulated === null ? 'tanpa batas' : Money::of($rule->min_fare_regulated)->format(),
                    $rule->max_fare_regulated === null ? 'tanpa batas' : Money::of($rule->max_fare_regulated)->format(),
                ),
        ));

        if ($rule->min_fare_regulated === null && $rule->max_fare_regulated === null) {
            $this->line('                      <fg=yellow>Wajib diisi sebelum go-live. Konfirmasi ke sumber resmi.</>');
        }

        $this->newLine();
        $this->line(sprintf('  Simulasi surge    : %sx', $surge));
        $this->line(sprintf('  Simulasi promo    : %s', $discount->format()));
        $this->newLine();
    }

    /**
     * @param  array<int, array{0: int, 1: int, 2: string}>  $profiles
     */
    private function printBreakdown(
        FareCalculator $calculator,
        PricingRule $rule,
        string $surge,
        Money $discount,
        array $profiles,
    ): void {
        // Jarak yang paling umum dipakai sebagai contoh rincian.
        $sample = $profiles[3] ?? $profiles[0];

        $fare = $calculator->calculate(
            new RouteResult($sample[0], $sample[1], Polyline::empty()),
            $rule,
            $surge,
            $discount,
        );

        $this->line(sprintf(
            '  <options=bold>Rincian untuk %s km (%s)</>',
            number_format($sample[0] / 1000, 1, ',', '.'),
            $sample[2],
        ));
        $this->line('  '.str_repeat('-', 66));

        foreach ($fare->displayLines() as $line) {
            $this->line(sprintf('  %-34s %18s', $line['label'], $line['formatted']));
        }

        $this->line('  '.str_repeat('-', 66));
        $this->line(sprintf('  <options=bold>%-34s %18s</>', 'Total dibayar penumpang', $fare->total->format()));
        $this->newLine();
        $this->line(sprintf('  %-34s %18s', 'Driver terima', $fare->driverEarning->format()));
        $this->line(sprintf('  %-34s %18s', 'Platform terima (komisi + biaya)', $fare->commission->plus($fare->platformFee)->format()));

        $this->newLine();

        if (! $fare->linesSumToTotal()) {
            $this->error('  Rincian TIDAK menjumlah ke total. Ini bug, laporkan.');
        }

        if (! $fare->isBalanced()) {
            $this->error('  Pembagian uang TIDAK utuh. Ini bug, laporkan.');
        }
    }

    private function flags(FareBreakdown $fare): string
    {
        $notes = [];

        if ($fare->raisedToMinimum) {
            $notes[] = 'naik ke minimum';
        }

        if ($fare->clampedToRegulation) {
            $notes[] = $fare->regulatoryAdjustment->isNegative()
                ? 'dipotong batas atas'
                : 'dinaikkan batas bawah';
        }

        return $notes === [] ? '' : implode(', ', $notes);
    }
}
