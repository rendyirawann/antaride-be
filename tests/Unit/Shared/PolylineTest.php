<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Domain\Shared\ValueObjects\Coordinate;
use App\Domain\Shared\ValueObjects\Polyline;
use Tests\TestCase;

class PolylineTest extends TestCase
{
    /**
     * Contoh resmi dari dokumentasi Google Encoded Polyline Algorithm Format.
     *
     * Diuji terhadap nilai referensi, bukan terhadap hasil implementasi sendiri.
     * Encoder yang diuji dengan decodernya sendiri akan lulus walaupun keduanya
     * salah dengan cara yang sama, dan yang gagal nanti adalah MapLibre di sisi
     * Flutter yang tidak bisa menggambar apa pun.
     */
    private const GOOGLE_REFERENCE_ENCODED = '_p~iF~ps|U_ulLnnqC_mqNvxq`@';

    private const GOOGLE_REFERENCE_POINTS = [
        [38.5, -120.2],
        [40.7, -120.95],
        [43.252, -126.453],
    ];

    public function test_encode_sesuai_referensi_resmi_google(): void
    {
        $polyline = Polyline::of(array_map(
            static fn (array $pair) => Coordinate::of($pair[0], $pair[1]),
            self::GOOGLE_REFERENCE_POINTS,
        ));

        $this->assertSame(self::GOOGLE_REFERENCE_ENCODED, $polyline->encode());
    }

    public function test_decode_sesuai_referensi_resmi_google(): void
    {
        $polyline = Polyline::decode(self::GOOGLE_REFERENCE_ENCODED);

        $this->assertCount(3, $polyline);

        foreach (self::GOOGLE_REFERENCE_POINTS as $index => [$lat, $lng]) {
            $this->assertEqualsWithDelta($lat, $polyline->points[$index]->lat, 0.00001);
            $this->assertEqualsWithDelta($lng, $polyline->points[$index]->lng, 0.00001);
        }
    }

    public function test_encode_decode_bolak_balik_mempertahankan_titik(): void
    {
        $original = Polyline::of([
            Coordinate::of(3.5952, 98.6722),
            Coordinate::of(3.6000, 98.6800),
            Coordinate::of(3.6100, 98.6900),
            Coordinate::of(3.6422, 98.8853),
        ]);

        $roundTrip = Polyline::decode($original->encode());

        $this->assertCount(4, $roundTrip);

        foreach ($original->points as $index => $point) {
            $this->assertTrue(
                $point->equals($roundTrip->points[$index]),
                "Titik indeks {$index} berubah setelah bolak-balik.",
            );
        }
    }

    public function test_polyline_kosong_menghasilkan_string_kosong(): void
    {
        $this->assertSame('', Polyline::empty()->encode());
        $this->assertTrue(Polyline::empty()->isEmpty());
        $this->assertSame(0.0, Polyline::empty()->lengthMeters());
    }

    public function test_decode_string_kosong_menghasilkan_polyline_kosong(): void
    {
        $this->assertTrue(Polyline::decode('')->isEmpty());
    }

    // -------------------------------------------------------------------------
    // Douglas-Peucker
    // -------------------------------------------------------------------------

    /**
     * Kasus nyata: perjalanan 20 menit dengan ping tiap 4 detik di jalan yang
     * relatif lurus menghasilkan 300 titik, hampir semuanya tidak menambah
     * informasi.
     */
    public function test_menyederhanakan_ping_gps_di_jalan_lurus(): void
    {
        $points = [];

        for ($i = 0; $i < 300; $i++) {
            // Garis lurus dengan derau GPS kecil, jauh di bawah toleransi.
            $points[] = Coordinate::of(
                3.5952 + ($i * 0.00008),
                98.6722 + ($i * 0.00010) + (($i % 3 - 1) * 0.0000005),
            );
        }

        $original = Polyline::of($points);
        $simplified = $original->simplified();

        $this->assertLessThan(
            30,
            $simplified->count(),
            'Jalan lurus 300 titik seharusnya tersederhanakan jauh di bawah 30 titik.',
        );

        // Bentuk garisnya tidak boleh berubah bermakna.
        $this->assertEqualsWithDelta(
            $original->lengthMeters(),
            $simplified->lengthMeters(),
            50.0,
            'Panjang garis berubah lebih dari 50 m setelah penyederhanaan.',
        );
    }

    /**
     * Titik pertama dan terakhir adalah titik jemput dan titik tujuan. Kalau
     * salah satunya terbuang, peta di halaman detail order akan menggambar rute
     * yang dimulai di tengah jalan.
     */
    public function test_titik_ujung_selalu_dipertahankan(): void
    {
        $points = [];

        for ($i = 0; $i < 100; $i++) {
            $points[] = Coordinate::of(3.5952 + ($i * 0.0001), 98.6722);
        }

        $original = Polyline::of($points);
        $simplified = $original->simplified();

        $this->assertTrue($simplified->first()->equals($original->first()));
        $this->assertTrue($simplified->last()->equals($original->last()));
    }

    /**
     * Tikungan tajam membawa informasi dan tidak boleh dibuang, kalau tidak
     * rute yang digambar akan memotong lewat gedung.
     *
     * Rutenya: jalan ke timur, berbelok tajam ke utara di titik sudut, lalu
     * lurus terus. Yang HARUS bertahan adalah titik sudutnya. Dua titik
     * setelahnya berada di garis lurus yang sama, jadi salah satunya memang
     * redundan dan benar kalau dibuang.
     */
    public function test_tikungan_tajam_tidak_dibuang(): void
    {
        $awal = Coordinate::of(3.5952, 98.6722);
        $sudut = Coordinate::of(3.5952, 98.6822);
        $tengah = Coordinate::of(3.6052, 98.6822);
        $akhir = Coordinate::of(3.6152, 98.6822);

        $simplified = Polyline::of([$awal, $sudut, $tengah, $akhir])->simplified();

        // Titik sudut wajib bertahan. Ini yang mencegah rute digambar sebagai
        // garis diagonal yang memotong lewat gedung.
        $retained = array_map(
            static fn (Coordinate $c) => (string) $c,
            $simplified->points,
        );

        $this->assertContains(
            (string) $sudut,
            $retained,
            'Titik sudut terbuang; rute yang digambar akan memotong diagonal.',
        );

        $this->assertTrue($simplified->first()->equals($awal));
        $this->assertTrue($simplified->last()->equals($akhir));

        // Titik tengah pada segmen lurus memang layak dibuang.
        $this->assertCount(3, $simplified);
    }

    /**
     * Membuktikan penyederhanaan benar-benar mengikuti bentuk, bukan sekadar
     * mengambil titik pertama dan terakhir.
     *
     * Zigzag tajam: setiap titik mengubah arah, jadi tidak ada satu pun yang
     * boleh dibuang.
     */
    public function test_zigzag_tajam_dipertahankan_seluruhnya(): void
    {
        $polyline = Polyline::of([
            Coordinate::of(3.5900, 98.6700),
            Coordinate::of(3.5950, 98.6800),
            Coordinate::of(3.5900, 98.6900),
            Coordinate::of(3.5950, 98.7000),
            Coordinate::of(3.5900, 98.7100),
        ]);

        $this->assertCount(5, $polyline->simplified());
    }

    public function test_polyline_dua_titik_tidak_berubah(): void
    {
        $polyline = Polyline::of([
            Coordinate::of(3.5952, 98.6722),
            Coordinate::of(3.6422, 98.8853),
        ]);

        $this->assertCount(2, $polyline->simplified());
    }

    /**
     * Implementasinya iteratif, bukan rekursif. Ini membuktikan tidak ada stack
     * overflow pada jumlah titik yang realistis untuk perjalanan lintas kota.
     */
    public function test_menangani_ribuan_titik_tanpa_stack_overflow(): void
    {
        $points = [];

        for ($i = 0; $i < 5000; $i++) {
            $points[] = Coordinate::of(
                3.5952 + ($i * 0.00002),
                98.6722 + (sin($i / 100) * 0.005),
            );
        }

        $simplified = Polyline::of($points)->simplified();

        $this->assertGreaterThan(2, $simplified->count());
        $this->assertLessThan(5000, $simplified->count());
    }

    // -------------------------------------------------------------------------

    public function test_menghitung_panjang_garis_dalam_meter(): void
    {
        $polyline = Polyline::of([
            Coordinate::of(3.5952, 98.6722),
            Coordinate::of(3.6422, 98.8853),
        ]);

        $km = $polyline->lengthMeters() / 1000;

        $this->assertGreaterThan(23.0, $km);
        $this->assertLessThan(25.0, $km);
    }

    public function test_menghasilkan_koordinat_geojson(): void
    {
        $polyline = Polyline::of([
            Coordinate::of(3.5952, 98.6722),
            Coordinate::of(3.6000, 98.6800),
        ]);

        $this->assertSame(
            [[98.6722, 3.5952], [98.68, 3.6]],
            $polyline->toGeoJsonCoordinates(),
        );
    }
}
