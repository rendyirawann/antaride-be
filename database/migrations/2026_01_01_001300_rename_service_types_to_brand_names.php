<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Nama layanan jadi nama merek: "Antar Motor" -> "Antaride",
 * "Antar Barang" -> "AntarExpress".
 *
 * ============================================================================
 *  KENAPA MIGRASI, BUKAN SEED ULANG
 * ============================================================================
 *  `CatalogSeeder` memakai `insertGetId`, bukan `updateOrCreate`. Menjalankannya
 *  lagi di server yang sudah hidup TIDAK memperbarui nama — dia membuat baris
 *  layanan KEDUA dengan kode yang sama, dan sejak itu setiap quote bisa
 *  menunjuk ke salah satu dari dua baris dengan tarif yang berbeda.
 *
 *  Karena itu perubahan nama untuk server yang sudah jalan harus lewat sini.
 *  Seeder juga diperbarui, tapi itu hanya untuk pemasangan baru.
 * ============================================================================
 *
 * ============================================================================
 *  DICOCOKKAN LEWAT `code`, BUKAN LEWAT NAMA LAMA
 * ============================================================================
 *  `code` adalah kunci yang dipakai aplikasi dan tarif; namanya hanya label
 *  yang dibaca manusia. Mencocokkan lewat nama lama berarti migrasi ini gagal
 *  diam-diam di database mana pun yang namanya sudah pernah disunting tangan
 *  lewat backoffice.
 * ============================================================================
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const NAMA_BARU = [
        'ride_bike' => 'Antaride',
        'send' => 'AntarExpress',
    ];

    /** @var array<string, string> */
    private const NAMA_LAMA = [
        'ride_bike' => 'Antar Motor',
        'send' => 'Antar Barang',
    ];

    public function up(): void
    {
        foreach (self::NAMA_BARU as $code => $nama) {
            DB::table('service_types')
                ->where('code', $code)
                ->update(['name' => $nama, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (self::NAMA_LAMA as $code => $nama) {
            DB::table('service_types')
                ->where('code', $code)
                ->update(['name' => $nama, 'updated_at' => now()]);
        }
    }
};
