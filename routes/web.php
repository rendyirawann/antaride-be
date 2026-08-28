<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Web Publik
|------------------------------------------------------------------------------
|
| Antaride tidak punya halaman web untuk pengguna akhir. Customer, driver, dan
| merchant memakai aplikasi mobile; staf memakai panel admin.
|
| File ini sengaja hampir kosong. Menambahkan halaman publik di sini berarti
| menambah permukaan serangan pada aplikasi yang juga melayani panel admin,
| jadi kalau nanti butuh landing page, tempatnya di luar aplikasi ini.
|
*/

/*
|------------------------------------------------------------------------------
| Akar situs
|------------------------------------------------------------------------------
|
| Dua konfigurasi yang mungkin, dan keduanya harus ditangani:
|
|   LOKAL / SATU DOMAIN
|     ADMIN_DOMAIN kosong, admin_prefix = 'admin'.
|     '/' mengalihkan ke '/admin'.
|
|   PRODUKSI / SUBDOMAIN
|     ADMIN_DOMAIN diisi, dan admin_prefix otomatis menjadi '' (lihat
|     config/antaride.php baris routing). Panel admin sudah tidak ada di host
|     ini sama sekali.
|
| Versi pertama route ini hanya membaca admin_prefix:
|
|     return redirect($prefix !== '' ? "/{$prefix}" : '/');
|
| Pada konfigurasi kedua, prefix-nya '' sehingga hasilnya `redirect('/')` —
| yaitu ke route ini sendiri. Browser mengikuti 302 ke '/', mendapat 302 ke '/'
| lagi, dan berhenti dengan ERR_TOO_MANY_REDIRECTS. Yang menerima akibatnya
| adalah siapa pun yang membuka domain utama, termasuk kesalahan ketik staf
| sendiri, dan yang terlihat di monitoring hanya lonjakan 302 tanpa error.
|
| Sekarang: kalau admin sudah pindah subdomain, akar domain ini mengarahkan ke
| subdomain itu secara absolut. Kalau tidak ada tujuan yang masuk akal, 404 —
| jawaban jujur untuk host yang memang tidak punya halaman.
|
*/

Route::get('/', function () {
    $adminDomain = config('antaride.routing.admin_domain');

    if ($adminDomain !== null) {
        return redirect()->away(
            (request()->secure() ? 'https://' : 'http://').$adminDomain
        );
    }

    $prefix = (string) config('antaride.routing.admin_prefix');

    if ($prefix === '') {
        // Tidak ada subdomain admin dan tidak ada prefix. Tidak ada tempat
        // yang bisa dituju, dan mengalihkan ke diri sendiri bukan jawaban.
        abort(404);
    }

    return redirect("/{$prefix}");
})->name('home');
