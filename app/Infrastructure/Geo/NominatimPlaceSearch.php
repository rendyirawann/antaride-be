<?php

declare(strict_types=1);

namespace App\Infrastructure\Geo;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pencarian dan reverse geocoding alamat lewat Nominatim.
 *
 * ============================================================================
 *  KENAPA NOMINATIM, BUKAN GOOGLE PLACES
 * ============================================================================
 *  Autocomplete dipanggil pada SETIAP KETIKAN. Dengan Google Places yang
 *  ditagih per permintaan, satu penumpang yang mengetik "jalan sudirman" bisa
 *  menjadi belasan permintaan berbayar untuk satu order.
 *
 *  Nominatim memakai data OSM yang sama dengan OSRM yang sudah dipasang untuk
 *  perhitungan rute — satu sumber peta, dua mesin, nol biaya per permintaan.
 *  Konsekuensinya kualitas alamat Indonesia lebih rendah daripada Google;
 *  itu ditebus dengan pembatasan area (lihat [$viewbox]) yang membuang hasil
 *  dari kota lain sebelum sampai ke layar.
 * ============================================================================
 *
 * ============================================================================
 *  KEGAGALAN DI SINI TIDAK BOLEH MENGHENTIKAN PEMESANAN
 * ============================================================================
 *  Pencarian alamat adalah KEMUDAHAN, bukan syarat: pengguna tetap bisa
 *  menggeser peta untuk memilih titik. Karena itu setiap kegagalan
 *  mengembalikan daftar KOSONG dan dicatat ke log — bukan melempar exception
 *  yang membuat layar pemilih rute menampilkan galat merah.
 *
 *  Yang dihindari: Nominatim mati membuat orang tidak bisa memesan sama sekali,
 *  padahal cara memesan yang lama masih bekerja sepenuhnya.
 * ============================================================================
 */
class NominatimPlaceSearch
{
    /**
     * Cari alamat dari kata kunci.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query, ?float $lat = null, ?float $lng = null): array
    {
        $query = trim($query);

        // Di bawah tiga huruf, hasilnya seluruh kota dan tidak berguna — dan
        // tiap ketikan pertama akan memanggil geocoder tanpa alasan.
        if (mb_strlen($query) < 3) {
            return [];
        }

        $kunci = 'places:s:'.md5(mb_strtolower($query));

        return $this->diingat($kunci, function () use ($query): array {
            $hasil = $this->minta('/search', [
                'q' => $query,
                'format' => 'jsonv2',
                'addressdetails' => 1,
                'limit' => 8,
                'countrycodes' => 'id',
                'accept-language' => 'id',

                // `bounded=1` MEMBUANG hasil di luar kotak, bukan sekadar
                // mengurutkannya lebih bawah. Tanpa ini, mengetik "jalan
                // merdeka" mengembalikan Jalan Merdeka di seluruh Indonesia —
                // dan yang teratas belum tentu yang di kota ini.
                'viewbox' => $this->viewbox(),
                'bounded' => 1,
            ]);

            return array_values(array_filter(array_map(
                fn (array $baris): ?array => $this->petakan($baris),
                $hasil,
            )));
        });
    }

    /**
     * Alamat untuk satu titik koordinat.
     *
     * Dipakai saat GPS menemukan posisi pengguna: kolom alamat terisi sendiri,
     * jadi dia tidak perlu mengetik alamat tempat dia sedang berdiri.
     *
     * @return array<string, mixed>|null
     */
    public function reverse(float $lat, float $lng): ?array
    {
        // Dibulatkan ke lima desimal (~1 meter) SEBELUM jadi kunci cache.
        // Tanpa pembulatan, GPS yang bergeser beberapa sentimeter menghasilkan
        // kunci baru setiap kali dan cache-nya tidak pernah kena.
        $kunci = sprintf('places:r:%.5f,%.5f', $lat, $lng);

        $hasil = $this->diingat($kunci, function () use ($lat, $lng): array {
            $baris = $this->minta('/reverse', [
                'lat' => $lat,
                'lon' => $lng,
                'format' => 'jsonv2',
                'addressdetails' => 1,
                'accept-language' => 'id',

                // 18 = tingkat bangunan/nomor rumah. Lebih rendah dari itu
                // mengembalikan nama kelurahan untuk titik yang persis berada
                // di depan sebuah toko.
                'zoom' => 18,
            ]);

            // `/reverse` mengembalikan SATU objek, bukan array. Dibungkus jadi
            // array supaya bisa memakai cache dan pemetaan yang sama.
            $satu = isset($baris['lat']) ? $this->petakan($baris) : null;

            return $satu === null ? [] : [$satu];
        });

        return $hasil[0] ?? null;
    }

    // -------------------------------------------------------------------------

    /**
     * Kotak pembatas area layanan, dihitung dari `antaride.area`.
     *
     * Format Nominatim: kiri,atas,kanan,bawah (lng,lat,lng,lat).
     */
    private function viewbox(): string
    {
        $lat = (float) config('antaride.area.lat');
        $lng = (float) config('antaride.area.lng');
        $radius = (float) config('antaride.area.radius_km');

        // Satu derajat lintang selalu ~111 km. Satu derajat bujur menyempit
        // mengikuti kosinus lintang — di dekat khatulistiwa selisihnya kecil,
        // tapi rumusnya tetap dipakai supaya area tidak melar kalau nanti
        // dipindah ke kota yang jauh dari khatulistiwa.
        $dLat = $radius / 111.0;
        $dLng = $radius / (111.0 * max(cos(deg2rad($lat)), 0.01));

        return sprintf(
            '%.6f,%.6f,%.6f,%.6f',
            $lng - $dLng,
            $lat + $dLat,
            $lng + $dLng,
            $lat - $dLat,
        );
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<mixed>
     */
    private function minta(string $path, array $params): array
    {
        try {
            $response = $this->klien()->get($path, $params);

            if (! $response->successful()) {
                Log::warning('Nominatim menjawab bukan 2xx.', [
                    'path' => $path,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $data = $response->json();

            return is_array($data) ? $data : [];
        } catch (Throwable $e) {
            // Termasuk kasus paling umum di server yang belum memasang
            // Nominatim: connection refused ke localhost.
            Log::warning('Nominatim tidak bisa dihubungi.', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function klien(): PendingRequest
    {
        $klien = Http::baseUrl(rtrim((string) config('services.nominatim.url'), '/'))
            ->timeout((float) config('services.nominatim.timeout', 4))
            ->connectTimeout((float) config('services.nominatim.connect_timeout', 1))

            // Kebijakan Nominatim menuntut User-Agent yang mengidentifikasi
            // aplikasinya. Instans yang memakai bawaan pustaka HTTP diblokir.
            ->withHeaders(['User-Agent' => 'Antaride/1.0 (+https://antaride.id)']);

        $email = config('services.nominatim.email');

        return is_string($email) && $email !== ''
            ? $klien->withQueryParameters(['email' => $email])
            : $klien;
    }

    /**
     * Bentuk hasil yang dipakai aplikasi. Satu bentuk untuk search dan reverse.
     *
     * @param  array<string, mixed>  $baris
     * @return array<string, mixed>|null
     */
    private function petakan(array $baris): ?array
    {
        $lat = isset($baris['lat']) ? (float) $baris['lat'] : null;
        $lng = isset($baris['lon']) ? (float) $baris['lon'] : null;

        if ($lat === null || $lng === null) {
            return null;
        }

        $alamat = is_array($baris['address'] ?? null) ? $baris['address'] : [];

        /*
         * Nama pendek DIPISAH dari alamat lengkap, dan itu bukan hiasan.
         *
         * Daftar saran menampilkan keduanya bertingkat: nama tebal di atas,
         * alamat lengkap kecil di bawahnya. Tanpa pemisahan ini, tiap baris
         * hanya berisi satu kalimat panjang yang dipotong "..." di tengah nama
         * tempatnya — persis bagian yang dicari pembaca.
         */
        $nama = $baris['name'] ?? null;

        if (! is_string($nama) || $nama === '') {
            $nama = $alamat['road']
                ?? $alamat['village']
                ?? $alamat['suburb']
                ?? $alamat['city']
                ?? null;
        }

        $lengkap = is_string($baris['display_name'] ?? null)
            ? $baris['display_name']
            : (string) $nama;

        if (! is_string($nama) || $nama === '') {
            // Tidak ada nama yang bisa dipakai: ambil ruas pertama alamat
            // lengkap, itulah yang paling spesifik di format Nominatim.
            $nama = trim(explode(',', $lengkap)[0]);
        }

        return [
            'name' => $nama,
            'address' => $lengkap,
            'lat' => $lat,
            'lng' => $lng,
        ];
    }

    /**
     * @param  callable(): array<mixed>  $isi
     * @return array<mixed>
     */
    private function diingat(string $kunci, callable $isi): array
    {
        $jam = (int) config('services.nominatim.cache_hours', 72);

        /*
         * Hasil KOSONG tidak disimpan lama.
         *
         * Kalau Nominatim sedang mati, setiap pencarian mengembalikan kosong —
         * dan menyimpannya selama tiga hari berarti fitur ini tetap mati
         * berjam-jam SETELAH Nominatim hidup kembali. Lima menit cukup untuk
         * meredam ketikan beruntun, cukup pendek untuk pulih sendiri.
         */
        $hasil = Cache::get($kunci);

        if (is_array($hasil)) {
            return $hasil;
        }

        $baru = $isi();

        Cache::put($kunci, $baru, $baru === [] ? 300 : $jam * 3600);

        return $baru;
    }
}
