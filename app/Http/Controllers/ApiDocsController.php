<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;

/**
 * Swagger UI untuk API Antaride.
 *
 * ============================================================================
 *  SWAGGER UI MENAMPILKANNYA, SCRAMBLE YANG MEMBUATNYA
 * ============================================================================
 *  Dua hal yang sering dikira satu:
 *
 *    Spesifikasi   berkas OpenAPI 3.1 yang menjelaskan seluruh endpoint.
 *                  Dihasilkan Scramble DARI KODE — dari FormRequest, tipe
 *                  parameter, dan Resource yang sudah ada.
 *
 *    Tampilan      Swagger UI. Membaca spesifikasi itu dan menggambarkannya.
 *
 *  Alternatif yang lazim adalah `l5-swagger`, yang menuntut anotasi `@OA\` di
 *  atas setiap controller. Itu TIDAK dipakai di sini, dan alasannya bukan
 *  kemalasan: anotasi adalah salinan kedua dari aturan yang sudah ada di
 *  FormRequest. Dua salinan yang harus sepakat akan menyimpang — dan yang
 *  menyimpang adalah dokumentasi, karena dia tidak dijalankan siapa pun.
 *
 *  Dengan Scramble, menambah aturan `required` di FormRequest langsung muncul
 *  di dokumentasi. Tidak ada langkah kedua yang bisa terlupa.
 * ============================================================================
 */
class ApiDocsController extends Controller
{
    /**
     * Halaman Swagger UI.
     */
    public function index(): View
    {
        return view('docs.swagger');
    }

    /**
     * Spesifikasi OpenAPI.
     *
     * ========================================================================
     *  DIBACA DARI BERKAS, DIBUAT ULANG HANYA KALAU TIDAK ADA
     * ========================================================================
     *  `docs/openapi/openapi.json` di-commit ke repo dan diperbarui saat deploy.
     *  Membacanya dari berkas berarti halaman dokumentasi tidak menjalankan
     *  analisis statis Scramble pada setiap pemuatan — analisis itu memindai
     *  seluruh controller dan memakan beberapa detik.
     *
     *  Kalau berkasnya tidak ada, spesifikasinya dibuat saat itu juga alih-alih
     *  menyerah. Itu yang mencegah gejala yang paling membingungkan dari halaman
     *  ini: SWAGGER UI YANG PUTIH POLOS.
     *
     *  Yang terjadi tanpa cadangan itu: endpoint spesifikasi menjawab 404,
     *  Swagger UI gagal memuatnya, dan karena kegagalannya terjadi di dalam
     *  JavaScript-nya sendiri, yang tampil adalah halaman kosong tanpa satu pun
     *  pesan. Orang yang membukanya menyimpulkan server mati.
     * ========================================================================
     */
    public function spec(Request $request): JsonResponse
    {
        $path = base_path('docs/openapi/openapi.json');

        if (! File::exists($path)) {
            try {
                Artisan::call('scramble:export', ['--path' => 'docs/openapi/openapi.json']);
            } catch (\Throwable $e) {
                return response()->json([
                    /*
                     * Bentuk galatnya SENGAJA bukan spesifikasi OpenAPI yang
                     * kosong. Swagger UI menampilkan pesan "tidak bisa memuat"
                     * yang bisa dibaca untuk JSON yang bukan spesifikasi,
                     * sementara spesifikasi kosong digambarkannya sebagai
                     * halaman tanpa endpoint — yang terbaca sebagai API yang
                     * tidak punya apa-apa, bukan sebagai kegagalan.
                     */
                    'error' => 'Spesifikasi OpenAPI belum dibuat dan gagal dibuat sekarang.',
                    'perbaikan' => 'Jalankan: php artisan scramble:export --path=docs/openapi/openapi.json',
                    'detail' => $e->getMessage(),
                ], 503);
            }
        }

        $isi = json_decode((string) File::get($path), true);

        if (! is_array($isi) || ! isset($isi['paths'])) {
            return response()->json([
                'error' => 'Berkas spesifikasi ada tapi isinya tidak sah.',
                'perbaikan' => 'Buat ulang: php artisan scramble:export --path=docs/openapi/openapi.json',
            ], 503);
        }

        /*
         * ====================================================================
         *  `servers` DITULIS ULANG DARI REQUEST YANG SEDANG BERJALAN
         * ====================================================================
         *  Berkas spesifikasi dibuat di komputer pengembang, jadi `servers` di
         *  dalamnya memuat `http://127.0.0.1:8000`. Kalau dibiarkan, tombol
         *  "Try it out" di server produksi akan mengirim request ke localhost
         *  MILIK PEMBACA — yang gagal dengan galat jaringan yang tidak menyebut
         *  server sama sekali.
         *
         *  `url()` di sini membawa subfolder secara otomatis lewat
         *  `X-Forwarded-Prefix`, jadi deploy di `/antaride-be` menghasilkan
         *  `https://domain/antaride-be/api` tanpa disetel terpisah.
         *  Lihat tests/Feature/Http/SubfolderDeploymentTest.php.
         *
         *  --------------------------------------------------------------------
         *   `/api`, BUKAN `/api/v1`
         *  --------------------------------------------------------------------
         *   Scramble memakai `api_path => 'api'`, jadi prefix itu DIPINDAHKAN ke
         *   `servers` dan path di dalam spesifikasi dimulai dari `/v1/...`.
         *
         *   Menuliskan `/api/v1` di sini menghasilkan `/api/v1/v1/quotes` saat
         *   tombol "Try it out" ditekan — 404 yang tidak menyebut penyebabnya
         *   sama sekali. `test_gabungan_servers_dan_path_menunjuk_route_yang_ada`
         *   menyusun ulang URL-nya dan mencocokkannya dengan route sungguhan.
         * ====================================================================
         */
        $isi['servers'] = [[
            'url' => url('/'.trim((string) config('scramble.api_path', 'api'), '/')),
            'description' => config('app.env') === 'production'
                ? 'Server produksi'
                : 'Server '.config('app.env'),
        ]];

        return response()->json($isi, 200, [
            // Spesifikasi berubah setiap deploy. Cache browser di sini berarti
            // pengembang aplikasi membaca daftar endpoint versi lama setelah
            // API-nya berubah.
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
