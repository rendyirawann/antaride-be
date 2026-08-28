<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Koneksi Default
    |--------------------------------------------------------------------------
    |
    | Proyek ini hanya mendukung PostgreSQL. Koneksi mysql/sqlsrv sengaja tidak
    | disediakan supaya tidak ada kode yang diam-diam ditulis dengan asumsi
    | dialek lain, lalu gagal saat memakai partial index, JSONB, atau PostGIS.
    |
    */

    'default' => env('DB_CONNECTION', 'pgsql'),

    'connections' => [

        /*
        |----------------------------------------------------------------------
        | PostgreSQL — sumber kebenaran
        |----------------------------------------------------------------------
        |
        | Pemisahan read/write dipakai oleh panel admin: seluruh query panel
        | diarahkan ke replica supaya satu staf yang salah memfilter tanpa
        | indeks tidak bisa memperlambat penerimaan order.
        |
        | 'sticky' => true penting. Setelah admin menulis sesuatu, pembacaan di
        | request yang sama dikembalikan ke master, supaya dia melihat
        | perubahannya sendiri dan bukan data replica yang tertinggal beberapa
        | milidetik.
        |
        | Di lokal DB_READ_HOST menunjuk ke host yang sama, jadi perilakunya
        | identik tanpa perlu replica sungguhan.
        |
        */
        'pgsql' => [
            'driver' => 'pgsql',

            'read' => [
                'host' => [env('DB_READ_HOST', env('DB_HOST', '127.0.0.1'))],
                'port' => env('DB_READ_PORT', env('DB_PORT', '5433')),
            ],
            'write' => [
                'host' => [env('DB_HOST', '127.0.0.1')],
                'port' => env('DB_PORT', '5433'),
            ],
            'sticky' => true,

            'database' => env('DB_DATABASE', 'antaride'),
            'username' => env('DB_USERNAME', 'postgres'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => env('DB_SCHEMA', 'public'),
            'sslmode' => env('DB_SSLMODE', 'prefer'),

            // Dipaksa UTC supaya timestamp tidak pernah bergantung pada setelan
            // server. Konversi ke Asia/Jakarta hanya terjadi di lapisan tampilan.
            'timezone' => 'UTC',
        ],

        /*
        |----------------------------------------------------------------------
        | PostgreSQL — test suite
        |----------------------------------------------------------------------
        |
        | Test TIDAK boleh jalan di SQLite. Skema ini bergantung pada partial
        | unique index, JSONB, GIN, partisi deklaratif, dan PostGIS, yang tidak
        | ada satu pun di SQLite. Test yang lulus di sana tidak membuktikan apa
        | pun tentang produksi.
        |
        */
        'pgsql_testing' => [
            'driver' => 'pgsql',
            'host' => env('DB_TEST_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('DB_TEST_PORT', env('DB_PORT', '5433')),
            'database' => env('DB_TEST_DATABASE', 'antaride_testing'),
            'username' => env('DB_USERNAME', 'postgres'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
            'timezone' => 'UTC',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Tabel Migration
    |--------------------------------------------------------------------------
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis
    |--------------------------------------------------------------------------
    |
    | Empat koneksi dengan peran yang dibedakan tegas:
    |
    |   default   dipakai Laravel sendiri, boleh berprefix
    |   cache     cache aplikasi
    |   queue     antrean job
    |   shared    TANPA PREFIX. Hanya untuk key yang dipakai bersama dengan
    |             location service Go: posisi driver (GEO), lock order, TTL
    |             penawaran, quote, dan rate limit ping.
    |
    | Koneksi 'shared' itu bukan kerumitan yang dibuat-buat. Laravel menempelkan
    | prefix "antaride-database-" ke setiap key secara default. Location service
    | Go menulis key mentah drv:loc:ride_bike. Kalau matching engine memakai
    | koneksi berprefix, dia akan membaca set kosong sementara Go menulis ke key
    | lain, dan tidak ada satu pun error yang muncul untuk menjelaskannya.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'predis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'antaride')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],

        'queue' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_QUEUE_DB', '2'),
        ],

        'shared' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'options' => [
                'prefix' => '',
            ],
        ],

    ],

];
