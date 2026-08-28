<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Actions;

use App\Domain\Catalog\Models\PricingRule;
use App\Domain\Pricing\Exceptions\PricingRuleNotFoundException;
use DateTimeInterface;

/**
 * Menemukan tarif yang berlaku untuk sebuah layanan, zona, dan saat tertentu.
 *
 * Aturan pemilihannya:
 *
 *   1. Tarif khusus zona menang atas tarif default (zone_id NULL).
 *   2. Di antara yang berlaku, yang effective_from paling baru menang.
 *
 * Butir kedua sebenarnya tidak pernah menentukan apa pun, karena exclusion
 * constraint `pricing_rules_no_overlap` di database sudah menjamin tidak ada
 * dua tarif aktif dengan periode bertumpang tindih untuk pasangan (layanan,
 * zona) yang sama. Urutan itu tetap ditulis sebagai jaring: kalau suatu hari
 * constraint-nya dilepas untuk keperluan migrasi dan lupa dipasang kembali,
 * hasil query tetap deterministik alih-alih bergantung pada urutan baris di
 * disk.
 */
class ResolvePricingRule
{
    public function handle(
        int $serviceTypeId,
        ?int $zoneId = null,
        ?DateTimeInterface $at = null,
    ): PricingRule {
        $at ??= now();

        $rule = PricingRule::query()
            ->where('service_type_id', $serviceTypeId)
            ->activeAt($at)
            ->forZone($zoneId)
            ->first();

        if ($rule === null) {
            throw new PricingRuleNotFoundException(
                'Tarif untuk layanan ini belum tersedia. Silakan coba beberapa saat lagi.',
                details: [
                    'service_type_id' => $serviceTypeId,
                    'zone_id' => $zoneId,
                ],
            );
        }

        return $rule;
    }

    /**
     * Semua tarif yang berlaku untuk beberapa layanan sekaligus, di satu zona.
     *
     * Dipakai endpoint quote yang mengembalikan harga untuk semua layanan
     * sekaligus, supaya penumpang bisa membandingkan ride motor dan ride mobil
     * dalam satu tampilan.
     *
     * Satu query untuk semua layanan, bukan satu per layanan. Dengan empat
     * layanan aktif, bedanya antara satu round trip dan empat, di jalur yang
     * dipanggil setiap kali penumpang menggeser pin di peta.
     *
     * @param  array<int, int>  $serviceTypeIds
     * @return array<int, PricingRule> diindeks service_type_id
     */
    public function handleMany(
        array $serviceTypeIds,
        ?int $zoneId = null,
        ?DateTimeInterface $at = null,
    ): array {
        if ($serviceTypeIds === []) {
            return [];
        }

        $at ??= now();

        $rules = PricingRule::query()
            ->whereIn('service_type_id', $serviceTypeIds)
            ->activeAt($at)
            ->where(function ($query) use ($zoneId): void {
                $query->whereNull('zone_id');

                if ($zoneId !== null) {
                    $query->orWhere('zone_id', $zoneId);
                }
            })
            // Urutan menentukan siapa yang menang saat ada dua kandidat untuk
            // satu layanan: tarif khusus zona lebih dulu, lalu yang terbaru.
            ->orderByRaw('zone_id IS NULL')
            ->orderByDesc('effective_from')
            ->get();

        $resolved = [];

        foreach ($rules as $rule) {
            // Yang pertama muncul untuk sebuah layanan adalah yang menang.
            // Sisanya diabaikan.
            $resolved[$rule->service_type_id] ??= $rule;
        }

        return $resolved;
    }
}
