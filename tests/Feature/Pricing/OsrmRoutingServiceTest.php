<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Domain\Pricing\Contracts\RoutingService;
use App\Domain\Pricing\Exceptions\RoutingUnavailableException;
use App\Domain\Shared\ValueObjects\Coordinate;
use App\Infrastructure\Routing\OsrmRoutingService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Diuji dengan HTTP palsu, bukan OSRM sungguhan.
 *
 * Berbeda dari adapter Redis dan PostGIS yang diuji terhadap server nyata,
 * OSRM belum terpasang dan memasangnya butuh data OSM Indonesia berukuran
 * gigabyte. Yang bisa dan perlu diuji tanpa itu adalah bagian yang paling
 * mungkin salah: bentuk URL yang dikirim (urutan lng,lat), pembacaan balasan,
 * dan perilaku saat gagal.
 *
 * Balasan palsu di bawah mengikuti bentuk balasan OSRM v5 yang sebenarnya.
 */
class OsrmRoutingServiceTest extends TestCase
{
    private OsrmRoutingService $routing;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.osrm.url' => 'http://127.0.0.1:5000']);
        $this->routing = new OsrmRoutingService;
    }

    // -------------------------------------------------------------------------
    // Bentuk permintaan
    // -------------------------------------------------------------------------

    /**
     * OSRM menerima koordinat dalam urutan lng,lat.
     *
     * Tertukar di sini berarti rute dihitung dari lokasi yang sama sekali lain
     * tanpa error apa pun: (3.5952, 98.6722) sebagai lng,lat menempatkan titik
     * di Samudra Hindia. Tarifnya akan keluar, hanya salah.
     */
    public function test_mengirim_koordinat_dalam_urutan_lng_lat(): void
    {
        Http::fake([
            '*' => Http::response($this->routeResponse(), 200),
        ]);

        $this->routing->route(
            Coordinate::of(3.5952, 98.6722),
            Coordinate::of(3.6422, 98.8853),
        );

        Http::assertSent(function ($request) {
            // lng dulu, lalu lat: 98.672200,3.595200;98.885300,3.642200
            $this->assertStringContainsString('98.672200,3.595200', $request->url());
            $this->assertStringContainsString('98.885300,3.642200', $request->url());

            // Dan TIDAK boleh ada urutan yang terbalik.
            $this->assertStringNotContainsString('3.595200,98.672200', $request->url());

            return true;
        });
    }

    public function test_meminta_polyline_terenkode_bukan_geojson(): void
    {
        Http::fake(['*' => Http::response($this->routeResponse(), 200)]);

        $this->routing->route(
            Coordinate::of(3.5952, 98.6722),
            Coordinate::of(3.6422, 98.8853),
        );

        Http::assertSent(function ($request) {
            $this->assertStringContainsString('geometries=polyline', $request->url());
            $this->assertStringContainsString('overview=full', $request->url());

            return true;
        });
    }

    public function test_memakai_profil_dari_config(): void
    {
        config(['services.osrm.profile' => 'bike']);
        Http::fake(['*' => Http::response($this->routeResponse(), 200)]);

        (new OsrmRoutingService)->route(
            Coordinate::of(3.5952, 98.6722),
            Coordinate::of(3.6422, 98.8853),
        );

        Http::assertSent(fn ($request) => str_contains($request->url(), '/route/v1/bike/'));
    }

    // -------------------------------------------------------------------------
    // Pembacaan balasan
    // -------------------------------------------------------------------------

    public function test_membaca_jarak_durasi_dan_polyline(): void
    {
        Http::fake(['*' => Http::response($this->routeResponse(
            distance: 8432.7,
            duration: 1265.4,
        ), 200)]);

        $result = $this->routing->route(
            Coordinate::of(3.5952, 98.6722),
            Coordinate::of(3.6422, 98.8853),
        );

        // Dibulatkan ke ATAS, supaya tarif tidak pernah kurang dari jarak
        // sebenarnya.
        $this->assertSame(8433, $result->distanceMeters);
        $this->assertSame(1266, $result->durationSeconds);

        $this->assertSame(8.43, $result->distanceKm());
        $this->assertSame(22, $result->durationMinutes());

        $this->assertFalse($result->polyline->isEmpty());
        $this->assertFalse($result->isEstimated);
    }

    public function test_balasan_tanpa_geometry_menghasilkan_polyline_kosong(): void
    {
        Http::fake(['*' => Http::response([
            'code' => 'Ok',
            'routes' => [['distance' => 1000.0, 'duration' => 200.0]],
        ], 200)]);

        $result = $this->routing->route(
            Coordinate::of(3.5952, 98.6722),
            Coordinate::of(3.6000, 98.6800),
        );

        $this->assertTrue($result->polyline->isEmpty());
        $this->assertSame(1000, $result->distanceMeters);
    }

    // -------------------------------------------------------------------------
    // Multi-perhentian
    // -------------------------------------------------------------------------

    public function test_rute_multi_perhentian_mengirim_semua_titik_berurutan(): void
    {
        Http::fake(['*' => Http::response($this->routeResponse(), 200)]);

        $this->routing->routeVia([
            Coordinate::of(3.5952, 98.6722),
            Coordinate::of(3.6000, 98.6800),
            Coordinate::of(3.6422, 98.8853),
        ]);

        Http::assertSent(function ($request) {
            $url = $request->url();

            // Tiga titik, dua titik koma pemisah di bagian path.
            $path = parse_url($url, PHP_URL_PATH) ?? '';
            $this->assertSame(2, substr_count($path, ';'));

            return true;
        });
    }

    public function test_rute_dengan_kurang_dari_dua_titik_ditolak(): void
    {
        $this->expectException(RoutingUnavailableException::class);

        $this->routing->routeVia([Coordinate::of(3.5952, 98.6722)]);
    }

    // -------------------------------------------------------------------------
    // Tabel durasi
    // -------------------------------------------------------------------------

    /**
     * Matching butuh ETA beberapa kandidat ke satu titik jemput. Ini harus SATU
     * panggilan, bukan satu per driver.
     */
    public function test_durasi_banyak_titik_asal_dalam_satu_panggilan(): void
    {
        Http::fake(['*' => Http::response([
            'code' => 'Ok',
            'durations' => [[120.4], [305.9], [88.1]],
        ], 200)]);

        $durations = $this->routing->durationsTo(
            [
                Coordinate::of(3.5952, 98.6722),
                Coordinate::of(3.6000, 98.6800),
                Coordinate::of(3.5900, 98.6700),
            ],
            Coordinate::of(3.5960, 98.6730),
        );

        $this->assertSame([121, 306, 89], $durations);

        // Satu permintaan HTTP saja, bukan tiga.
        Http::assertSentCount(1);
    }

    /**
     * OSRM mengembalikan null untuk titik yang tidak terhubung ke jaringan
     * jalan, misalnya koordinat di tengah danau. Itu bukan error; kandidat itu
     * memang harus dilewati matching.
     */
    public function test_titik_yang_tidak_terhubung_jalan_menghasilkan_null(): void
    {
        Http::fake(['*' => Http::response([
            'code' => 'Ok',
            'durations' => [[120.0], [null], [88.0]],
        ], 200)]);

        $durations = $this->routing->durationsTo(
            [
                Coordinate::of(3.5952, 98.6722),
                Coordinate::of(3.6000, 98.6800),
                Coordinate::of(3.5900, 98.6700),
            ],
            Coordinate::of(3.5960, 98.6730),
        );

        $this->assertSame([120, null, 88], $durations);
    }

    public function test_titik_asal_kosong_menghasilkan_array_kosong(): void
    {
        $durations = $this->routing->durationsTo([], Coordinate::of(3.5952, 98.6722));

        $this->assertSame([], $durations);
        Http::assertNothingSent();
    }

    public function test_tabel_durasi_meminta_hanya_satu_tujuan(): void
    {
        Http::fake(['*' => Http::response([
            'code' => 'Ok',
            'durations' => [[100.0], [200.0]],
        ], 200)]);

        $this->routing->durationsTo(
            [Coordinate::of(3.5952, 98.6722), Coordinate::of(3.6000, 98.6800)],
            Coordinate::of(3.5960, 98.6730),
        );

        Http::assertSent(function ($request) {
            $this->assertStringContainsString('sources=0%3B1', $request->url());
            $this->assertStringContainsString('destinations=2', $request->url());

            return true;
        });
    }

    // -------------------------------------------------------------------------
    // Kegagalan
    // -------------------------------------------------------------------------

    /**
     * Tidak ada fallback ke haversine, dan itu keputusan yang disengaja.
     *
     * Jarak garis lurus di kota dengan jalan satu arah dan sungai bisa setengah
     * dari jarak tempuh sebenarnya. Fallback semacam itu bukan degradasi
     * layanan, tapi menagih pengguna setengah harga tanpa ada yang tahu.
     */
    public function test_osrm_mati_melempar_exception_bukan_menebak_jarak(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $this->expectException(RoutingUnavailableException::class);

        $this->routing->route(
            Coordinate::of(3.5952, 98.6722),
            Coordinate::of(3.6422, 98.8853),
        );
    }

    public function test_kode_no_route_melempar_exception_dengan_pesan_yang_jelas(): void
    {
        Http::fake(['*' => Http::response(['code' => 'NoRoute'], 200)]);

        $this->expectException(RoutingUnavailableException::class);
        $this->expectExceptionMessageMatches('/tidak ditemukan rute jalan/i');

        $this->routing->route(
            Coordinate::of(3.5952, 98.6722),
            Coordinate::of(-8.5000, 120.0000),
        );
    }

    public function test_kode_bukan_ok_melempar_exception(): void
    {
        Http::fake(['*' => Http::response(['code' => 'InvalidQuery'], 200)]);

        $this->expectException(RoutingUnavailableException::class);
        $this->expectExceptionMessageMatches('/InvalidQuery/');

        $this->routing->route(
            Coordinate::of(3.5952, 98.6722),
            Coordinate::of(3.6422, 98.8853),
        );
    }

    public function test_balasan_tanpa_rute_melempar_exception(): void
    {
        Http::fake(['*' => Http::response(['code' => 'Ok', 'routes' => []], 200)]);

        $this->expectException(RoutingUnavailableException::class);

        $this->routing->route(
            Coordinate::of(3.5952, 98.6722),
            Coordinate::of(3.6422, 98.8853),
        );
    }

    /**
     * Health check tidak boleh melempar. Dia mengembalikan false, karena yang
     * memanggilnya adalah perintah pemeriksaan, bukan jalur permintaan.
     */
    public function test_pemeriksaan_ketersediaan_tidak_melempar(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $this->assertFalse($this->routing->isAvailable());
    }

    public function test_pemeriksaan_ketersediaan_true_saat_osrm_sehat(): void
    {
        Http::fake(['*' => Http::response($this->routeResponse(), 200)]);

        $this->assertTrue($this->routing->isAvailable());
    }

    // -------------------------------------------------------------------------

    public function test_container_menyerahkan_implementasi_osrm(): void
    {
        $this->assertInstanceOf(OsrmRoutingService::class, $this->app->make(RoutingService::class));
    }

    // -------------------------------------------------------------------------

    /**
     * Bentuk balasan OSRM v5 yang sebenarnya.
     *
     * @return array<string, mixed>
     */
    private function routeResponse(float $distance = 24100.5, float $duration = 1800.0): array
    {
        return [
            'code' => 'Ok',
            'routes' => [
                [
                    'distance' => $distance,
                    'duration' => $duration,
                    // Polyline terenkode yang sah.
                    'geometry' => '_p~iF~ps|U_ulLnnqC_mqNvxq`@',
                    'legs' => [],
                    'weight' => $duration,
                    'weight_name' => 'routability',
                ],
            ],
            'waypoints' => [],
        ];
    }
}
