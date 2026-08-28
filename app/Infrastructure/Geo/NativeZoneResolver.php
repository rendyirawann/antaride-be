<?php

declare(strict_types=1);

namespace App\Infrastructure\Geo;

use App\Domain\Catalog\Contracts\ZoneResolver;
use App\Domain\Catalog\Models\Zone;
use App\Domain\Shared\ValueObjects\Coordinate;
use Illuminate\Support\Facades\Cache;

/**
 * Resolusi zona tanpa PostGIS, dengan ray-casting di PHP.
 *
 * Ada untuk dua hal:
 *
 *   1. Pengembangan tidak terhalang saat ekstensi PostGIS belum terpasang.
 *   2. Unit test bisa menguji logika zona tanpa menyentuh database.
 *
 * Cocok karena jumlah zona operasional puluhan, bukan ribuan. Seluruh polygon
 * aktif dimuat sekali lalu di-cache, dan penyaringan bounding box membuang
 * mayoritas kandidat dengan empat perbandingan angka sebelum menelusuri sisi
 * polygon.
 *
 * BUKAN pengganti PostGIS untuk analitik. Begitu ada kebutuhan query spasial di
 * SQL (heatmap per zona, order yang jatuh di luar semua zona, irisan antar
 * zona), yang dipakai harus PostGisZoneResolver.
 */
class NativeZoneResolver implements ZoneResolver
{
    private const CACHE_KEY = 'geo:zones:active';

    public function resolve(Coordinate $point): ?Zone
    {
        return $this->resolveAll($point)[0] ?? null;
    }

    /**
     * @return array<int, Zone>
     */
    public function resolveAll(Coordinate $point): array
    {
        $matches = [];

        foreach ($this->activeZones() as $zone) {
            if ($zone->containsPoint($point)) {
                $matches[] = $zone;
            }
        }

        // Urutan harus sama dengan PostGisZoneResolver: prioritas menurun,
        // lalu id menaik. Kalau berbeda, dua resolver akan memilih zona yang
        // berbeda untuk titik yang masuk dua zona sekaligus, dan ongkosnya ikut
        // berbeda.
        usort($matches, static function (Zone $a, Zone $b): int {
            return [$b->priority, $a->id] <=> [$a->priority, $b->id];
        });

        return $matches;
    }

    public function isServiceable(Coordinate $point): bool
    {
        foreach ($this->activeZones() as $zone) {
            if ($zone->containsPoint($point)) {
                return true;
            }
        }

        return false;
    }

    public function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Polygon zona aktif, di-cache.
     *
     * TTL boleh panjang karena zona jarang berubah, dan setiap perubahan lewat
     * panel admin memanggil flushCache(). Yang berbahaya bukan TTL panjang,
     * tapi TTL panjang tanpa invalidasi.
     *
     * @return array<int, Zone>
     */
    private function activeZones(): array
    {
        $ttl = (int) config('antaride.geo.zone_cache_seconds', 900);

        /** @var array<int, Zone> */
        return Cache::remember(
            self::CACHE_KEY,
            now()->addSeconds($ttl),
            static fn () => Zone::query()
                ->active()
                ->orderByDesc('priority')
                ->orderBy('id')
                ->get()
                ->all(),
        );
    }
}
