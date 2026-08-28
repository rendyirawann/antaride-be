<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Domain\Shared\Exceptions\InvalidCoordinateException;
use App\Domain\Shared\ValueObjects\Coordinate;
use Tests\TestCase;

class CoordinateTest extends TestCase
{
    /**
     * Jarak Lapangan Merdeka Medan ke Bandara Kualanamu.
     *
     * Jarak garis lurus sebenarnya sekitar 23-24 km. Toleransi 500 m dipakai
     * karena yang diuji adalah kebenaran rumus haversine, bukan ketepatan
     * koordinat referensi yang saya masukkan.
     */
    public function test_menghitung_jarak_haversine_dengan_benar(): void
    {
        $merdeka = Coordinate::of(3.5952, 98.6722);
        $kualanamu = Coordinate::of(3.6422, 98.8853);

        $km = $merdeka->distanceTo($kualanamu) / 1000;

        $this->assertGreaterThan(23.0, $km);
        $this->assertLessThan(25.0, $km);
    }

    public function test_jarak_ke_titik_yang_sama_adalah_nol(): void
    {
        $point = Coordinate::of(3.5952, 98.6722);

        $this->assertSame(0.0, $point->distanceTo($point));
    }

    public function test_jarak_bersifat_simetris(): void
    {
        $a = Coordinate::of(3.5952, 98.6722);
        $b = Coordinate::of(3.6422, 98.8853);

        $this->assertEqualsWithDelta($a->distanceTo($b), $b->distanceTo($a), 0.001);
    }

    public function test_menolak_latitude_di_luar_rentang(): void
    {
        $this->expectException(InvalidCoordinateException::class);

        Coordinate::of(91.0, 98.6722);
    }

    public function test_menolak_longitude_di_luar_rentang(): void
    {
        $this->expectException(InvalidCoordinateException::class);

        Coordinate::of(3.5952, 181.0);
    }

    public function test_mengenali_titik_di_dalam_indonesia(): void
    {
        $this->assertTrue(Coordinate::of(3.5952, 98.6722)->isWithinIndonesia());
        $this->assertTrue(Coordinate::of(-6.2088, 106.8456)->isWithinIndonesia());
    }

    public function test_mengenali_titik_di_luar_indonesia(): void
    {
        // London
        $this->assertFalse(Coordinate::of(51.5074, -0.1278)->isWithinIndonesia());

        // Sydney
        $this->assertFalse(Coordinate::of(-33.8688, 151.2093)->isWithinIndonesia());

        // Dubai
        $this->assertFalse(Coordinate::of(25.2048, 55.2708)->isWithinIndonesia());
    }

    /**
     * Bounding box bukan pemeriksa batas negara, dan test ini mengunci
     * keterbatasan itu supaya tidak ada yang salah mengira sebaliknya.
     *
     * Persegi yang memuat Aceh sampai Papua secara geometris tidak mungkin
     * mengecualikan negara tetangga. Keputusan "titik ini dilayani atau tidak"
     * adalah tugas ZoneResolver yang menguji polygon zona sungguhan, bukan
     * tugas method ini.
     */
    public function test_bounding_box_memang_meloloskan_negara_tetangga(): void
    {
        // Singapura, Brunei, dan Timor Leste semuanya berada di dalam persegi
        // yang memuat Indonesia. Ini perilaku yang diharapkan, bukan bug.
        $this->assertTrue(Coordinate::of(1.3521, 103.8198)->isWithinIndonesia(), 'Singapura');
        $this->assertTrue(Coordinate::of(4.5353, 114.7277)->isWithinIndonesia(), 'Brunei');
        $this->assertTrue(Coordinate::of(-8.5569, 125.5603)->isWithinIndonesia(), 'Timor Leste');
    }

    public function test_mengenali_titik_nol_dari_gps_yang_belum_siap(): void
    {
        $this->assertTrue(Coordinate::of(0.0, 0.0)->isNullIsland());
        $this->assertFalse(Coordinate::of(3.5952, 98.6722)->isNullIsland());
    }

    /**
     * Deteksi fake GPS: lompatan 24 km dalam 10 detik.
     *
     * Kecepatan tersirat sekitar 8.500 km/jam, jauh di atas ambang 150 km/jam.
     * Ini bukan motor yang mengebut, ini posisi yang dipalsukan.
     */
    public function test_mendeteksi_lompatan_posisi_yang_tidak_mungkin(): void
    {
        $merdeka = Coordinate::of(3.5952, 98.6722);
        $kualanamu = Coordinate::of(3.6422, 98.8853);

        $speed = $merdeka->impliedSpeedKmh($kualanamu, 10);
        $threshold = (float) config('antaride.gps.max_speed_kmh');

        $this->assertGreaterThan($threshold, $speed);
    }

    public function test_kecepatan_wajar_untuk_motor_dalam_kota_lolos(): void
    {
        $a = Coordinate::of(3.5952, 98.6722);

        // Sekitar 44 m dalam 4 detik, kira-kira 40 km/jam.
        $b = Coordinate::of(3.5956, 98.6722);

        $speed = $a->impliedSpeedKmh($b, 4);
        $threshold = (float) config('antaride.gps.max_speed_kmh');

        $this->assertLessThan($threshold, $speed);
    }

    public function test_selisih_waktu_nol_menghasilkan_kecepatan_tak_hingga(): void
    {
        $a = Coordinate::of(3.5952, 98.6722);
        $b = Coordinate::of(3.6000, 98.6800);

        $this->assertSame(INF, $a->impliedSpeedKmh($b, 0));
    }

    /**
     * Urutan GeoJSON adalah [lng, lat], terbalik dari kebiasaan menyebut.
     */
    public function test_pasangan_geojson_memakai_urutan_lng_lat(): void
    {
        $point = Coordinate::of(3.5952, 98.6722);

        $this->assertSame([98.6722, 3.5952], $point->toGeoJsonPair());

        $roundTrip = Coordinate::fromGeoJsonPair([98.6722, 3.5952]);

        $this->assertTrue($point->equals($roundTrip));
    }

    public function test_wkt_memakai_urutan_lng_lat_untuk_postgis(): void
    {
        $point = Coordinate::of(3.5952, 98.6722);

        $this->assertSame('POINT(98.6722000 3.5952000)', $point->toWkt());
    }

    public function test_presisi_dipatok_tujuh_desimal(): void
    {
        $point = Coordinate::of(3.59521234567890, 98.67221234567890);

        $this->assertSame(3.5952123, $point->lat);
        $this->assertSame(98.6722123, $point->lng);
    }

    public function test_bearing_ke_utara_adalah_nol_derajat(): void
    {
        $a = Coordinate::of(3.5952, 98.6722);
        $b = Coordinate::of(3.6952, 98.6722);

        $this->assertEqualsWithDelta(0.0, $a->bearingTo($b), 0.5);
    }

    public function test_bearing_ke_timur_adalah_sembilan_puluh_derajat(): void
    {
        $a = Coordinate::of(3.5952, 98.6722);
        $b = Coordinate::of(3.5952, 98.7722);

        $this->assertEqualsWithDelta(90.0, $a->bearingTo($b), 0.5);
    }
}
