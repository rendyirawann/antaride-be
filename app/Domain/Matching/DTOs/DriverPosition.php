<?php

declare(strict_types=1);

namespace App\Domain\Matching\DTOs;

use App\Domain\Shared\ValueObjects\Coordinate;

/**
 * Posisi driver yang dibaca dari Redis.
 *
 * Bukan model Eloquent, dan itu disengaja. Data ini tidak pernah masuk
 * PostgreSQL: seribu driver yang ping tiap 4 detik menghasilkan 250 write per
 * detik, dan dalam sehari itu 21 juta baris yang tidak berguna karena posisi
 * sepuluh menit lalu sudah tidak ada artinya.
 *
 * Yang disimpan permanen hanya polyline terkompresi saat trip selesai, satu
 * baris per order.
 */
final readonly class DriverPosition
{
    public function __construct(
        public int $driverId,
        public Coordinate $coordinate,
        public ?float $heading,
        public ?float $speedKmh,
        public ?float $accuracyM,
        public ?int $timestamp,
        public ?int $batteryPercent,
        public bool $lowQuality,
        /** Jarak ke titik pencarian, dalam meter. Hanya terisi hasil GEORADIUS. */
        public ?float $distanceM = null,
    ) {}

    /**
     * @param  array<string, string|null>  $meta  isi HASH drv:meta:{id}
     */
    public static function fromRedisHash(
        int $driverId,
        array $meta,
        ?float $distanceM = null,
    ): ?self {
        if (! isset($meta['lat'], $meta['lng'])) {
            return null;
        }

        $accuracy = isset($meta['acc']) ? (float) $meta['acc'] : null;
        $maxAccuracy = (float) config('antaride.gps.max_accuracy_m', 100);

        return new self(
            driverId: $driverId,
            coordinate: Coordinate::of((float) $meta['lat'], (float) $meta['lng']),
            heading: isset($meta['heading']) ? (float) $meta['heading'] : null,
            speedKmh: isset($meta['speed']) ? (float) $meta['speed'] : null,
            accuracyM: $accuracy,
            timestamp: isset($meta['ts']) ? (int) $meta['ts'] : null,
            batteryPercent: isset($meta['battery']) ? (int) $meta['battery'] : null,
            // Akurasi buruk tetap disimpan, tapi ditandai. Posisi seperti ini
            // tidak layak dipakai mengkonfirmasi geofence kedatangan, tapi masih
            // cukup untuk menampilkan driver bergerak di peta.
            lowQuality: $accuracy !== null && $accuracy > $maxAccuracy,
            distanceM: $distanceM,
        );
    }

    /**
     * Umur posisi ini dalam detik.
     */
    public function ageSeconds(): ?int
    {
        if ($this->timestamp === null) {
            return null;
        }

        return max(0, now()->getTimestamp() - $this->timestamp);
    }

    /**
     * Apakah posisi ini sudah terlalu tua untuk dipercaya.
     *
     * Driver yang ping terakhirnya lebih lama dari ambang dianggap tidak hadir,
     * walaupun namanya masih ada di set driver tersedia. Ini menutup kasus app
     * yang mati mendadak tanpa mengirim offline: key GEO-nya masih ada sampai
     * TTL habis, dan tanpa pemeriksaan ini order akan ditawarkan ke driver yang
     * ponselnya sudah mati.
     */
    public function isStale(): bool
    {
        $age = $this->ageSeconds();

        if ($age === null) {
            return true;
        }

        return $age > (int) config('antaride.matching.stale_ping_seconds', 30);
    }

    /**
     * Layak dipakai untuk konfirmasi geofence.
     */
    public function isReliableForGeofence(): bool
    {
        return ! $this->lowQuality && ! $this->isStale();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'driver_id' => $this->driverId,
            'lat' => $this->coordinate->lat,
            'lng' => $this->coordinate->lng,
            'heading' => $this->heading,
            'speed_kmh' => $this->speedKmh,
            'accuracy_m' => $this->accuracyM,
            'age_seconds' => $this->ageSeconds(),
            'low_quality' => $this->lowQuality,
            'distance_m' => $this->distanceM,
        ];
    }
}
