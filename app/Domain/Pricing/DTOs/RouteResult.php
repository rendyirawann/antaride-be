<?php

declare(strict_types=1);

namespace App\Domain\Pricing\DTOs;

use App\Domain\Shared\ValueObjects\Polyline;

/**
 * Hasil perhitungan rute dari mesin routing.
 *
 * `distanceMeters` di sini adalah jarak TEMPUH mengikuti jalan, bukan jarak
 * garis lurus. Ini yang dipakai menghitung tarif. Jarak garis lurus dari
 * haversine hanya untuk penyaringan kandidat dan geofence.
 *
 * Bedanya bukan kecil: di kota dengan jalan satu arah dan sungai, jarak tempuh
 * bisa dua kali jarak garis lurus. Memakai haversine untuk tarif berarti
 * menagih setengah dari yang seharusnya.
 */
final readonly class RouteResult
{
    public function __construct(
        public int $distanceMeters,
        public int $durationSeconds,
        public Polyline $polyline,
        /** Apakah hasil ini dari fallback, bukan dari mesin routing sungguhan. */
        public bool $isEstimated = false,
    ) {}

    public function distanceKm(): float
    {
        return round($this->distanceMeters / 1000, 2);
    }

    public function durationMinutes(): int
    {
        return (int) ceil($this->durationSeconds / 60);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'distance_m' => $this->distanceMeters,
            'duration_s' => $this->durationSeconds,
            'distance_km' => $this->distanceKm(),
            'duration_minutes' => $this->durationMinutes(),
            'polyline' => $this->polyline->encode(),
            'is_estimated' => $this->isEstimated,
        ];
    }
}
