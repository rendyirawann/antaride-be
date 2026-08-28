<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ekstensi PostgreSQL yang dipakai skema ini.
 *
 * Harus jalan paling awal, karena tabel-tabel setelahnya bergantung pada tipe
 * dan operator yang dibawa ekstensi ini.
 */
return new class extends Migration
{
    /**
     * Ekstensi yang selalu tersedia di instalasi PostgreSQL standar.
     *
     * @var array<string, string>
     */
    private const REQUIRED = [
        // Trigram index. Ini yang membuat pencarian ILIKE '%budi%' pada nama
        // dan nomor HP tetap memakai index. Tanpa dia, satu panggilan CS yang
        // mencari pelanggan akan memindai seluruh tabel users.
        //
        // Blueprint admin menyarankan Meilisearch atau membatasi pencarian ke
        // prefix 'budi%'. Dengan pg_trgm keduanya tidak diperlukan di Fase 1,
        // jadi ada satu service infrastruktur lebih sedikit untuk diurus.
        'pg_trgm' => 'pencarian ILIKE terindeks pada nama dan nomor HP',

        // Memungkinkan index GIN pada kolom bigint/timestamp digabung dengan
        // kolom JSONB dalam satu index komposit.
        'btree_gin' => 'index GIN gabungan untuk filter panel admin',

        // Memungkinkan operator kesetaraan pada integer dipakai di dalam index
        // GiST. Ini prasyarat exclusion constraint pada pricing_rules, yang
        // mencegah dua tarif aktif dengan periode bertumpang tindih untuk
        // pasangan (service_type, zone) yang sama.
        //
        // Tanpa itu, tarif ganda hanya tertahan oleh kedisiplinan kode. Yang
        // terjadi di lapangan: admin mengubah tarif, lupa mengakhiri yang lama,
        // dan mulai saat itu harga yang keluar bergantung pada urutan baris
        // yang dikembalikan query. Sengketa ongkos tiga bulan kemudian tidak
        // akan bisa dijelaskan.
        'btree_gist' => 'exclusion constraint anti tarif bertumpang tindih',

        // gen_random_uuid() dan digest(). Dipakai untuk hash kode OTP, karena
        // kode OTP tidak boleh disimpan dalam bentuk asli.
        'pgcrypto' => 'hash kode OTP dan pembangkit UUID di sisi database',

        // Email case-insensitive tanpa perlu LOWER() di setiap query, sehingga
        // unique constraint pada email benar-benar mencegah duplikat yang cuma
        // beda huruf besar-kecil.
        'citext' => 'email case-insensitive',
    ];

    public function up(): void
    {
        foreach (self::REQUIRED as $extension => $purpose) {
            DB::statement("CREATE EXTENSION IF NOT EXISTS {$extension}");
        }

        $this->enablePostGis();
    }

    /**
     * PostGIS dipisah karena di Windows dia paket instalasi tersendiri, bukan
     * bagian dari PostgreSQL.
     *
     * Kalau belum terpasang, migration TIDAK digagalkan. Skema zona tetap bisa
     * dibangun dengan kolom GeoJSON sebagai sumber kebenaran, dan resolusi
     * zona jatuh ke driver 'native' (ray-casting di PHP). Yang hilang hanya
     * kemampuan query spasial di SQL untuk analitik.
     *
     * Ini keputusan sengaja: satu ekstensi yang belum terpasang tidak boleh
     * menghalangi seluruh pembangunan skema.
     */
    private function enablePostGis(): void
    {
        $available = DB::selectOne(
            "SELECT 1 AS ok FROM pg_available_extensions WHERE name = 'postgis'"
        );

        if ($available === null) {
            $this->warn(
                'PostGIS tidak tersedia di instance PostgreSQL ini.',
                'Zona akan memakai kolom GeoJSON dan resolusi driver "native".',
                'Untuk mengaktifkan: pasang PostGIS Bundle 3.6.2 (versi pertama',
                'yang mendukung PostgreSQL 18) lewat StackBuilder pada bagian',
                'Spatial Extensions, lalu jalankan: php artisan geo:enable-postgis',
            );

            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
    }

    private function warn(string ...$lines): void
    {
        foreach ($lines as $line) {
            fwrite(STDERR, "  [PostGIS] {$line}\n");
        }
    }

    public function down(): void
    {
        // Ekstensi sengaja tidak di-drop.
        //
        // DROP EXTENSION akan menghapus semua index dan kolom yang
        // bergantung padanya, dan itu tidak pernah menjadi hal yang diinginkan
        // seseorang saat menjalankan rollback satu langkah. Ekstensi yang
        // menganggur tidak memakan biaya apa pun.
    }
};
