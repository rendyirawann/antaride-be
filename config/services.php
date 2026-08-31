<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OSRM — routing & jarak (self-host)
    |--------------------------------------------------------------------------
    |
    | Dipanggil untuk setiap quote, jadi ribuan kali per hari. Ini penghematan
    | terbesar dibanding Google Distance Matrix. Timeout dibuat pendek: kalau
    | OSRM lambat, lebih baik quote gagal cepat dan user mencoba lagi daripada
    | worker Octane tertahan.
    |
    */

    'osrm' => [
        'url' => env('OSRM_URL', 'http://127.0.0.1:5000'),
        'profile' => env('OSRM_PROFILE', 'driving'),
        'timeout' => (float) env('OSRM_TIMEOUT', 3),
        'connect_timeout' => (float) env('OSRM_CONNECT_TIMEOUT', 1),
        'retries' => (int) env('OSRM_RETRIES', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Nominatim — pencarian & reverse geocoding alamat (self-host)
    |--------------------------------------------------------------------------
    |
    | Pasangan alami OSRM: data OSM yang sama, mesin yang sama-sama gratis.
    |
    | `url` MENUNJUK KE MANA menentukan apakah ini boleh dipakai produksi.
    | Nominatim publik (nominatim.openstreetmap.org) membatasi 1 permintaan per
    | detik dan kebijakannya MELARANG pemakaian autocomplete — setiap ketikan
    | adalah satu permintaan, dan pemakaian seperti itu diblokir. Bawaannya
    | karena itu localhost: server yang belum memasang Nominatim gagal
    | terhubung dan fiturnya mati dengan jelas, bukan diam-diam melanggar
    | kebijakan orang lain.
    |
    | `email` diminta kebijakan penggunaan Nominatim supaya pemilik instans bisa
    | dihubungi kalau ada masalah. Wajib diisi kalau url-nya bukan milik sendiri.
    |
    */

    'nominatim' => [
        /*
         * Sakelar eksplisit, BUKAN disimpulkan dari url-nya.
         *
         * Godaan pertama adalah menganggap "url masih localhost berarti belum
         * terpasang". Itu salah dua kali: server yang MEMANG memasang Nominatim
         * di localhost akan dianggap belum, dan pemeriksaannya menuntut env()
         * dibaca di luar berkas config — yang mengembalikan null begitu
         * `config:cache` dijalankan, jadi fiturnya mati diam-diam di produksi
         * tepat setelah langkah deploy yang wajib.
         */
        'enabled' => (bool) env('NOMINATIM_ENABLED', false),

        'url' => env('NOMINATIM_URL', 'http://127.0.0.1:8080'),
        'email' => env('NOMINATIM_EMAIL'),
        'timeout' => (float) env('NOMINATIM_TIMEOUT', 4),
        'connect_timeout' => (float) env('NOMINATIM_CONNECT_TIMEOUT', 1),

        // Alamat jarang berubah, dan hasil yang sama diminta berulang kali oleh
        // orang berbeda di kota yang sama.
        'cache_hours' => (int) env('NOMINATIM_CACHE_HOURS', 72),
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Places — autocomplete alamat
    |--------------------------------------------------------------------------
    |
    | Berbayar per request, jadi hanya untuk autocomplete yang di-debounce di
    | sisi app dan di-cache agresif di sisi kita. Alamat jarang berubah.
    |
    */

    'places' => [
        'key' => env('GOOGLE_PLACES_KEY'),
        'cache_days' => (int) env('GOOGLE_PLACES_CACHE_DAYS', 30),
        'country' => env('GOOGLE_PLACES_COUNTRY', 'id'),
        'language' => env('GOOGLE_PLACES_LANGUAGE', 'id'),
        'timeout' => (float) env('GOOGLE_PLACES_TIMEOUT', 4),
    ],

    /*
    |--------------------------------------------------------------------------
    | Centrifugo — realtime gateway
    |--------------------------------------------------------------------------
    |
    | Laravel tidak memegang koneksi WebSocket. Dia hanya menerbitkan token
    | channel setelah memeriksa otorisasi, lalu mem-publish event lewat HTTP API
    | Centrifugo. Kanal: order:{uuid}, driver:{id}, merchant:{id}, admin:live.
    |
    */

    'centrifugo' => [
        'url' => env('CENTRIFUGO_URL', 'http://127.0.0.1:8100'),
        'api_key' => env('CENTRIFUGO_API_KEY'),
        'token_hmac_secret' => env('CENTRIFUGO_TOKEN_HMAC_SECRET'),
        'token_ttl' => (int) env('CENTRIFUGO_TOKEN_TTL', 3600),
        'timeout' => (float) env('CENTRIFUGO_TIMEOUT', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Location Service (Go)
    |--------------------------------------------------------------------------
    |
    | Laravel hanya perlu tahu URL-nya untuk memberi tahu app driver ke mana
    | harus mengirim ping, dan token bersama untuk memverifikasi callback.
    |
    */

    /*
     * Konfigurasi layanan lokasi ADA DI `config/antaride.php`, bukan di sini.
     *
     * Blok `location_service` di berkas ini pernah ada dan TIDAK PERNAH dibaca
     * satu pun bagian aplikasi. Dua config untuk hal yang sama tidak sekadar
     * berlebihan — yang di sini memakai nama kunci env yang BERBEDA
     * (`LOCATION_SERVICE_TOKEN` alih-alih `LOCATION_SERVICE_SECRET`).
     *
     * Yang terjadi kalau dibiarkan: orang yang menyiapkan server membaca berkas
     * ini, menyetel `LOCATION_SERVICE_TOKEN`, dan nilainya diabaikan. Laravel
     * jatuh ke penurunan rahasia dari APP_KEY, sementara layanan Go memakai
     * nilai yang disetel — dan SETIAP ping ditolak 401. Tidak ada galat di
     * aplikasi driver; yang terlihat hanya driver online tanpa satu pun order.
     */

    /*
    |--------------------------------------------------------------------------
    | Duitku — top up (VA, QRIS, retail)
    |--------------------------------------------------------------------------
    */

    'duitku' => [
        'merchant_code' => env('DUITKU_MERCHANT_CODE'),
        'api_key' => env('DUITKU_API_KEY'),
        'env' => env('DUITKU_ENV', 'sandbox'),
        'base_url' => env('DUITKU_ENV', 'sandbox') === 'production'
            ? 'https://passport.duitku.com'
            : 'https://sandbox.duitku.com',
        'callback_url' => env('DUITKU_CALLBACK_URL'),
        'timeout' => (float) env('DUITKU_TIMEOUT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Disbursement — payout ke rekening driver
    |--------------------------------------------------------------------------
    */

    'flip' => [
        'secret_key' => env('FLIP_SECRET_KEY'),
        'validation_token' => env('FLIP_VALIDATION_TOKEN'),
        'env' => env('FLIP_ENV', 'sandbox'),
        'base_url' => env('FLIP_ENV', 'sandbox') === 'production'
            ? 'https://bigflip.id/api'
            : 'https://bigflip.id/big_sandbox_api',
        'timeout' => (float) env('FLIP_TIMEOUT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | FCM — push notification (HTTP v1)
    |--------------------------------------------------------------------------
    */

    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID'),
        'credentials_path' => env('FCM_CREDENTIALS_PATH'),
        'timeout' => (float) env('FCM_TIMEOUT', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mail transport bawaan Laravel
    |--------------------------------------------------------------------------
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

];
