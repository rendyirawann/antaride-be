<?php

declare(strict_types=1);

namespace App\Domain\Matching\Contracts;

use App\Domain\Matching\DTOs\DriverPosition;
use App\Domain\Shared\ValueObjects\Coordinate;

/**
 * Indeks posisi driver, untuk pencarian kandidat matching.
 *
 * Datanya hidup di Redis, bukan PostgreSQL. Seribu driver yang ping tiap 4
 * detik menghasilkan 250 write per detik; kalau itu masuk tabel, dalam sehari
 * bertambah 21 juta baris yang tidak berguna karena posisi sepuluh menit lalu
 * sudah tidak ada artinya.
 *
 * PENTING: key yang dipakai di sini DIBAGI dengan location service Go yang
 * menulisnya. Nama key adalah bagian dari kontrak antar service, jadi
 * implementasinya wajib memakai koneksi Redis tanpa prefix.
 */
interface DriverLocationIndex
{
    /**
     * Driver yang berada dalam radius sebuah titik, terdekat lebih dulu.
     *
     * MURNI GEOGRAFIS. Yang dikembalikan adalah semua driver yang posisinya ada
     * di indeks layanan itu, termasuk yang sedang memegang order.
     *
     * Penyaringan ketersediaan TIDAK dilakukan di sini, dan itu disengaja.
     * Indeks posisi harus tetap memuat driver yang sedang mengantar, karena
     * penumpangnya perlu melihat drivernya bergerak. Yang menentukan siapa
     * boleh ditawari order adalah set ketersediaan, dan radius pencarian bisa
     * melintasi beberapa zona sekaligus, jadi irisannya dilakukan pemanggil
     * lewat availableDriverIds().
     *
     * @param  int  $limit  batas jumlah, supaya radius lebar tidak mengembalikan ribuan
     * @return array<int, DriverPosition>
     */
    public function findNearby(
        string $serviceCode,
        Coordinate $center,
        int $radiusMeters,
        int $limit = 20,
    ): array;

    /**
     * Id driver yang siap menerima order untuk sebuah layanan di zona-zona
     * tertentu.
     *
     * Menerima beberapa zona karena radius pencarian matching sering melintasi
     * batas zona, dan driver di zona sebelah yang jaraknya 500 meter lebih
     * layak ditawari daripada driver sezona yang jaraknya 4 km.
     *
     * @param  array<int, int>  $zoneIds
     * @return array<int, int>
     */
    public function availableDriverIds(string $serviceCode, array $zoneIds): array;

    /**
     * Posisi terakhir satu driver.
     */
    public function positionOf(int $driverId): ?DriverPosition;

    /**
     * Posisi terakhir beberapa driver sekaligus.
     *
     * Dipakai live map panel admin. Satu pipeline, bukan N panggilan: dengan
     * 500 marker, bedanya antara satu round trip dan lima ratus.
     *
     * @param  array<int, int>  $driverIds
     * @return array<int, DriverPosition>
     */
    public function positionsOf(array $driverIds): array;

    /**
     * Catat posisi driver.
     *
     * Di jalur normal ini dilakukan location service Go, bukan Laravel. Method
     * ini ada untuk seeding, test, dan koreksi manual dari panel admin.
     */
    public function record(
        string $serviceCode,
        int $driverId,
        Coordinate $coordinate,
        ?float $heading = null,
        ?float $speedKmh = null,
        ?float $accuracyM = null,
        ?int $batteryPercent = null,
    ): void;

    /**
     * Tandai driver siap menerima order untuk sebuah layanan di sebuah zona.
     */
    public function markAvailable(string $serviceCode, int $zoneId, int $driverId): void;

    /**
     * Cabut ketersediaan driver.
     *
     * Dipanggil saat driver menerima order, offline, atau ping-nya berhenti.
     */
    public function markUnavailable(string $serviceCode, int $zoneId, int $driverId): void;

    /**
     * Cabut ketersediaan driver di seluruh layanan dan zona.
     *
     * Dipakai saat driver offline atau disuspend, ketika tidak diketahui lagi
     * dia terdaftar di kombinasi mana saja.
     */
    public function markUnavailableEverywhere(int $driverId): void;

    /**
     * Jumlah driver tersedia untuk sebuah layanan di sebuah zona.
     *
     * Ini pembagi dari rasio permintaan-pasokan yang menentukan surge otomatis,
     * dan angka yang paling sering dilihat tim ops.
     */
    public function availableCount(string $serviceCode, int $zoneId): int;

    /**
     * Driver dalam sebuah kotak, untuk live map panel admin.
     *
     * Dibatasi $limit. Di atas itu, pemanggil harus menampilkan agregat cluster
     * per grid, bukan marker individual: 500 elemen DOM adalah beda antara
     * 60 fps dan 4 fps.
     *
     * @return array<int, DriverPosition>
     */
    public function findInBox(
        string $serviceCode,
        Coordinate $southWest,
        Coordinate $northEast,
        int $limit = 500,
    ): array;

    /**
     * Hapus jejak driver dari indeks. Dipakai test dan pembersihan.
     */
    public function forget(int $driverId): void;
}
