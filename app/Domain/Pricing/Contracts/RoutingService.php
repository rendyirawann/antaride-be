<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Contracts;

use App\Domain\Pricing\DTOs\RouteResult;
use App\Domain\Pricing\Exceptions\RoutingUnavailableException;
use App\Domain\Shared\ValueObjects\Coordinate;

/**
 * Perhitungan jarak tempuh, waktu tempuh, dan rute.
 *
 * Dipanggil untuk setiap quote, jadi ribuan kali per hari. Itu sebabnya
 * blueprint memilih OSRM self-host alih-alih Google Distance Matrix: ini
 * penghematan terbesar di seluruh sistem.
 */
interface RoutingService
{
    /**
     * Rute dari satu titik ke titik lain.
     *
     * @throws RoutingUnavailableException
     */
    public function route(Coordinate $origin, Coordinate $destination): RouteResult;

    /**
     * Rute dengan beberapa perhentian, berurutan.
     *
     * Dipakai layanan antar barang multi-drop. Urutan perhentian TIDAK
     * dioptimalkan; yang dikirim adalah urutan yang diminta pengguna, karena
     * pengguna sering punya alasan yang tidak diketahui sistem (paket pertama
     * harus sampai sebelum jam tutup kantor).
     *
     * @param  array<int, Coordinate>  $waypoints  minimal dua titik
     *
     * @throws RoutingUnavailableException
     */
    public function routeVia(array $waypoints): RouteResult;

    /**
     * Waktu tempuh dari beberapa titik asal ke satu tujuan.
     *
     * Dipakai matching untuk menghitung ETA kedatangan beberapa kandidat driver
     * ke titik jemput dalam SATU panggilan, bukan satu panggilan per driver.
     * Dengan lima kandidat per gelombang dan empat gelombang, bedanya antara
     * satu panggilan dan dua puluh.
     *
     * @param  array<int, Coordinate>  $origins
     * @return array<int, int|null> detik, indeksnya sejajar dengan $origins
     */
    public function durationsTo(array $origins, Coordinate $destination): array;

    /**
     * Apakah mesin routing sedang bisa dihubungi.
     *
     * Dipakai health check, bukan di jalur permintaan.
     */
    public function isAvailable(): bool;
}
