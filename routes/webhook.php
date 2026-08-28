<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Webhook Payment Gateway
|------------------------------------------------------------------------------
|
| Tanpa CSRF, tanpa session, tanpa auth Sanctum. Yang menggantikannya:
| verifikasi tanda tangan di controller, allowlist IP provider, dan pencatatan
| setiap payload masuk ke payment_webhook_logs sebelum diproses.
|
| Prinsip yang dipegang di sini: JANGAN pernah percaya webhook saja. Webhook
| hilang itu normal, bukan kasus langka. Setiap webhook punya pasangan job
| polling yang membandingkan status ke provider secara berkala, dan yang
| menentukan kebenaran adalah hasil polling, bukan webhook yang mungkin tidak
| pernah datang.
|
| Idempotency wajib. Provider akan mengirim ulang webhook yang sama, dan
| memproses dua kali berarti saldo bertambah dua kali.
|
*/

Route::post('/duitku/callback', function () {
    abort(501, 'Belum diimplementasikan.');
})->name('duitku.callback');

Route::post('/flip/callback', function () {
    abort(501, 'Belum diimplementasikan.');
})->name('flip.callback');
