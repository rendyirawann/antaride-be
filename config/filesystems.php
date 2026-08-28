<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Disk Default
    |--------------------------------------------------------------------------
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Disk
    |--------------------------------------------------------------------------
    */

    'disks' => [

        /*
         * Disk private bawaan Laravel, TIDAK di-serve.
         *
         * Bawaannya `serve => true`, yang mendaftarkan route `/storage/{path}`.
         * Dua alasan itu dimatikan di sini:
         *
         *   1. Jalur `/storage` sudah dipakai symlink disk `public`. Dua hal
         *      yang melayani prefix yang sama berarti mana yang menang
         *      bergantung pada konfigurasi web server, dan itu bukan sesuatu
         *      yang boleh menentukan apakah file private bisa diakses.
         *
         *   2. Tidak ada satu pun kode di aplikasi ini yang menerbitkan URL
         *      dari disk `local`. File private yang memang perlu dilihat ada di
         *      disk `kyc` dan `exports`, masing-masing dengan jalurnya sendiri.
         *
         * Route yang tidak dipakai tapi tetap terdaftar adalah permukaan
         * serangan gratis.
         */
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        /*
        |----------------------------------------------------------------------
        | KYC — dokumen identitas driver
        |----------------------------------------------------------------------
        |
        | Visibility private. KTP, SIM, STNK, SKCK, dan foto selfie hanya boleh
        | diakses lewat signed URL berumur pendek yang diterbitkan setelah
        | pemeriksaan permission, dan setiap penerbitannya masuk audit log.
        |
        | Jalurnya sendiri (`/dokumen-kyc`), terpisah dari `/storage`, supaya
        | tidak pernah bertabrakan dengan symlink disk publik dan supaya log web
        | server memperlihatkan akses dokumen KYC sebagai baris tersendiri.
        |
        | Menaruh ini di disk 'public' adalah kebocoran data pribadi yang
        | menunggu terjadi: siapa pun yang menebak nama file bisa mengunduh KTP.
        |
        */
        'kyc' => [
            'driver' => 'local',
            'root' => storage_path('app/private/kyc'),
            'visibility' => 'private',

            /*
             * `serve` HARUS true, dan itu tidak berarti file ini dilayani
             * bebas.
             *
             * Yang dilakukan `serve => true` adalah mendaftarkan route
             * BERTANDA TANGAN bernama `storage.kyc`. Tanpa dia,
             * LocalFilesystemAdapter tidak punya cara membuat URL sementara,
             * dan `Storage::disk('kyc')->temporaryUrl(...)` SELALU melempar
             * RuntimeException — artinya DriverDocument::temporaryUrl() tidak
             * pernah bisa dipakai sama sekali.
             *
             * Bentuknya di panel admin: setiap kali staf verifikasi membuka
             * dokumen driver, halamannya 500. Tim verifikasi tidak bisa
             * bekerja, dan tidak ada driver baru yang bisa diloloskan.
             *
             * Yang menjaga kerahasiaannya tetap dua hal, dan keduanya tidak
             * berubah: `visibility => private` (file tidak ada di public/),
             * dan tanda tangan berumur lima menit pada URL-nya. Tanpa tanda
             * tangan yang sah, route itu menolak.
             */
            'serve' => true,
            'url' => env('APP_URL', 'http://127.0.0.1:8000').'/dokumen-kyc',

            'throw' => true,
            'report' => false,
        ],

        /*
        |----------------------------------------------------------------------
        | Public — aset yang memang boleh diakses siapa saja
        |----------------------------------------------------------------------
        |
        | Foto menu merchant, banner promo, avatar. Bukan tempat dokumen KYC.
        |
        */
        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
        |----------------------------------------------------------------------
        | S3 / Cloudflare R2
        |----------------------------------------------------------------------
        |
        | Dipakai di produksi untuk foto KYC, bukti pengantaran, dan file export.
        | R2 memakai endpoint kustom, karena itu use_path_style_endpoint true.
        |
        */
        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'auto'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', true),
            'throw' => true,
            'report' => false,
        ],

        /*
        |----------------------------------------------------------------------
        | Export — hasil laporan
        |----------------------------------------------------------------------
        |
        | File dihapus otomatis setelah masa retensi. Setiap unduhan dicatat,
        | karena kalau data driver bocor kamu perlu tahu siapa yang terakhir
        | mengunduhnya dan seberapa banyak.
        |
        */
        'exports' => [
            'driver' => 'local',
            'root' => storage_path('app/private/exports'),
            'visibility' => 'private',
            'serve' => false,
            'throw' => true,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Link
    |--------------------------------------------------------------------------
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
