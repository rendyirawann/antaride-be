<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Koneksi Queue Default
    |--------------------------------------------------------------------------
    */

    'default' => env('QUEUE_CONNECTION', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Koneksi Queue
    |--------------------------------------------------------------------------
    |
    | Tiga antrean dengan prioritas berbeda (blueprint admin bagian 13):
    |
    |   critical   matching, notifikasi order, webhook payment
    |   default    settlement, rating, update statistik
    |   reports    export, agregasi metrik, rekonsiliasi
    |
    | Pemisahan ini bukan kerapian, tapi kebutuhan. Satu export 2 juta baris
    | yang berbagi antrean dengan notifikasi matching akan menahan semuanya,
    | dan yang dirasakan penumpang adalah driver tidak pernah muncul.
    |
    | retry_after untuk 'critical' dibuat pendek karena job matching memang
    | berumur belasan detik. Untuk 'reports' dibuat panjang karena export besar
    | wajar berjalan lama dan tidak boleh dianggap gagal lalu diulang.
    |
    */

    'connections' => [

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'queue'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 90),
            'block_for' => null,
            'after_commit' => true,
        ],

        'redis_critical' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'queue'),
            'queue' => 'critical',
            'retry_after' => 60,
            'block_for' => null,
            'after_commit' => true,
        ],

        'redis_reports' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'queue'),
            'queue' => 'reports',
            'retry_after' => 3600,
            'block_for' => null,
            'after_commit' => true,
        ],

        'sync' => [
            'driver' => 'sync',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Batching
    |--------------------------------------------------------------------------
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Gagal
    |--------------------------------------------------------------------------
    |
    | Disimpan ke Postgres, bukan Redis. Job yang gagal adalah bukti dan harus
    | selamat dari restart Redis. Beberapa di antaranya menyangkut uang.
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table' => 'failed_jobs',
    ],

];
