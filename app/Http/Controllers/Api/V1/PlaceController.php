<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Infrastructure\Geo\NominatimPlaceSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pencarian alamat untuk kolom titik jemput dan tujuan.
 *
 * ============================================================================
 *  BUTUH LOGIN, WALAUPUN DATANYA PUBLIK
 * ============================================================================
 *  Alamat memang bukan rahasia. Yang dijaga bukan datanya melainkan BIAYA dan
 *  KUOTA-nya: endpoint ini meneruskan ke geocoder yang punya batas kecepatan,
 *  dan endpoint terbuka yang meneruskan ke layanan berkuota adalah cara
 *  termudah membuat instans Nominatim sendiri diblokir oleh lalu lintas orang
 *  lain.
 *
 *  Rate limit tersendiri, lebih longgar dari OTP tapi tetap ada: satu penumpang
 *  yang mengetik alamat menghasilkan beberapa permintaan dalam beberapa detik,
 *  jadi batasnya harus memuat pengetikan wajar tanpa memuat skrip.
 * ============================================================================
 */
class PlaceController extends Controller
{
    public function __construct(private readonly NominatimPlaceSearch $places) {}

    /**
     * Cari alamat dari kata kunci.
     *
     * Dipanggil saat pengguna mengetik di kolom alamat. Aplikasi menahan
     * (debounce) sebelum memanggil — lihat catatan di layar pemilih rute.
     */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'max:120'],

            // Titik acuan opsional untuk mengurutkan hasil dari yang terdekat.
            // Bukan penyaring: penyaringan area dilakukan viewbox di geocoder.
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $hasil = $this->places->search(
            (string) $data['q'],
            isset($data['lat']) ? (float) $data['lat'] : null,
            isset($data['lng']) ? (float) $data['lng'] : null,
        );

        return ApiResponse::success(['places' => $hasil]);
    }

    /**
     * Alamat untuk satu koordinat.
     *
     * Dipakai dua kali di layar pemilih rute: saat GPS menemukan posisi
     * pengguna, dan setiap kali dia berhenti menggeser peta.
     */
    public function reverse(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $tempat = $this->places->reverse((float) $data['lat'], (float) $data['lng']);

        // `place: null` BUKAN galat. Titik di tengah sawah memang tidak punya
        // alamat, dan aplikasi menanganinya dengan membiarkan kolomnya kosong
        // untuk diisi sendiri.
        return ApiResponse::success(['place' => $tempat]);
    }
}
