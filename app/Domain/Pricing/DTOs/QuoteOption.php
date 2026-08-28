<?php

declare(strict_types=1);

namespace App\Domain\Pricing\DTOs;

use JsonSerializable;

/**
 * Satu pilihan layanan di dalam sebuah quote.
 *
 * Penumpang melihat beberapa pilihan sekaligus (Antar Motor Rp 16.440, Antar
 * Mobil Rp 34.450) lalu memilih satu. Setiap pilihan punya ongkos, ETA, dan
 * ketersediaan driver sendiri.
 */
final readonly class QuoteOption implements JsonSerializable
{
    public function __construct(
        public string $serviceCode,
        public int $serviceTypeId,
        public string $serviceName,
        public FareBreakdown $fare,
        public SurgeDecision $surge,
        /** Id aturan tarif yang dipakai, untuk jejak audit di order. */
        public int $pricingRuleId,
        /** Perkiraan menit driver sampai ke titik jemput. Null kalau tidak ada driver. */
        public ?int $pickupEtaMinutes,
        /** Jumlah driver tersedia dalam radius pencarian. */
        public int $availableDrivers,
        /** Perkiraan menit seluruh perjalanan, dari OSRM. */
        public int $tripDurationMinutes,
    ) {}

    /**
     * Apakah layanan ini bisa dipesan sekarang.
     *
     * Nol driver TIDAK membuat pilihan ini disembunyikan. Penumpang tetap
     * melihat harganya, hanya dengan penanda bahwa belum ada driver. Ini
     * disengaja: menyembunyikan pilihan membuat penumpang berpikir layanannya
     * tidak ada, lalu pindah aplikasi. Menampilkannya dengan jujur membuat dia
     * menunggu beberapa menit lalu mencoba lagi.
     */
    public function isOrderable(): bool
    {
        return $this->availableDrivers > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'service_code' => $this->serviceCode,
            'service_name' => $this->serviceName,
            'fare' => $this->fare->jsonSerialize(),
            'surge' => [
                'multiplier' => $this->surge->multiplier,
                'active' => $this->surge->isActive(),
                // Alasan teknisnya TIDAK dikirim ke aplikasi. Penumpang butuh
                // tahu kenapa ongkosnya naik, bukan nama aturan yang memicunya
                // atau angka rasio order berbanding driver.
                'explanation' => $this->surge->customerExplanation(),
            ],
            'pickup_eta_minutes' => $this->pickupEtaMinutes,
            'trip_duration_minutes' => $this->tripDurationMinutes,
            'available_drivers' => $this->availableDrivers,
            'orderable' => $this->isOrderable(),
        ];
    }

    /**
     * Bentuk untuk disimpan di Redis.
     *
     * Sengaja dipisah dari jsonSerialize(). Yang disimpan harus memuat SEMUA
     * yang dibutuhkan pembuatan order, termasuk pricing_rule_id dan rincian
     * surge yang tidak pernah dikirim ke aplikasi.
     *
     * @return array<string, mixed>
     */
    public function toStorage(): array
    {
        return [
            'service_code' => $this->serviceCode,
            'service_type_id' => $this->serviceTypeId,
            'service_name' => $this->serviceName,
            'pricing_rule_id' => $this->pricingRuleId,
            'fare' => $this->fare->jsonSerialize(),
            'surge' => $this->surge->toArray(),
            'pickup_eta_minutes' => $this->pickupEtaMinutes,
            'trip_duration_minutes' => $this->tripDurationMinutes,
            'available_drivers' => $this->availableDrivers,
        ];
    }
}
