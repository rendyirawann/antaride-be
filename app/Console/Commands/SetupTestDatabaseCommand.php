<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Menyiapkan database untuk test suite.
 *
 * Test jalan di PostgreSQL, bukan SQLite, karena skema ini bergantung pada
 * fitur yang tidak ada di SQLite. Yang paling penting: test yang membuktikan
 * "driver tidak bisa memegang dua order" akan LULUS di SQLite justru karena
 * constraint-nya tidak ada di sana. Test yang lulus karena penjaganya hilang
 * lebih berbahaya daripada tidak ada test sama sekali.
 *
 * Perintah ini dijalankan sekali. `php artisan test` sesudahnya bekerja seperti
 * biasa.
 */
class SetupTestDatabaseCommand extends Command
{
    protected $signature = 'antaride:setup-test-db {--fresh : Hapus dan buat ulang}';

    protected $description = 'Buat database test dan jalankan migrasi di atasnya';

    public function handle(): int
    {
        // Dibaca dari config, BUKAN env().
        //
        // env() mengembalikan null begitu config di-cache dengan
        // `php artisan config:cache`, karena file .env tidak dibaca lagi.
        // Konsekuensinya di sini: nama database menjadi null, CREATE DATABASE
        // gagal dengan pesan sintaks yang tidak menjelaskan apa pun, dan
        // penyebabnya adalah perintah cache yang dijalankan setengah jam
        // sebelumnya.
        $database = (string) config('database.connections.pgsql_testing.database', 'antaride_testing');

        // Terhubung ke database 'postgres' karena CREATE DATABASE tidak bisa
        // dijalankan dari dalam database yang sedang dipakai.
        config([
            'database.connections.pgsql_admin' => array_merge(
                config('database.connections.pgsql_testing'),
                ['database' => 'postgres'],
            ),
        ]);

        $exists = DB::connection('pgsql_admin')
            ->selectOne('SELECT 1 AS ok FROM pg_database WHERE datname = ?', [$database]) !== null;

        if ($exists && $this->option('fresh')) {
            $this->warn("  Menghapus database {$database} ...");

            // Putus semua koneksi lain dulu, kalau tidak DROP akan ditolak.
            DB::connection('pgsql_admin')->statement(
                'SELECT pg_terminate_backend(pid) FROM pg_stat_activity
                 WHERE datname = ? AND pid <> pg_backend_pid()',
                [$database],
            );

            DB::connection('pgsql_admin')->statement("DROP DATABASE \"{$database}\"");
            $exists = false;
        }

        if (! $exists) {
            $this->info("  Membuat database {$database} ...");
            DB::connection('pgsql_admin')->statement(
                "CREATE DATABASE \"{$database}\" ENCODING 'UTF8' TEMPLATE template0"
            );
        } else {
            $this->line("  Database {$database} sudah ada.");
        }

        $this->info('  Menjalankan migrasi di database test ...');

        $this->call('migrate:fresh', [
            '--database' => 'pgsql_testing',
            '--force' => true,
        ]);

        $this->newLine();
        $this->info('  Siap. Jalankan: php artisan test');

        return self::SUCCESS;
    }
}
