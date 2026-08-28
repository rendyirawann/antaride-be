<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Contracts;

use App\Domain\Catalog\Models\Zone;
use App\Domain\Shared\ValueObjects\Coordinate;

/**
 * Memetakan sebuah titik ke zona operasional.
 *
 * Ini pemanggilan pertama di setiap alur quote, dan hasilnya menentukan tarif,
 * surge, dan apakah titik itu dilayani sama sekali. Jadi harus benar dan harus
 * murah.
 *
 * Dua implementasi, dan keduanya WAJIB memberi jawaban yang sama untuk titik
 * yang sama:
 *
 *   PostGisZoneResolver  ST_Contains dengan index GiST. Dipakai produksi.
 *   NativeZoneResolver   ray-casting di PHP, polygon di-cache. Dipakai kalau
 *                        PostGIS tidak terpasang, dan di unit test yang tidak
 *                        boleh menyentuh database.
 *
 * Kesamaan keduanya dijaga test kontrak yang menjalankan kedua implementasi
 * pada polygon zona yang sama:
 * Tests\Feature\Catalog\ZoneResolverContractTest
 */
interface ZoneResolver
{
    /**
     * Zona yang memuat titik ini.
     *
     * Mengembalikan null kalau titik berada di luar seluruh zona aktif, yang
     * berarti alamat itu tidak dilayani.
     *
     * Kalau titik masuk beberapa zona yang bertumpang tindih, yang
     * dikembalikan adalah zona dengan `priority` tertinggi. Ini yang membuat
     * zona bandara di dalam zona kota bisa punya tarif sendiri.
     */
    public function resolve(Coordinate $point): ?Zone;

    /**
     * Semua zona yang memuat titik ini, terurut prioritas menurun.
     *
     * Dipakai panel admin untuk menjelaskan kenapa sebuah titik mendapat tarif
     * tertentu, saat ada sengketa ongkos.
     *
     * @return array<int, Zone>
     */
    public function resolveAll(Coordinate $point): array;

    /**
     * Apakah titik ini dilayani oleh zona aktif mana pun.
     *
     * Lebih murah dari resolve() pada implementasi PostGIS karena tidak perlu
     * memuat model.
     */
    public function isServiceable(Coordinate $point): bool;

    /**
     * Kosongkan cache polygon.
     *
     * Wajib dipanggil setiap kali zona diubah lewat panel admin, kalau tidak
     * resolver native akan memakai polygon lama sampai TTL-nya habis, dan
     * ongkos dihitung dengan zona yang sudah tidak berlaku.
     */
    public function flushCache(): void;
}
