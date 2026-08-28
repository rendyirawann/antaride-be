<?php

declare(strict_types=1);

namespace Tests\Feature\Matching;

use App\Domain\Matching\Contracts\DriverLocationIndex;
use App\Domain\Matching\Exceptions\RedisGeoCommandException;
use App\Domain\Shared\ValueObjects\Coordinate;
use App\Infrastructure\Redis\Geo\RedisDriverLocationIndex;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Diuji terhadap Redis sungguhan, bukan mock.
 *
 * Alasannya: yang paling mungkin salah di class ini bukan logikanya, tapi
 * asumsinya tentang Redis — versi mana punya perintah apa, bentuk balasan
 * GEORADIUS, dan apakah prefix key ikut terpasang. Mock akan mengonfirmasi
 * asumsi saya sendiri, bukan mengujinya.
 *
 * Driver id di sini dimulai dari 900001 supaya tidak bertabrakan dengan data
 * pengembangan yang mungkin ada di Redis yang sama.
 */
class RedisDriverLocationIndexTest extends TestCase
{
    private RedisDriverLocationIndex $index;

    private const SERVICE = 'ride_bike';

    private const ZONE = 9001;

    /** @var array<int, int> */
    private const DRIVERS = [900001, 900002, 900003, 900004];

    protected function setUp(): void
    {
        parent::setUp();

        $this->index = new RedisDriverLocationIndex;
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Kontrak paling penting: key tanpa prefix
    // -------------------------------------------------------------------------

    /**
     * Key harus mentah, tanpa prefix Laravel.
     *
     * Ini kontrak antar service. Location service Go menulis `drv:loc:ride_bike`
     * langsung; kalau PHP membaca `antaride-database-drv:loc:ride_bike`, matching
     * akan selalu melaporkan tidak ada driver sementara app driver menunjukkan
     * dirinya online, dan tidak ada satu pun error yang muncul.
     *
     * Diperiksa dengan membaca key mentah lewat koneksi tanpa prefix, memakai
     * nama key yang persis seperti yang ditulis service Go.
     */
    public function test_key_ditulis_tanpa_prefix_laravel(): void
    {
        $this->index->record(
            self::SERVICE,
            900001,
            Coordinate::of(3.5952, 98.6722),
        );

        $raw = Redis::connection('shared');

        $this->assertSame(
            1,
            (int) $raw->exists('drv:meta:900001'),
            'Key drv:meta:900001 tidak ada dalam bentuk mentah. '
            .'Location service Go tidak akan pernah melihat data yang ditulis PHP.',
        );

        $this->assertGreaterThan(
            0,
            (int) $raw->zcard('drv:loc:'.self::SERVICE),
            'GEO set drv:loc:ride_bike kosong dalam bentuk mentah.',
        );
    }

    /**
     * Membuktikan sebaliknya: koneksi default MEMANG berprefix.
     *
     * Test ini mengunci alasan keberadaan koneksi 'shared'. Kalau suatu hari
     * prefix dihapus dari config dan koneksi shared dianggap tidak perlu lagi,
     * test ini yang gagal lebih dulu.
     */
    public function test_koneksi_default_memang_berprefix_sehingga_shared_dibutuhkan(): void
    {
        $this->assertNotSame(
            '',
            (string) config('database.redis.options.prefix'),
            'Prefix global sudah kosong; keberadaan koneksi shared perlu ditinjau.',
        );

        $this->assertSame(
            '',
            (string) config('database.redis.shared.options.prefix'),
            'Koneksi shared TIDAK boleh punya prefix.',
        );
    }

    // -------------------------------------------------------------------------
    // Pencarian radius
    // -------------------------------------------------------------------------

    public function test_menemukan_driver_dalam_radius_terdekat_lebih_dulu(): void
    {
        $center = Coordinate::of(3.5952, 98.6722);

        // Jarak dari pusat kira-kira: 0 m, 1 km, 3,3 km, 45 km.
        $this->index->record(self::SERVICE, 900001, $center);
        $this->index->record(self::SERVICE, 900002, Coordinate::of(3.6000, 98.6800));
        $this->index->record(self::SERVICE, 900003, Coordinate::of(3.6200, 98.6950));
        $this->index->record(self::SERVICE, 900004, Coordinate::of(3.9000, 99.0000));

        $found = $this->index->findNearby(self::SERVICE, $center, 5000);

        $ids = array_map(static fn ($p) => $p->driverId, $found);

        $this->assertSame(
            [900001, 900002, 900003],
            $ids,
            'Driver di luar radius ikut terbawa, atau urutan jaraknya salah.',
        );

        // Jarak harus menaik.
        $previous = -1.0;

        foreach ($found as $position) {
            $this->assertNotNull($position->distanceM, 'Jarak dari Redis tidak terbawa.');
            $this->assertGreaterThanOrEqual($previous, $position->distanceM);
            $previous = $position->distanceM;
        }
    }

    public function test_menghormati_batas_jumlah_hasil(): void
    {
        $center = Coordinate::of(3.5952, 98.6722);

        foreach (self::DRIVERS as $offset => $driverId) {
            $this->index->record(
                self::SERVICE,
                $driverId,
                Coordinate::of(3.5952 + ($offset * 0.001), 98.6722),
            );
        }

        $this->assertCount(2, $this->index->findNearby(self::SERVICE, $center, 50000, limit: 2));
    }

    public function test_radius_kosong_menghasilkan_array_kosong(): void
    {
        $this->index->record(self::SERVICE, 900001, Coordinate::of(3.9000, 99.0000));

        $found = $this->index->findNearby(
            self::SERVICE,
            Coordinate::of(3.5952, 98.6722),
            1000,
        );

        $this->assertSame([], $found);
    }

    /**
     * Metadata posisi punya TTL 60 detik. Driver yang metadatanya sudah hilang
     * tidak boleh ikut dikembalikan walaupun member GEO-nya masih ada.
     *
     * Ini kasus nyata: app driver mati mendadak tanpa mengirim offline. Member
     * GEO tidak punya TTL sendiri, jadi tanpa pemeriksaan ini order akan
     * ditawarkan ke ponsel yang sudah mati.
     */
    public function test_driver_tanpa_metadata_tidak_dikembalikan(): void
    {
        $center = Coordinate::of(3.5952, 98.6722);

        $this->index->record(self::SERVICE, 900001, $center);
        $this->index->record(self::SERVICE, 900002, Coordinate::of(3.5960, 98.6730));

        // Hapus metadata driver kedua, meniru TTL yang habis, tapi biarkan
        // member GEO-nya tetap ada.
        Redis::connection('shared')->del('drv:meta:900002');

        $found = $this->index->findNearby(self::SERVICE, $center, 5000);

        $this->assertSame(
            [900001],
            array_map(static fn ($p) => $p->driverId, $found),
        );
    }

    // -------------------------------------------------------------------------
    // Metadata posisi
    // -------------------------------------------------------------------------

    public function test_menyimpan_dan_membaca_metadata_lengkap(): void
    {
        $this->index->record(
            self::SERVICE,
            900001,
            Coordinate::of(3.5952, 98.6722),
            heading: 137.5,
            speedKmh: 42.0,
            accuracyM: 8.0,
            batteryPercent: 73,
        );

        $position = $this->index->positionOf(900001);

        $this->assertNotNull($position);
        $this->assertSame(900001, $position->driverId);
        $this->assertEqualsWithDelta(3.5952, $position->coordinate->lat, 0.0001);
        $this->assertEqualsWithDelta(137.5, $position->heading, 0.01);
        $this->assertEqualsWithDelta(42.0, $position->speedKmh, 0.01);
        $this->assertEqualsWithDelta(8.0, $position->accuracyM, 0.01);
        $this->assertSame(73, $position->batteryPercent);
        $this->assertFalse($position->lowQuality);
        $this->assertFalse($position->isStale());
        $this->assertTrue($position->isReliableForGeofence());
    }

    /**
     * Akurasi di atas 100 m tetap disimpan tapi ditandai low quality.
     *
     * Posisi seperti ini masih cukup untuk menampilkan driver bergerak di peta,
     * tapi tidak layak dipakai memutuskan "driver sudah tiba di titik jemput".
     */
    public function test_akurasi_buruk_ditandai_tapi_tetap_disimpan(): void
    {
        $this->index->record(
            self::SERVICE,
            900001,
            Coordinate::of(3.5952, 98.6722),
            accuracyM: 250.0,
        );

        $position = $this->index->positionOf(900001);

        $this->assertNotNull($position);
        $this->assertTrue($position->lowQuality);
        $this->assertFalse(
            $position->isReliableForGeofence(),
            'Posisi dengan akurasi 250 m tidak boleh dipakai konfirmasi geofence.',
        );
    }

    public function test_membaca_banyak_posisi_sekaligus(): void
    {
        foreach (self::DRIVERS as $offset => $driverId) {
            $this->index->record(
                self::SERVICE,
                $driverId,
                Coordinate::of(3.5952 + ($offset * 0.001), 98.6722),
            );
        }

        $positions = $this->index->positionsOf(self::DRIVERS);

        $this->assertCount(4, $positions);
    }

    public function test_posisi_driver_yang_tidak_ada_bernilai_null(): void
    {
        $this->assertNull($this->index->positionOf(900099));
        $this->assertSame([], $this->index->positionsOf([900098, 900099]));
        $this->assertSame([], $this->index->positionsOf([]));
    }

    // -------------------------------------------------------------------------
    // Ketersediaan
    // -------------------------------------------------------------------------

    public function test_menandai_dan_mencabut_ketersediaan(): void
    {
        $this->index->markAvailable(self::SERVICE, self::ZONE, 900001);
        $this->index->markAvailable(self::SERVICE, self::ZONE, 900002);

        $this->assertSame(2, $this->index->availableCount(self::SERVICE, self::ZONE));

        $ids = $this->index->availableDriverIds(self::SERVICE, [self::ZONE]);
        sort($ids);
        $this->assertSame([900001, 900002], $ids);

        $this->index->markUnavailable(self::SERVICE, self::ZONE, 900001);

        $this->assertSame(1, $this->index->availableCount(self::SERVICE, self::ZONE));
        $this->assertSame([900002], $this->index->availableDriverIds(self::SERVICE, [self::ZONE]));
    }

    /**
     * Radius matching sering melintasi batas zona, jadi ketersediaan dibaca
     * dari beberapa zona sekaligus dan hasilnya digabung tanpa duplikat.
     */
    public function test_menggabungkan_ketersediaan_dari_beberapa_zona(): void
    {
        $this->index->markAvailable(self::SERVICE, 9001, 900001);
        $this->index->markAvailable(self::SERVICE, 9002, 900002);

        // Driver yang terdaftar di dua zona tidak boleh muncul dua kali.
        $this->index->markAvailable(self::SERVICE, 9001, 900003);
        $this->index->markAvailable(self::SERVICE, 9002, 900003);

        $ids = $this->index->availableDriverIds(self::SERVICE, [9001, 9002]);
        sort($ids);

        $this->assertSame([900001, 900002, 900003], $ids);
    }

    /**
     * Driver yang offline harus tercabut dari SEMUA kombinasi layanan dan zona.
     *
     * Tanpa pelacakan pendaftaran, driver yang offline saat terdaftar di tiga
     * zona akan meninggalkan sisa di dua zona, dan order akan ditawarkan ke
     * orang yang sudah pulang.
     */
    public function test_mencabut_ketersediaan_di_seluruh_zona_dan_layanan(): void
    {
        $this->index->markAvailable('ride_bike', 9001, 900001);
        $this->index->markAvailable('ride_bike', 9002, 900001);
        $this->index->markAvailable('send', 9001, 900001);

        $this->index->markUnavailableEverywhere(900001);

        $this->assertSame(0, $this->index->availableCount('ride_bike', 9001));
        $this->assertSame(0, $this->index->availableCount('ride_bike', 9002));
        $this->assertSame(0, $this->index->availableCount('send', 9001));
        $this->assertSame([], $this->index->availableDriverIds('ride_bike', [9001, 9002]));
    }

    public function test_zona_kosong_menghasilkan_daftar_kosong(): void
    {
        $this->assertSame([], $this->index->availableDriverIds(self::SERVICE, []));
    }

    // -------------------------------------------------------------------------
    // Live map
    // -------------------------------------------------------------------------

    /**
     * Redis 5.0 tidak punya GEOSEARCH BYBOX, jadi kotaknya didekati lingkaran
     * lalu disaring. Yang diuji: tidak ada driver di dalam kotak yang terlewat,
     * dan tidak ada driver di luar kotak yang terbawa.
     */
    public function test_pencarian_kotak_menyaring_dengan_tepat(): void
    {
        $southWest = Coordinate::of(3.5900, 98.6700);
        $northEast = Coordinate::of(3.6000, 98.6800);

        // Di dalam kotak.
        $this->index->record(self::SERVICE, 900001, Coordinate::of(3.5950, 98.6750));
        $this->index->record(self::SERVICE, 900002, Coordinate::of(3.5905, 98.6705));

        // Di luar kotak tapi di dalam lingkaran pembungkusnya. Ini kasus yang
        // membuktikan penyaringan kotaknya benar-benar jalan.
        $this->index->record(self::SERVICE, 900003, Coordinate::of(3.6010, 98.6810));

        // Jauh di luar.
        $this->index->record(self::SERVICE, 900004, Coordinate::of(3.7000, 98.8000));

        $found = $this->index->findInBox(self::SERVICE, $southWest, $northEast);
        $ids = array_map(static fn ($p) => $p->driverId, $found);
        sort($ids);

        $this->assertSame([900001, 900002], $ids);
    }

    public function test_pencarian_kotak_menghormati_batas_marker(): void
    {
        $southWest = Coordinate::of(3.5900, 98.6700);
        $northEast = Coordinate::of(3.6000, 98.6800);

        foreach (self::DRIVERS as $offset => $driverId) {
            $this->index->record(
                self::SERVICE,
                $driverId,
                Coordinate::of(3.5950 + ($offset * 0.0001), 98.6750),
            );
        }

        $this->assertCount(
            2,
            $this->index->findInBox(self::SERVICE, $southWest, $northEast, limit: 2),
        );
    }

    // -------------------------------------------------------------------------
    // Salah konfigurasi harus gagal keras
    // -------------------------------------------------------------------------

    /**
     * GEOSEARCH pada Redis di bawah 6.2 harus melempar exception yang jelas,
     * BUKAN mengembalikan array kosong.
     *
     * Sudah diuji langsung bahwa Redis mengembalikan pesan error sebagai string
     * biasa untuk perintah yang tidak dikenal. Kalau string itu dibiarkan lolos,
     * akibatnya adalah matching yang melaporkan "tidak ada driver" pada setiap
     * order karena satu variabel env salah setel, tanpa satu pun baris log yang
     * menjelaskannya.
     */
    public function test_geosearch_pada_redis_lama_gagal_keras_bukan_diam(): void
    {
        $version = $this->redisVersion();

        if (version_compare($version, '6.2.0', '>=')) {
            $this->markTestSkipped("Redis {$version} mendukung GEOSEARCH, kasus ini tidak berlaku.");
        }

        config(['antaride.geo.redis_command' => 'geosearch']);

        $this->index->record(self::SERVICE, 900001, Coordinate::of(3.5952, 98.6722));

        $this->expectException(RedisGeoCommandException::class);
        $this->expectExceptionMessageMatches('/REDIS_GEO_COMMAND/');

        $this->index->findNearby(self::SERVICE, Coordinate::of(3.5952, 98.6722), 5000);
    }

    /**
     * Config harus sesuai versi Redis yang benar-benar berjalan.
     */
    public function test_config_perintah_geo_sesuai_versi_redis_yang_berjalan(): void
    {
        $version = $this->redisVersion();
        $configured = config('antaride.geo.redis_command');
        $expected = version_compare($version, '6.2.0', '>=') ? 'geosearch' : 'georadius';

        $this->assertSame(
            $expected,
            $configured,
            "Redis {$version} membutuhkan REDIS_GEO_COMMAND={$expected}, "
            ."tapi yang disetel {$configured}.",
        );
    }

    // -------------------------------------------------------------------------

    public function test_container_menyerahkan_implementasi_redis(): void
    {
        $this->assertInstanceOf(
            RedisDriverLocationIndex::class,
            $this->app->make(DriverLocationIndex::class),
        );
    }

    // -------------------------------------------------------------------------

    private function redisVersion(): string
    {
        $info = Redis::connection('shared')->info();

        return $info['redis_version']
            ?? $info['Server']['redis_version']
            ?? '0.0.0';
    }

    private function cleanUp(): void
    {
        $raw = Redis::connection('shared');

        foreach (self::DRIVERS as $driverId) {
            $raw->del("drv:meta:{$driverId}");
            $raw->del("drv:zones:{$driverId}");
        }

        foreach (['ride_bike', 'send', 'ride_car', 'food'] as $service) {
            $raw->del("drv:loc:{$service}");

            foreach ([9001, 9002] as $zone) {
                $raw->del("drv:available:{$service}:zone:{$zone}");
            }
        }
    }
}
