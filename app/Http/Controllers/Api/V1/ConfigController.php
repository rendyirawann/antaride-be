<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Konfigurasi yang dibaca aplikasi saat mulai.
 *
 * ============================================================================
 *  KENAPA AREA LAYANAN DATANG DARI SERVER, BUKAN DITULIS DI APLIKASI
 * ============================================================================
 *  Area layanan berubah lebih cepat daripada aplikasi bisa diperbarui. Saat
 *  cakupan digeser — misalnya OSRM di server dipersempit ke sekitar Lubuk
 *  Pakam — aplikasi yang menyimpan koordinatnya sendiri akan tetap membuka
 *  peta di kota lama.
 *
 *  Yang dilihat pengguna saat itu bukan pesan galat, melainkan peta yang
 *  membuka kota yang salah lalu menolak menghitung ongkos tanpa alasan yang
 *  bisa dia mengerti. Memperbaikinya menuntut membangun ulang dan membagikan
 *  ulang APK ke semua orang.
 *
 *  Dengan endpoint ini, menggeser area cukup mengubah .env dan memuat ulang
 *  konfigurasi.
 * ============================================================================
 *
 * ============================================================================
 *  TERBUKA TANPA LOGIN, DAN ITU DISENGAJA
 * ============================================================================
 *  Isinya tidak rahasia — titik tengah peta dan nama kota. Aplikasi
 *  membutuhkannya SEBELUM ada sesi: layar sambutan dan pemilih rute bisa
 *  terbuka sebelum pengguna masuk.
 * ============================================================================
 */
class ConfigController extends Controller
{
    public function show(): JsonResponse
    {
        return ApiResponse::success([
            'area' => [
                'lat' => (float) config('antaride.area.lat'),
                'lng' => (float) config('antaride.area.lng'),
                'radius_km' => (float) config('antaride.area.radius_km'),
                'zoom' => (float) config('antaride.area.zoom'),
                'label' => (string) config('antaride.area.label'),
            ],

            /*
             * Aplikasi menyembunyikan kolom pencarian alamat kalau ini false.
             *
             * Server yang belum memasang Nominatim akan mengembalikan daftar
             * kosong untuk SETIAP ketikan — dan kolom pencarian yang tidak
             * pernah menemukan apa pun terbaca sebagai aplikasi rusak, bukan
             * sebagai fitur yang belum dinyalakan. Lebih baik tidak
             * menampilkannya sama sekali.
             */
            'places_enabled' => $this->pencarianAlamatMenyala(),
        ]);
    }

    /**
     * Menyala hanya kalau geocoder-nya dinyalakan secara eksplisit di .env.
     *
     * Lewat `config()`, bukan `env()`: `config:cache` — langkah wajib di
     * DEPLOY.md — membuat `env()` mengembalikan null di luar berkas config,
     * dan fiturnya akan mati diam-diam persis setelah deploy.
     */
    private function pencarianAlamatMenyala(): bool
    {
        return (bool) config('services.nominatim.enabled', false);
    }
}
