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

    'location_service' => [
        'url' => env('LOCATION_SERVICE_URL', 'http://127.0.0.1:8200'),
        'token' => env('LOCATION_SERVICE_TOKEN'),
    ],

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
