<?php

declare(strict_types=1);

use App\Http\Controllers\ApiDocsController;
use App\Http\Middleware\EnsureApiDocsAccess;
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

/*
|------------------------------------------------------------------------------
| Dokumentasi API — Swagger UI
|------------------------------------------------------------------------------
|
| `/api/documentation`, dijaga HTTP Basic auth (lihat `antaride.docs`).
|
| KENAPA DI SINI, BUKAN DI routes/api_v1.php
| ------------------------------------------------------------------------------
| Berkas itu seluruhnya di bawah prefix `api/v1` dan middleware Sanctum. Halaman
| dokumentasi bukan endpoint API: dia mengembalikan HTML, dan yang membukanya
| browser tanpa token Sanctum.
|
| KENAPA `/api/documentation`, BUKAN `/docs/api` MILIK SCRAMBLE
| ------------------------------------------------------------------------------
| Scramble punya tampilannya sendiri di `/docs/api`, tapi dijaga
| `RestrictedDocsAccess` yang hanya mengizinkan environment lokal — jadi di
| produksi halaman itu tidak bisa dibuka sama sekali.
|
| `/api/documentation` adalah jalur yang lazim dikenal orang dari `l5-swagger`,
| dan itu yang dicari pengembang aplikasi saat pertama kali membuka proyek ini.
|
| Scramble tetap dipakai — sebagai PEMBUAT spesifikasinya, bukan penampilnya.
|
*/
Route::middleware(EnsureApiDocsAccess::class)
    ->prefix('api/documentation')
    ->as('docs.')
    ->group(function (): void {
        Route::get('/', [ApiDocsController::class, 'index'])->name('index');

        // Dinamai `docs.spec`, dan namanya dipakai Blade lewat `route()`.
        // Itu yang membuat URL-nya membawa subfolder saat aplikasi di-deploy
        // di `/antaride-be` — path harfiah akan menunjuk ke luar subfolder.
        Route::get('/openapi.json', [ApiDocsController::class, 'spec'])->name('spec');
    });
