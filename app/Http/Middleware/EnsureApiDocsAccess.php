<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP Basic auth untuk dokumentasi API.
 *
 * ============================================================================
 *  KENAPA DOKUMENTASI PERLU DIJAGA SAMA SEKALI
 * ============================================================================
 *  Spesifikasi OpenAPI Antaride memuat SELURUH permukaan serangan dalam satu
 *  berkas yang rapi: setiap endpoint, setiap nama field, setiap aturan validasi,
 *  dan setiap kode galat.
 *
 *  Itu persis yang dibutuhkan siapa pun yang hendak menyerangnya, dan
 *  mengumpulkannya sendiri butuh berjam-jam menebak. Menerbitkannya terbuka
 *  berarti menyerahkan pekerjaan itu secara cuma-cuma.
 *
 *  Yang dijaga BUKAN kerahasiaan API-nya — endpoint tetap bisa ditemukan dengan
 *  mengamati aplikasi. Yang dijaga adalah kemudahannya.
 * ============================================================================
 *
 * ============================================================================
 *  BASIC AUTH, BUKAN SESI ADMIN
 * ============================================================================
 *  Yang membuka dokumentasi ini pengembang aplikasi mobile dan integrator —
 *  bukan staf backoffice. Menuntut mereka punya akun admin berarti memberi akses
 *  panel yang memuat data penumpang kepada orang yang hanya perlu membaca daftar
 *  endpoint.
 *
 *  Basic auth juga yang dipahami setiap alat: Postman, Insomnia, `curl`, dan
 *  generator klien bisa mengambil spesifikasinya tanpa alur login berbasis
 *  halaman.
 * ============================================================================
 */
class EnsureApiDocsAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $userHarusnya = (string) config('antaride.docs.username');
        $sandiHarusnya = (string) config('antaride.docs.password');

        /*
         * Kredensial KOSONG berarti dokumentasi DITUTUP, bukan terbuka.
         *
         * Ini arah gagal yang penting. Kalau `.env` produksi lupa memuat
         * `API_DOCS_USERNAME`, perilaku yang "ramah" — membiarkannya terbuka —
         * menerbitkan seluruh permukaan API tanpa ada yang menyadarinya.
         *
         * 404, bukan 403: halaman yang tidak dikonfigurasi sebaiknya tidak
         * mengaku ada sama sekali.
         */
        if ($userHarusnya === '' || $sandiHarusnya === '') {
            abort(404);
        }

        $user = (string) $request->getUser();
        $sandi = (string) $request->getPassword();

        /*
         * `hash_equals` untuk KEDUANYA, dan keduanya selalu dijalankan.
         *
         * Perbandingan `===` biasa berhenti di karakter pertama yang berbeda,
         * jadi waktu jawabannya membocorkan berapa karakter awal yang sudah
         * benar. Dengan cukup banyak percobaan, sandi bisa ditebak per karakter.
         *
         * `&` bukan `&&`: operator `&&` melompati pemeriksaan kedua kalau yang
         * pertama gagal, dan lompatan itu sendiri terukur dari waktunya.
         */
        $cocok = hash_equals($userHarusnya, $user)
            & hash_equals($sandiHarusnya, $sandi);

        if ($cocok !== 1) {
            return response('Dokumentasi API Antaride memerlukan autentikasi.', 401, [
                // `WWW-Authenticate` yang membuat browser menampilkan dialog
                // nama pengguna dan sandi. Tanpa header ini, yang terlihat hanya
                // halaman 401 kosong tanpa cara memasukkan kredensial.
                'WWW-Authenticate' => 'Basic realm="Dokumentasi API Antaride", charset="UTF-8"',
            ]);
        }

        return $next($request);
    }
}
