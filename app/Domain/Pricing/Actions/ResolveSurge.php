<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Actions;

use App\Domain\Catalog\Models\SurgeRule;
use App\Domain\Matching\Contracts\DriverLocationIndex;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Pricing\DTOs\SurgeDecision;
use App\Domain\Support\Models\FeatureFlag;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Menentukan pengali surge untuk sebuah zona dan layanan.
 *
 * ============================================================================
 *  PENGALI TIDAK PERNAH DIKALIKAN SATU SAMA LAIN
 * ============================================================================
 *  Kalau jadwal jam sibuk (1,3x) dan rasio permintaan (1,5x) berlaku
 *  bersamaan, hasilnya 1,5x — BUKAN 1,95x.
 *
 *  Ini keputusan yang paling mudah salah di seluruh fitur surge, dan
 *  kesalahannya tidak akan terlihat di pengujian normal karena butuh dua
 *  aturan aktif bersamaan. Yang terjadi di produksi: pada jam pulang kerja
 *  saat hujan, keduanya aktif dan penumpang tiba-tiba melihat ongkos hampir
 *  dua kali lipat. Itu keluhan yang sampai ke media sosial dalam satu jam.
 *
 *  Yang dipakai adalah pengali TERTINGGI di antara aturan yang berlaku.
 * ============================================================================
 *
 *  URUTAN KEWENANGAN
 *
 *   1. manual        keputusan eksplisit tim ops, menang mutlak
 *   2. schedule & demand_ratio   yang tertinggi di antara keduanya
 *
 *  Surge manual menang mutlak, bahkan kalau nilainya LEBIH RENDAH dari surge
 *  otomatis. Alasannya: tombol manual dipakai justru saat tim ops perlu
 *  membatalkan perilaku otomatis, misalnya menurunkan surge di area bencana
 *  supaya orang tetap bisa mengungsi.
 */
class ResolveSurge
{
    public function __construct(
        private readonly DriverLocationIndex $driverIndex,
    ) {}

    public function handle(
        int $zoneId,
        int $serviceTypeId,
        string $serviceCode,
        ?DateTimeInterface $at = null,
    ): SurgeDecision {
        // Kill switch global. Kalau surge dimatikan seluruh sistem, tidak ada
        // aturan mana pun yang perlu diperiksa.
        if (! $this->surgeEnabled()) {
            return SurgeDecision::none();
        }

        $at ??= now();

        $rules = SurgeRule::query()
            ->active()
            ->forZoneAndService($zoneId, $serviceTypeId)
            ->get();

        if ($rules->isEmpty()) {
            return SurgeDecision::none();
        }

        // --- 1. Manual menang mutlak ---

        foreach ($rules as $rule) {
            if ($rule->isManualStillValid($at)) {
                return new SurgeDecision(
                    multiplier: $this->normalize((string) $rule->multiplier),
                    reason: 'manual',
                    surgeRuleId: $rule->id,
                );
            }
        }

        // --- 2. Jadwal dan rasio permintaan, yang tertinggi menang ---

        $best = SurgeDecision::none();

        // Rasio dihitung sekali, bukan per aturan. Perhitungannya menyentuh
        // Redis dan database, dan sebuah zona bisa punya beberapa aturan
        // demand_ratio dengan ambang berbeda.
        $demandContext = null;

        foreach ($rules as $rule) {
            $candidate = null;

            if ($rule->scheduleCovers($at)) {
                $candidate = new SurgeDecision(
                    multiplier: $this->normalize((string) $rule->multiplier),
                    reason: 'schedule',
                    surgeRuleId: $rule->id,
                );
            } elseif ($rule->isDemandRatio()) {
                $demandContext ??= $this->measureDemand($zoneId, $serviceCode, $serviceTypeId);

                if ($rule->demandThresholdReached($demandContext['ratio'])) {
                    $candidate = new SurgeDecision(
                        multiplier: $this->normalize((string) $rule->multiplier),
                        reason: 'demand_ratio',
                        surgeRuleId: $rule->id,
                        demandRatio: $demandContext['ratio'],
                        availableDrivers: $demandContext['drivers'],
                        pendingOrders: $demandContext['orders'],
                    );
                }
            }

            // Yang tertinggi menang. TIDAK dikalikan.
            if ($candidate !== null && bccomp($candidate->multiplier, $best->multiplier, 2) > 0) {
                $best = $candidate;
            }
        }

        return $best;
    }

    // -------------------------------------------------------------------------

    /**
     * Rasio order yang sedang mencari driver berbanding driver tersedia.
     *
     * Pembilang dari database (order berstatus searching di zona itu), pembagi
     * dari Redis (driver tersedia). Keduanya dari sumber yang berbeda karena
     * memang tinggal di tempat berbeda: order adalah data transaksional,
     * ketersediaan driver adalah keadaan sesaat.
     *
     * Pembagi nol ditangani secara khusus. Nol driver dengan ada order berarti
     * kelangkaan maksimum, tapi pembagian dengan nol tidak bisa menghasilkan
     * angka. Yang dikembalikan adalah rasio besar tetap, cukup untuk melewati
     * ambang mana pun tanpa menghasilkan INF yang tidak bisa disimpan.
     *
     * @return array{ratio: string, drivers: int, orders: int}
     */
    private function measureDemand(int $zoneId, string $serviceCode, int $serviceTypeId): array
    {
        $availableDrivers = $this->driverIndex->availableCount($serviceCode, $zoneId);

        $pendingOrders = DB::table('orders')
            ->where('zone_id', $zoneId)
            ->where('service_type_id', $serviceTypeId)
            ->where('status', OrderStatus::Searching->value)
            ->count();

        if ($pendingOrders === 0) {
            return ['ratio' => '0.0000', 'drivers' => $availableDrivers, 'orders' => 0];
        }

        if ($availableDrivers === 0) {
            // Kelangkaan maksimum. Angka 999 dipilih supaya melewati ambang
            // apa pun yang masuk akal, dan tetap berupa angka yang bisa
            // disimpan serta dibaca di audit.
            return ['ratio' => '999.0000', 'drivers' => 0, 'orders' => $pendingOrders];
        }

        return [
            'ratio' => bcdiv((string) $pendingOrders, (string) $availableDrivers, 4),
            'drivers' => $availableDrivers,
            'orders' => $pendingOrders,
        ];
    }

    /**
     * Dibaca dari feature_flags, bukan config, supaya tim ops bisa mematikan
     * surge seluruh sistem tanpa deploy.
     *
     * Default TRUE: surge yang hilang hanya mengurangi pendapatan, tidak
     * merusak apa pun. Bandingkan dengan withdrawal.auto_approve yang
     * default-nya FALSE karena yang hilang di sana adalah kontrol atas uang
     * keluar.
     *
     * Pola cache-nya ada di FeatureFlag, bukan ditulis ulang di sini — itu yang
     * membuat panel admin bisa membatalkan cache-nya saat flag diubah.
     */
    private function surgeEnabled(): bool
    {
        return FeatureFlag::isEnabled('surge.enabled', default: true);
    }

    private function normalize(string $multiplier): string
    {
        return number_format((float) $multiplier, 2, '.', '');
    }
}
