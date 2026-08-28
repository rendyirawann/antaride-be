<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Contracts\ZoneResolver;
use App\Domain\Catalog\Models\Zone;
use App\Domain\Shared\ValueObjects\Coordinate;
use App\Infrastructure\Geo\NativeZoneResolver;
use App\Infrastructure\Geo\PostGisZoneResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Test kontrak: PostGisZoneResolver dan NativeZoneResolver WAJIB memberi
 * jawaban identik.
 *
 * Kenapa ini penting. Dua implementasi ada supaya pengembangan tidak terhalang
 * saat PostGIS belum terpasang. Tapi dua implementasi juga berarti dua
 * kemungkinan jawaban, dan yang menentukan tarif adalah zona yang dikembalikan.
 *
 * Kalau keduanya menyimpang, gejalanya adalah ongkos yang berbeda antara
 * lingkungan pengembangan dan produksi untuk titik yang sama. Itu jenis
 * perbedaan yang tidak pernah ditemukan lewat pengujian manual, karena tidak
 * ada yang membandingkan dua lingkungan pada koordinat yang sama persis.
 *
 * Yang diuji bukan hanya "keduanya menemukan zona", tapi juga menemukan zona
 * YANG SAMA saat titik masuk dua zona bertumpang tindih.
 */
class ZoneResolverContractTest extends TestCase
{
    use RefreshDatabase;

    private PostGisZoneResolver $postgis;

    private NativeZoneResolver $native;

    protected function setUp(): void
    {
        parent::setUp();

        $this->postgis = new PostGisZoneResolver;
        $this->native = new NativeZoneResolver;
        $this->native->flushCache();

        $this->seedZones();
    }

    /**
     * Titik-titik uji yang mencakup kasus yang berbeda sifatnya.
     *
     * @return array<string, array{0: float, 1: float}>
     */
    public static function zonePoints(): array
    {
        return [
            // Di dalam satu zona saja.
            'tengah zona kota' => [3.5800, 98.6900],

            // Di dalam dua zona yang bertumpang tindih. Ini kasus yang paling
            // mungkin membuat dua resolver berbeda pilihan.
            'irisan kota dan baru' => [3.5952, 98.6700],

            // Persis di titik sudut polygon.
            'sudut polygon' => [3.5680, 98.6550],

            // Persis di sisi polygon.
            'di sisi utara' => [3.6050, 98.6800],

            // Di luar semua zona, dekat batas.
            'sedikit di luar' => [3.5670, 98.6900],

            // Jauh di luar.
            'jauh di luar' => [3.9000, 99.5000],

            // Di luar Indonesia.
            'london' => [51.5074, -0.1278],
        ];
    }

    #[DataProvider('zonePoints')]
    public function test_kedua_resolver_memilih_zona_yang_sama(float $lat, float $lng): void
    {
        $point = Coordinate::of($lat, $lng);

        $fromPostgis = $this->postgis->resolve($point);
        $fromNative = $this->native->resolve($point);

        $this->assertSame(
            $fromPostgis?->id,
            $fromNative?->id,
            sprintf(
                "Dua resolver memilih zona berbeda untuk (%.4f, %.4f).\n"
                ."  postgis: %s\n  native : %s\n\n"
                .'Ini berarti ongkos berbeda antara lingkungan yang memakai PostGIS '
                .'dan yang memakai native.',
                $lat,
                $lng,
                $fromPostgis?->code ?? 'null',
                $fromNative?->code ?? 'null',
            ),
        );
    }

    #[DataProvider('zonePoints')]
    public function test_kedua_resolver_setuju_soal_kelayakan_layanan(float $lat, float $lng): void
    {
        $point = Coordinate::of($lat, $lng);

        $this->assertSame(
            $this->postgis->isServiceable($point),
            $this->native->isServiceable($point),
            sprintf('Beda pendapat soal kelayakan untuk (%.4f, %.4f).', $lat, $lng),
        );
    }

    #[DataProvider('zonePoints')]
    public function test_kedua_resolver_menghasilkan_daftar_zona_yang_sama(float $lat, float $lng): void
    {
        $point = Coordinate::of($lat, $lng);

        $fromPostgis = array_map(
            static fn (Zone $z) => $z->id,
            $this->postgis->resolveAll($point),
        );

        $fromNative = array_map(
            static fn (Zone $z) => $z->id,
            $this->native->resolveAll($point),
        );

        // Urutannya juga harus sama, bukan hanya isinya. Yang dipakai sebagai
        // zona penentu tarif adalah elemen pertama.
        $this->assertSame(
            $fromPostgis,
            $fromNative,
            sprintf('Urutan atau isi daftar zona berbeda untuk (%.4f, %.4f).', $lat, $lng),
        );
    }

    /**
     * Ratusan titik acak di sekitar Medan.
     *
     * Test dengan titik yang dipilih tangan hanya menguji kasus yang sudah saya
     * pikirkan. Yang ini menguji yang belum.
     */
    public function test_kedua_resolver_sepakat_pada_lima_ratus_titik_acak(): void
    {
        $disagreements = [];

        for ($i = 0; $i < 500; $i++) {
            // Rentang yang sengaja lebih luas dari zona, supaya banyak titik
            // jatuh di luar dan di dekat batas.
            $lat = 3.5500 + (mt_rand(0, 100000) / 1000000);
            $lng = 98.6300 + (mt_rand(0, 120000) / 1000000);

            $point = Coordinate::of($lat, $lng);

            $a = $this->postgis->resolve($point)?->id;
            $b = $this->native->resolve($point)?->id;

            if ($a !== $b) {
                $disagreements[] = sprintf(
                    '(%.6f, %.6f): postgis=%s native=%s',
                    $lat,
                    $lng,
                    $a ?? 'null',
                    $b ?? 'null',
                );
            }
        }

        $this->assertSame(
            [],
            $disagreements,
            'Dua resolver berbeda pada '.count($disagreements)." dari 500 titik:\n  "
            .implode("\n  ", array_slice($disagreements, 0, 10)),
        );
    }

    /**
     * Titik persis di batas zona HARUS dinyatakan di dalam.
     *
     * Ini keputusan bisnis yang ditemukan justru oleh test kontrak ini.
     * `ST_Contains` PostGIS mengembalikan false untuk titik di batas, sesuai
     * definisi OGC. Tapi zona digambar mengikuti jalan, dan alamat hasil
     * geocoding menempel ke sumbu jalan, jadi titik yang jatuh tepat di garis
     * batas pasti terjadi.
     *
     * Kalau batas dianggap di luar, akibatnya bukan cuma satu alamat ditolak:
     * titik di perbatasan dua zona akan ditolak oleh KEDUANYA, dan pelanggan
     * mendapat pesan "di luar area layanan" padahal dikelilingi area terlayani.
     *
     * Karena itu keduanya memakai semantik ST_Covers.
     */
    public function test_titik_di_batas_zona_dinyatakan_di_dalam(): void
    {
        $boundaryPoints = [
            'sudut barat daya' => [3.5680, 98.6550],
            'sudut timur laut' => [3.6050, 98.7000],
            'tengah sisi selatan' => [3.5680, 98.6800],
            'tengah sisi barat' => [3.5900, 98.6550],
        ];

        foreach ($boundaryPoints as $label => [$lat, $lng]) {
            $point = Coordinate::of($lat, $lng);

            $this->assertNotNull(
                $this->postgis->resolve($point),
                "PostGIS menolak titik di batas ({$label}). Alamat di garis batas akan gagal dilayani.",
            );

            $this->assertNotNull(
                $this->native->resolve($point),
                "Native menolak titik di batas ({$label}).",
            );

            $this->assertTrue($this->postgis->isServiceable($point), $label);
            $this->assertTrue($this->native->isServiceable($point), $label);
        }
    }

    /**
     * Toleransi batas tidak boleh sampai menarik titik yang benar-benar di luar.
     *
     * Toleransi native 1e-9 derajat, sekitar 0,1 mm. Titik 1 meter di luar
     * batas harus tetap di luar, di kedua implementasi.
     */
    public function test_titik_semeter_di_luar_batas_tetap_di_luar(): void
    {
        // Zona kota batas selatannya lat 3.5680. Satu meter ke selatan kira-kira
        // 0.000009 derajat.
        $justOutside = Coordinate::of(3.5679900, 98.6800);

        $this->assertNull($this->postgis->resolve($justOutside));
        $this->assertNull($this->native->resolve($justOutside));
    }

    /**
     * Zona berprioritas lebih tinggi harus menang, di kedua implementasi.
     *
     * Ini yang membuat zona bandara di dalam zona kota bisa punya tarif sendiri.
     */
    public function test_prioritas_menentukan_zona_yang_dipilih(): void
    {
        // Zona kecil berprioritas tinggi, seluruhnya di dalam zona kota.
        $this->createZone(
            code: 'MDN-BANDARA',
            priority: 50,
            ring: [
                [98.6800, 3.5850],
                [98.6900, 3.5850],
                [98.6900, 3.5950],
                [98.6800, 3.5950],
                [98.6800, 3.5850],
            ],
        );

        $this->native->flushCache();

        $inside = Coordinate::of(3.5900, 98.6850);

        $postgisChoice = $this->postgis->resolve($inside);
        $nativeChoice = $this->native->resolve($inside);

        $this->assertSame('MDN-BANDARA', $postgisChoice?->code);
        $this->assertSame('MDN-BANDARA', $nativeChoice?->code);

        // Dan daftar lengkapnya memuat keduanya, prioritas tinggi lebih dulu.
        $all = $this->postgis->resolveAll($inside);
        $this->assertGreaterThanOrEqual(2, count($all));
        $this->assertSame('MDN-BANDARA', $all[0]->code);
    }

    /**
     * Zona tidak aktif tidak boleh ikut dipertimbangkan oleh keduanya.
     */
    public function test_zona_tidak_aktif_diabaikan_kedua_resolver(): void
    {
        Zone::query()->update(['is_active' => false]);
        $this->native->flushCache();

        $point = Coordinate::of(3.5800, 98.6900);

        $this->assertNull($this->postgis->resolve($point));
        $this->assertNull($this->native->resolve($point));
        $this->assertFalse($this->postgis->isServiceable($point));
        $this->assertFalse($this->native->isServiceable($point));
    }

    /**
     * Container harus menyerahkan implementasi yang sesuai config.
     */
    public function test_container_memilih_implementasi_sesuai_config(): void
    {
        config(['antaride.geo.zone_driver' => 'postgis']);
        $this->assertInstanceOf(PostGisZoneResolver::class, $this->app->make(ZoneResolver::class));

        // Binding singleton, jadi harus dilupakan dulu supaya dibangun ulang.
        $this->app->forgetInstance(ZoneResolver::class);

        config(['antaride.geo.zone_driver' => 'native']);
        $this->assertInstanceOf(NativeZoneResolver::class, $this->app->make(ZoneResolver::class));
    }

    // -------------------------------------------------------------------------

    private function seedZones(): void
    {
        // Dua zona yang sengaja bertumpang tindih, meniru bentuk seeder
        // sungguhan untuk Medan.
        $this->createZone('MDN-KOTA', 10, [
            [98.6550, 3.5680],
            [98.7000, 3.5680],
            [98.7000, 3.6050],
            [98.6550, 3.6050],
            [98.6550, 3.5680],
        ]);

        $this->createZone('MDN-BARU', 10, [
            [98.6450, 3.5850],
            [98.6760, 3.5850],
            [98.6760, 3.6180],
            [98.6450, 3.6180],
            [98.6450, 3.5850],
        ]);
    }

    /**
     * @param  array<int, array{0: float, 1: float}>  $ring
     */
    private function createZone(string $code, int $priority, array $ring): void
    {
        $lngs = array_column($ring, 0);
        $lats = array_column($ring, 1);

        // INSERT lewat query builder, bukan Eloquent, supaya trigger database
        // yang mengisi kolom geometry ikut teruji di jalur yang sama dengan
        // panel admin.
        DB::table('zones')->insert([
            'uuid' => (string) Str::uuid7(),
            'name' => $code,
            'code' => $code,
            'city' => 'Medan',
            'province' => 'Sumatera Utara',
            'polygon_geojson' => json_encode([
                'type' => 'Polygon',
                'coordinates' => [$ring],
            ], JSON_THROW_ON_ERROR),
            'min_lat' => min($lats),
            'max_lat' => max($lats),
            'min_lng' => min($lngs),
            'max_lng' => max($lngs),
            'center_lat' => round((min($lats) + max($lats)) / 2, 7),
            'center_lng' => round((min($lngs) + max($lngs)) / 2, 7),
            'is_active' => true,
            'priority' => $priority,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
