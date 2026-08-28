<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Shared\Concerns\HasUuid;
use App\Domain\Shared\ValueObjects\Coordinate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Zona operasional.
 *
 * Polygon disimpan dua kali dengan peran berbeda:
 *
 *   polygon_geojson  JSONB, SUMBER KEBENARAN. Ini yang ditulis panel admin
 *                    saat ops menggambar zona di peta.
 *   polygon          geometry PostGIS, TURUNAN. Diisi trigger database dari
 *                    polygon_geojson, bukan oleh kode PHP.
 *
 * Kolom `polygon` sengaja TIDAK ada di $fillable dan tidak pernah ditulis dari
 * sini. Kalau kode aplikasi yang mengisinya, akan ada jalur penulisan yang lupa
 * dan geometry-nya jadi tidak sinkron dengan GeoJSON-nya. Trigger tidak bisa
 * lupa.
 */
class Zone extends Model
{
    use HasFactory;
    use HasUuid;

    protected $fillable = [
        'name',
        'code',
        'city',
        'province',
        'polygon_geojson',
        'min_lat',
        'max_lat',
        'min_lng',
        'max_lng',
        'center_lat',
        'center_lng',
        'is_active',
        'priority',
    ];

    /**
     * Kolom geometry tidak pernah dipilih secara default.
     *
     * Isinya bisa puluhan kilobyte per baris untuk polygon yang detail, dan
     * hampir tidak ada halaman yang membutuhkannya. Query yang memang perlu
     * memilihnya secara eksplisit.
     */
    protected $hidden = ['polygon'];

    protected function casts(): array
    {
        return [
            'polygon_geojson' => 'array',
            'min_lat' => 'float',
            'max_lat' => 'float',
            'min_lng' => 'float',
            'max_lng' => 'float',
            'center_lat' => 'float',
            'center_lng' => 'float',
            'is_active' => 'boolean',
            'priority' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    // -------------------------------------------------------------------------
    // Relasi
    // -------------------------------------------------------------------------

    public function pricingRules(): HasMany
    {
        return $this->hasMany(PricingRule::class);
    }

    public function surgeRules(): HasMany
    {
        return $this->hasMany(SurgeRule::class);
    }

    // -------------------------------------------------------------------------
    // Scope
    // -------------------------------------------------------------------------

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Penyaringan bounding box, murah dan tanpa PostGIS.
     *
     * Dipakai sebagai langkah pertama oleh resolver native: satu perbandingan
     * angka membuang mayoritas zona sebelum uji point-in-polygon yang jauh
     * lebih mahal dijalankan.
     */
    public function scopeContainingBoundingBox(Builder $query, Coordinate $point): Builder
    {
        return $query
            ->where('min_lat', '<=', $point->lat)
            ->where('max_lat', '>=', $point->lat)
            ->where('min_lng', '<=', $point->lng)
            ->where('max_lng', '>=', $point->lng);
    }

    // -------------------------------------------------------------------------
    // Perilaku
    // -------------------------------------------------------------------------

    public function center(): Coordinate
    {
        return Coordinate::of($this->center_lat, $this->center_lng);
    }

    /**
     * Cincin terluar polygon sebagai daftar koordinat.
     *
     * Hanya cincin pertama yang dibaca. Zona operasional tidak punya lubang di
     * tengahnya, dan kalau suatu hari punya, ini yang harus diubah lebih dulu
     * sebelum resolver native bisa dipercaya.
     *
     * @return array<int, Coordinate>
     */
    public function outerRing(): array
    {
        $coordinates = $this->polygon_geojson['coordinates'][0] ?? [];

        return array_map(
            static fn (array $pair) => Coordinate::fromGeoJsonPair($pair),
            $coordinates,
        );
    }

    /**
     * Uji point-in-polygon tanpa PostGIS, padanan dari `ST_Covers`.
     *
     * Dua tahap:
     *
     *   1. Titik yang berada PERSIS DI BATAS dinyatakan di dalam.
     *   2. Sisanya diuji dengan ray-casting: tarik garis dari titik ke arah tak
     *      hingga, hitung berapa kali dia memotong sisi polygon. Jumlah ganjil
     *      berarti di dalam.
     *
     * Tahap pertama tidak bisa dihilangkan. Ray-casting pada titik yang tepat
     * di batas hasilnya tidak stabil: bergantung sisi mana yang lebih dulu
     * diuji, titik yang sama bisa dinyatakan di dalam atau di luar. Tanpa
     * pemeriksaan eksplisit, resolver ini akan sesekali berbeda dari PostGIS
     * pada titik-titik perbatasan, dan gejalanya adalah alamat yang kadang
     * dilayani kadang tidak.
     *
     * Padanannya ST_Covers, bukan ST_Contains, karena titik jemput di garis
     * batas zona harus dilayani. Alasan lengkapnya ada di PostGisZoneResolver.
     *
     * Ada di model, bukan di resolver, supaya bisa dipanggil juga oleh panel
     * admin yang memvalidasi polygon baru sebelum menyimpannya.
     */
    public function containsPoint(Coordinate $point): bool
    {
        // Penyaringan bounding box dulu. Untuk titik yang jauh, ini menjawab
        // dengan empat perbandingan alih-alih menelusuri seluruh sisi.
        if ($point->lat < $this->min_lat || $point->lat > $this->max_lat) {
            return false;
        }

        if ($point->lng < $this->min_lng || $point->lng > $this->max_lng) {
            return false;
        }

        $ring = $this->outerRing();
        $count = count($ring);

        if ($count < 4) {
            return false;
        }

        if ($this->pointLiesOnBoundary($point, $ring)) {
            return true;
        }

        $inside = false;

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $lngI = $ring[$i]->lng;
            $latI = $ring[$i]->lat;
            $lngJ = $ring[$j]->lng;
            $latJ = $ring[$j]->lat;

            // Sisi ini memotong garis horizontal pada lat titik, dan
            // perpotongannya berada di sebelah kanan titik.
            $crosses = (($latI > $point->lat) !== ($latJ > $point->lat))
                && ($point->lng < ($lngJ - $lngI) * ($point->lat - $latI) / ($latJ - $latI) + $lngI);

            if ($crosses) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    /**
     * Apakah titik berada pada salah satu sisi polygon, termasuk di sudutnya.
     *
     * Toleransi 1e-9 derajat, sekitar 0,1 mm. Jauh lebih halus dari presisi
     * koordinat yang dipakai (7 desimal, sekitar 1 cm), jadi tidak akan
     * menyatakan titik di dalam hanya karena dekat batas; yang ditangani hanya
     * kesalahan pembulatan floating point.
     *
     * @param  array<int, Coordinate>  $ring
     */
    private function pointLiesOnBoundary(Coordinate $point, array $ring): bool
    {
        $epsilon = 1e-9;
        $count = count($ring);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $a = $ring[$j];
            $b = $ring[$i];

            // Titik harus berada di dalam kotak pembatas segmen, kalau tidak
            // dia berada di perpanjangan garisnya, bukan di segmennya.
            $withinSegmentBox =
                $point->lng >= min($a->lng, $b->lng) - $epsilon
                && $point->lng <= max($a->lng, $b->lng) + $epsilon
                && $point->lat >= min($a->lat, $b->lat) - $epsilon
                && $point->lat <= max($a->lat, $b->lat) + $epsilon;

            if (! $withinSegmentBox) {
                continue;
            }

            // Cross product nol berarti tiga titik kolinear.
            $cross = (($b->lat - $a->lat) * ($point->lng - $a->lng))
                - (($b->lng - $a->lng) * ($point->lat - $a->lat));

            if (abs($cross) <= $epsilon) {
                return true;
            }
        }

        return false;
    }
}
