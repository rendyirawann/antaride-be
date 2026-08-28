<?php

declare(strict_types=1);

namespace App\Domain\Pricing\DTOs;

use App\Domain\Shared\ValueObjects\Coordinate;
use App\Domain\Shared\ValueObjects\Polyline;
use Carbon\CarbonImmutable;
use JsonSerializable;

/**
 * Estimasi harga yang sudah dibekukan, disimpan di Redis dengan TTL.
 *
 * ============================================================================
 *  INI PENJAGA UTAMA TERHADAP MANIPULASI HARGA
 * ============================================================================
 *  Aplikasi menerima `quote_id` dan menyimpannya. Saat membuat order, yang
 *  dikirim ke backend adalah quote_id itu, BUKAN harganya. Backend membaca
 *  harga dari Redis.
 *
 *  Blueprint menempatkan "harga dikirim dari client" sebagai kesalahan nomor
 *  enam yang paling sering terjadi, dengan catatan: "Ada yang akan menemukannya
 *  dalam sebulan."
 *
 *  Konsekuensi praktis yang perlu dipegang: quote yang hilang atau kadaluarsa
 *  TIDAK boleh diperlakukan sebagai "hitung ulang saja". Order harus ditolak
 *  dengan 409 dan aplikasi meminta quote baru. Menghitung ulang di sisi
 *  pembuatan order berarti harga bisa berubah antara yang dilihat penumpang dan
 *  yang ditagih, dan itu keluhan yang benar-benar sah.
 * ============================================================================
 */
final readonly class Quote implements JsonSerializable
{
    /**
     * @param  array<string, QuoteOption>  $options  diindeks kode layanan
     * @param  array<int, array<string, mixed>>  $eligiblePromos
     */
    public function __construct(
        public string $id,
        public int $userId,
        public Coordinate $pickup,
        public ?Coordinate $destination,
        public ?int $zoneId,
        public ?string $zoneName,
        public int $distanceMeters,
        public int $durationSeconds,
        public Polyline $routePolyline,
        public array $options,
        public array $eligiblePromos,
        public CarbonImmutable $createdAt,
        public CarbonImmutable $expiresAt,
        /** Titik perhentian tambahan untuk layanan multi-drop. */
        public array $stops = [],
    ) {}

    public function option(string $serviceCode): ?QuoteOption
    {
        return $this->options[$serviceCode] ?? null;
    }

    public function hasOption(string $serviceCode): bool
    {
        return isset($this->options[$serviceCode]);
    }

    public function isExpired(?\DateTimeInterface $at = null): bool
    {
        return $this->expiresAt->isBefore($at ?? now());
    }

    public function secondsUntilExpiry(?\DateTimeInterface $at = null): int
    {
        $now = CarbonImmutable::instance(
            \DateTimeImmutable::createFromInterface($at ?? now())
        );

        // Carbon 3 mengembalikan float dari diffInSeconds(), bukan int seperti
        // Carbon 2. Dibulatkan ke bawah supaya TTL yang dikirim ke Redis tidak
        // pernah lebih panjang dari masa berlaku quote yang sebenarnya.
        return max(0, (int) floor($now->diffInSeconds($this->expiresAt, absolute: false)));
    }

    /**
     * Diskon promo untuk satu kombinasi promo dan layanan.
     *
     * Dibaca dari quote, bukan dihitung ulang dan bukan dari client. Aplikasi
     * mengirim kode promo yang dipilih; nominalnya diambil dari sini.
     */
    public function promoDiscountFor(string $promoCode, string $serviceCode): ?int
    {
        foreach ($this->eligiblePromos as $promo) {
            if (strcasecmp((string) $promo['code'], $promoCode) !== 0) {
                continue;
            }

            return $promo['discounts'][$serviceCode] ?? null;
        }

        return null;
    }

    /**
     * Bentuk yang dikirim ke aplikasi.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'quote_id' => $this->id,
            'expires_at' => $this->expiresAt->toIso8601String(),
            'expires_in_seconds' => $this->secondsUntilExpiry(),
            'zone' => $this->zoneName,
            'route' => [
                'distance_m' => $this->distanceMeters,
                'distance_km' => round($this->distanceMeters / 1000, 2),
                'duration_s' => $this->durationSeconds,
                'duration_minutes' => (int) ceil($this->durationSeconds / 60),
                'polyline' => $this->routePolyline->encode(),
            ],
            // Diurutkan supaya aplikasi tidak perlu mengurutkan sendiri, dan
            // supaya urutannya konsisten di tiga aplikasi Flutter.
            'services' => array_values(array_map(
                static fn (QuoteOption $option) => $option->jsonSerialize(),
                $this->options,
            )),
            'promos' => array_map(
                static fn (array $promo) => [
                    'code' => $promo['code'],
                    'title' => $promo['title'],
                    'discounts' => $promo['discounts'],
                ],
                $this->eligiblePromos,
            ),
        ];
    }

    /**
     * Bentuk untuk disimpan di Redis.
     *
     * @return array<string, mixed>
     */
    public function toStorage(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->userId,
            'pickup' => ['lat' => $this->pickup->lat, 'lng' => $this->pickup->lng],
            'destination' => $this->destination === null ? null : [
                'lat' => $this->destination->lat,
                'lng' => $this->destination->lng,
            ],
            'stops' => $this->stops,
            'zone_id' => $this->zoneId,
            'zone_name' => $this->zoneName,
            'distance_m' => $this->distanceMeters,
            'duration_s' => $this->durationSeconds,
            'route_polyline' => $this->routePolyline->encode(),
            'options' => array_map(
                static fn (QuoteOption $option) => $option->toStorage(),
                $this->options,
            ),
            'eligible_promos' => $this->eligiblePromos,
            'created_at' => $this->createdAt->toIso8601String(),
            'expires_at' => $this->expiresAt->toIso8601String(),
        ];
    }
}
