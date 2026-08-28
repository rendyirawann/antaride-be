<?php

declare(strict_types=1);

namespace App\Infrastructure\Geo;

use App\Domain\Catalog\Contracts\ZoneResolver;
use App\Domain\Catalog\Models\Zone;
use App\Domain\Shared\ValueObjects\Coordinate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Resolusi zona dengan PostGIS.
 *
 * Memakai kolom geometry berindeks GiST. `EXPLAIN` mengonfirmasi query di bawah
 * memakai `Index Scan using zones_polygon_gist`, bukan sequential scan.
 *
 * ============================================================================
 *  ST_Covers, BUKAN ST_Contains.
 * ============================================================================
 *  Menurut definisi OGC, `ST_Contains` bernilai FALSE untuk titik yang berada
 *  persis di batas polygon. `ST_Covers` memasukkan batasnya.
 *
 *  Ini bukan soal ketelitian matematis, tapi keputusan bisnis. Zona digambar
 *  mengikuti jalan, dan alamat hasil geocoding menempel ke sumbu jalan, jadi
 *  titik yang jatuh persis di garis batas PASTI terjadi. Dengan ST_Contains,
 *  alamat seperti itu ditolak dengan alasan "di luar area layanan" padahal
 *  dikelilingi area yang dilayani, dan lebih buruk lagi: titik di perbatasan
 *  dua zona akan ditolak oleh KEDUANYA.
 *
 *  NativeZoneResolver menegakkan perilaku yang sama lewat pemeriksaan
 *  titik-pada-sisi yang eksplisit. Kesamaan keduanya dijaga
 *  ZoneResolverContractTest, yang justru menemukan perbedaan ini.
 * ============================================================================
 *
 * Tidak ada cache di sini, sengaja. Query-nya sudah satu index scan pada tabel
 * yang isinya puluhan baris; menambah lapisan cache hanya menambah tempat data
 * bisa jadi tidak sinkron setelah ops mengubah polygon zona.
 */
class PostGisZoneResolver implements ZoneResolver
{
    public function resolve(Coordinate $point): ?Zone
    {
        return $this->query($point)->first();
    }

    /**
     * @return array<int, Zone>
     */
    public function resolveAll(Coordinate $point): array
    {
        return $this->query($point)->get()->all();
    }

    public function isServiceable(Coordinate $point): bool
    {
        // Tidak memuat model, hanya menanyakan keberadaan. Untuk endpoint
        // autocomplete alamat yang dipanggil setiap ketikan, bedanya nyata.
        return DB::table('zones')
            ->where('is_active', true)
            ->whereRaw(
                'ST_Covers(polygon, ST_SetSRID(ST_MakePoint(?, ?), 4326))',
                [$point->lng, $point->lat],
            )
            ->exists();
    }

    public function flushCache(): void
    {
        // Tidak ada cache untuk dikosongkan. Method ini tetap ada supaya
        // pemanggil tidak perlu tahu implementasi mana yang sedang aktif.
    }

    /**
     * @return Builder<Zone>
     */
    private function query(Coordinate $point)
    {
        return Zone::query()
            ->active()
            // Binding parameter, bukan interpolasi string. Koordinat sudah
            // divalidasi jadi float oleh value object, tapi kebiasaan
            // menyisipkan nilai ke SQL adalah kebiasaan yang tidak dibangun.
            ->whereRaw(
                'ST_Covers(polygon, ST_SetSRID(ST_MakePoint(?, ?), 4326))',
                [$point->lng, $point->lat],
            )
            // Zona yang lebih spesifik menang saat bertumpang tindih. Urutan
            // kedua pakai id supaya hasilnya deterministik kalau prioritasnya
            // sama; tanpa itu, dua zona berprioritas sama akan menghasilkan
            // tarif yang berbeda-beda antar request untuk titik yang sama.
            ->orderByDesc('priority')
            ->orderBy('id');
    }
}
