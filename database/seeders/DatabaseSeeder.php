<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Urutannya penting dan bukan sembarang.
 *
 *   PermissionSeeder  harus lebih dulu, karena AdminSeeder memberi role.
 *   SystemSeeder      harus sebelum data transaksi apa pun, karena wallet
 *                     platform adalah lawan transaksi yang dibutuhkan penjaga
 *                     double-entry di database.
 *   CatalogSeeder     harus sebelum order, karena order butuh layanan dan tarif.
 *
 * Seeder demo data sengaja TIDAK dijalankan otomatis. Jalankan terpisah:
 *
 *   php artisan db:seed --class=DemoDataSeeder
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->newLine();
        $this->command->line('  Menyiapkan data dasar Antaride...');
        $this->command->newLine();

        $this->call([
            PermissionSeeder::class,
            AdminSeeder::class,
            SystemSeeder::class,
            CatalogSeeder::class,
        ]);

        $this->command->newLine();
        $this->command->info('  Selesai. Jalankan `php artisan antaride:health` untuk memeriksa environment.');
        $this->command->newLine();
    }
}
