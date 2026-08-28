<?php

use App\Domain\Identity\Models\Admin;
use App\Domain\Identity\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Default
    |--------------------------------------------------------------------------
    |
    | Guard default 'api' karena beban utama aplikasi ini adalah mobile.
    | Panel admin selalu menyebut guard 'admin' secara eksplisit.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'api'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'admins'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Guard
    |--------------------------------------------------------------------------
    |
    | Tiga guard dengan pemisahan yang disengaja:
    |
    |   api     token Sanctum untuk mobile. Stateless. Provider 'users'.
    |   admin   session cookie untuk panel backoffice. Provider 'admins'.
    |   web     session untuk halaman publik non-admin (verifikasi email, dsb).
    |
    | Guard 'admin' memakai tabel, provider, dan session yang terpisah total
    | dari 'users'. Seorang admin TIDAK PERNAH punya baris di tabel users.
    | Ini menutup kemungkinan seorang customer memanjat jadi admin lewat celah
    | di alur registrasi atau reset password.
    |
    | Customer, driver, dan merchant sama-sama memakai guard 'api' dengan
    | provider 'users' yang sama. Peran mereka tidak ditentukan oleh guard,
    | tapi oleh keberadaan baris di tabel drivers / merchants, plus ability
    | pada token Sanctum yang menandai token itu diterbitkan untuk app mana.
    | Jadi token app customer tidak bisa dipakai memanggil endpoint driver.
    |
    */

    'guards' => [

        'api' => [
            'driver' => 'sanctum',
            'provider' => 'users',
        ],

        'admin' => [
            'driver' => 'session',
            'provider' => 'admins',
        ],

        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Provider
    |--------------------------------------------------------------------------
    */

    'providers' => [

        'users' => [
            'driver' => 'eloquent',
            'model' => User::class,
        ],

        'admins' => [
            'driver' => 'eloquent',
            'model' => Admin::class,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Reset Password
    |--------------------------------------------------------------------------
    |
    | Hanya admin yang punya alur reset password lewat email. Customer, driver,
    | dan merchant masuk lewat OTP nomor HP, jadi mereka tidak punya password
    | untuk direset. Ini mengurangi satu permukaan serangan.
    |
    */

    'passwords' => [

        'admins' => [
            'provider' => 'admins',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 30,
            'throttle' => 60,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Konfirmasi Ulang Password
    |--------------------------------------------------------------------------
    |
    | Blueprint admin bagian 3: approve withdrawal, adjustment saldo, ubah
    | tarif, dan ban driver wajib minta password lagi meski session masih
    | hidup. Jendelanya 15 menit, bukan default Laravel 3 jam.
    |
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 900),

];
