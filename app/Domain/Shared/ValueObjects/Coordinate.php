<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObjects;

use App\Domain\Shared\Exceptions\InvalidCoordinateException;
use JsonSerializable;
use Stringable;

/**
 * Satu titik di permukaan bumi.
 *
 * Validasi dilakukan di konstruktor, jadi tidak ada Coordinate yang tidak sah
 * bisa beredar di sistem. Ini menutup kelas bug yang gejalanya aneh: koordinat
 * (0, 0) di Teluk Guinea yang datang dari GPS yang belum siap, atau lat dan lng
 * yang tertukar sehingga penumpang di Medan tiba-tiba berada di Somalia.
 *
 * Presisi dipatok 7 desimal, sekitar 1 cm. Lebih dari itu tidak bermakna karena
 * GPS ponsel terbaik pun hanya akurat beberapa meter, dan menyimpan digit yang
 * tidak bermakna membuat perbandingan kesetaraan gagal karena selisih di digit
 * ke-12.
 */
final readonly class Coordinate implements JsonSerializable, Stringable
{
    public const PRECISION = 7;

    private function __construct(
        public float $lat,
        public float $lng,
    ) {}

    public static function of(float $lat, float $lng): self
    {
        if ($lat < -90.0 || $lat > 90.0) {
            throw new InvalidCoordinateException("Latitude di luar rentang: {$lat}");
        }

        if ($lng < -180.0 || $lng > 180.0) {
            throw new InvalidCoordinateException("Longitude di luar rentang: {$lng}");
        }

        return new self(
            round($lat, self::PRECISION),
            round($lng, self::PRECISION),
        );
    }

    /**
     * @param  array{lat: float|string, lng: float|string}|array{0: float, 1: float}  $value
     */
    public static function fromArray(array $value): self
    {
        if (isset($value['lat'], $value['lng'])) {
            return self::of((float) $value['lat'], (float) $value['lng']);
        }

        if (isset($value[0], $value[1])) {
            return self::of((float) $value[0], (float) $value[1]);
        }

        throw new InvalidCoordinateException('Bentuk koordinat tidak dikenali.');
    }

    /**
     * Dari pasangan GeoJSON, yang urutannya [longitude, latitude].
     *
     * Urutan terbalik dibanding kebiasaan orang menyebut "lat, lng", dan itu
     * penyebab bug paling umum saat bekerja dengan GeoJSON. Method terpisah ini
     * membuat urutannya eksplisit di tempat pemanggilan.
     *
     * @param  array{0: float, 1: float}  $pair
     */
    public static function fromGeoJsonPair(array $pair): self
    {
        return self::of((float) $pair[1], (float) $pair[0]);
    }

    // -------------------------------------------------------------------------
    // Batas wilayah
    // -------------------------------------------------------------------------

    /**
     * Apakah titik ini berada di dalam bounding box Indonesia.
     *
     * Blueprint bagian 5 mensyaratkan ping GPS di luar kotak ini ditolak
     * mentah-mentah. Ini penjaga paling murah terhadap koordinat sampah:
     * (0,0) dari GPS yang belum fix, dan lat/lng yang tertukar.
     *
     * ==========================================================================
     *  INI BUKAN PEMERIKSA BATAS NEGARA.
     * ==========================================================================
     *  Sebuah persegi yang memuat Aceh sampai Papua secara geometris TIDAK
     *  MUNGKIN mengecualikan Singapura, Brunei, sebagian Malaysia, Timor Leste,
     *  dan Papua Nugini. Titik di Singapura akan lolos method ini.
     *
     *  Itu tidak masalah untuk tugasnya: yang ditolak di sini adalah koordinat
     *  yang jelas sampah, dan lolosnya negara tetangga tidak berbahaya karena
     *  keputusan "titik ini dilayani atau tidak" ditentukan oleh ZoneResolver
     *  yang menguji polygon zona operasional sungguhan.
     *
     *  Jangan pakai method ini untuk memutuskan kelayakan order.
     * ==========================================================================
     */
    public function isWithinIndonesia(): bool
    {
        $bounds = config('antaride.gps.bounds');

        return $this->lat >= $bounds['min_lat']
            && $this->lat <= $bounds['max_lat']
            && $this->lng >= $bounds['min_lng']
            && $this->lng <= $bounds['max_lng'];
    }

    /**
     * Titik nol yang datang dari GPS yang belum mendapat sinyal.
     *
     * Diperiksa terpisah dari isWithinIndonesia() supaya pesan errornya bisa
     * membedakan "GPS belum siap" dari "lokasi di luar wilayah layanan".
     */
    public function isNullIsland(): bool
    {
        return abs($this->lat) < 0.0001 && abs($this->lng) < 0.0001;
    }

    // -------------------------------------------------------------------------
    // Perhitungan
    // -------------------------------------------------------------------------

    /**
     * Jarak garis lurus dalam meter, rumus haversine.
     *
     * PENTING: ini BUKAN jarak tempuh, dan tidak boleh dipakai untuk menghitung
     * tarif. Jarak tempuh datang dari OSRM yang mengikuti jalan sungguhan.
     *
     * Yang dipakai untuk: memeriksa radius geofence, menyaring kandidat driver
     * sebelum panggilan OSRM yang mahal, dan mendeteksi lompatan posisi yang
     * tidak mungkin.
     */
    public function distanceTo(self $other): float
    {
        // Radius bumi rata-rata menurut IUGG.
        $earthRadius = 6_371_008.8;

        $lat1 = deg2rad($this->lat);
        $lat2 = deg2rad($other->lat);
        $deltaLat = $lat2 - $lat1;
        $deltaLng = deg2rad($other->lng - $this->lng);

        $a = sin($deltaLat / 2) ** 2
            + cos($lat1) * cos($lat2) * sin($deltaLng / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public function isWithinMeters(self $other, float $meters): bool
    {
        return $this->distanceTo($other) <= $meters;
    }

    /**
     * Kecepatan tersirat dalam km/jam untuk berpindah ke titik lain dalam
     * rentang waktu tertentu.
     *
     * Dipakai deteksi fake GPS: motor yang berpindah dengan kecepatan tersirat
     * di atas 150 km/jam bukan sedang mengebut, tapi sedang memalsukan posisi.
     */
    public function impliedSpeedKmh(self $other, float $seconds): float
    {
        if ($seconds <= 0) {
            return INF;
        }

        return ($this->distanceTo($other) / $seconds) * 3.6;
    }

    /**
     * Arah dari titik ini ke titik lain, dalam derajat dari utara.
     *
     * Dipakai memutar ikon kendaraan di peta supaya menghadap arah jalannya.
     */
    public function bearingTo(self $other): float
    {
        $lat1 = deg2rad($this->lat);
        $lat2 = deg2rad($other->lat);
        $deltaLng = deg2rad($other->lng - $this->lng);

        $y = sin($deltaLng) * cos($lat2);
        $x = cos($lat1) * sin($lat2) - sin($lat1) * cos($lat2) * cos($deltaLng);

        return fmod(rad2deg(atan2($y, $x)) + 360, 360);
    }

    // -------------------------------------------------------------------------
    // Perbandingan & penyajian
    // -------------------------------------------------------------------------

    public function equals(self $other): bool
    {
        return abs($this->lat - $other->lat) < 1e-7
            && abs($this->lng - $other->lng) < 1e-7;
    }

    /**
     * Pasangan GeoJSON: [longitude, latitude].
     *
     * @return array{0: float, 1: float}
     */
    public function toGeoJsonPair(): array
    {
        return [$this->lng, $this->lat];
    }

    /**
     * Literal WKT untuk query PostGIS.
     *
     * Selalu dipakai bersama parameter binding, bukan disisipkan langsung ke
     * SQL. Nilainya sudah divalidasi jadi float, tapi kebiasaan menyisipkan
     * literal ke SQL adalah kebiasaan yang tidak boleh dibangun.
     */
    public function toWkt(): string
    {
        return sprintf('POINT(%.7F %.7F)', $this->lng, $this->lat);
    }

    public function __toString(): string
    {
        return sprintf('%.7F,%.7F', $this->lat, $this->lng);
    }

    /**
     * @return array{lat: float, lng: float}
     */
    public function jsonSerialize(): array
    {
        return ['lat' => $this->lat, 'lng' => $this->lng];
    }
}
