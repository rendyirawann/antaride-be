<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Domain\Matching\Contracts\DriverLocationIndex;
use App\Domain\Pricing\Actions\CreateQuote;
use App\Domain\Pricing\Contracts\QuoteStore;
use App\Domain\Pricing\Contracts\RoutingService;
use App\Domain\Pricing\DTOs\RouteResult;
use App\Domain\Pricing\Exceptions\OutsideServiceAreaException;
use App\Domain\Pricing\Exceptions\RoutingUnavailableException;
use App\Domain\Shared\ValueObjects\Coordinate;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\SystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Alur pembuatan quote, dari titik jemput sampai quote tersimpan di Redis.
 *
 * OSRM dipalsukan (belum terpasang, butuh data OSM bergigabyte). Zona, tarif,
 * surge, promo, indeks driver, dan penyimpanan quote semuanya nyata: PostgreSQL
 * dan Redis sungguhan.
 */
class CreateQuoteTest extends TestCase
{
    use RefreshDatabase;

    private CreateQuote $action;

    private DriverLocationIndex $driverIndex;

    private QuoteStore $quoteStore;

    /** Lapangan Merdeka Medan, di dalam zona MDN-KOTA hasil seeder. */
    private Coordinate $pickup;

    private Coordinate $destination;

    /** @var array<int, int> */
    private const DRIVERS = [900101, 900102, 900103];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSeeder::class);
        $this->seed(CatalogSeeder::class);

        $this->action = app(CreateQuote::class);
        $this->driverIndex = app(DriverLocationIndex::class);
        $this->quoteStore = app(QuoteStore::class);

        $this->pickup = Coordinate::of(3.5952, 98.6722);
        $this->destination = Coordinate::of(3.6000, 98.6800);

        $this->cleanRedis();
        $this->fakeOsrm();
    }

    protected function tearDown(): void
    {
        $this->cleanRedis();

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Jalur utama
    // -------------------------------------------------------------------------

    public function test_membuat_quote_dengan_pilihan_layanan_dan_harga(): void
    {
        $userId = $this->createUser();

        $quote = $this->action->handle($userId, $this->pickup, $this->destination);

        $this->assertNotSame('', $quote->id);
        $this->assertSame($userId, $quote->userId);
        $this->assertSame('Medan Kota', $quote->zoneName);
        $this->assertSame(8000, $quote->distanceMeters);
        $this->assertSame(1350, $quote->durationSeconds);

        // Seeder mengaktifkan dua layanan: send dan ride_bike.
        $this->assertArrayHasKey('ride_bike', $quote->options);
        $this->assertArrayHasKey('send', $quote->options);

        // ride_car dan food tidak aktif di seeder, jadi tidak muncul.
        $this->assertArrayNotHasKey('ride_car', $quote->options);

        $bike = $quote->option('ride_bike');
        $this->assertNotNull($bike);
        $this->assertTrue($bike->fare->total->isPositive());
        $this->assertTrue($bike->fare->linesSumToTotal());
        $this->assertTrue($bike->fare->isBalanced());
    }

    /**
     * Tarif khusus zona harus dipakai, bukan tarif default.
     *
     * Titik jemput ada di MDN-KOTA yang punya tarif ride_bike sendiri
     * (per_km 1.400, per_menit 130), berbeda dari default (1.600 dan 80).
     */
    public function test_memakai_tarif_khusus_zona_bukan_default(): void
    {
        $userId = $this->createUser();

        $quote = $this->action->handle($userId, $this->pickup, $this->destination);

        $zoneRuleId = DB::table('pricing_rules')
            ->join('service_types', 'service_types.id', '=', 'pricing_rules.service_type_id')
            ->join('zones', 'zones.id', '=', 'pricing_rules.zone_id')
            ->where('service_types.code', 'ride_bike')
            ->where('zones.code', 'MDN-KOTA')
            ->value('pricing_rules.id');

        $this->assertSame(
            (int) $zoneRuleId,
            $quote->option('ride_bike')->pricingRuleId,
            'Quote memakai tarif default padahal zona punya tarif khusus.',
        );
    }

    public function test_quote_tersimpan_di_redis_dan_bisa_dibaca_kembali(): void
    {
        $userId = $this->createUser();

        $quote = $this->action->handle($userId, $this->pickup, $this->destination);

        // Key mentah, tanpa prefix.
        $this->assertSame(
            1,
            (int) Redis::connection('shared')->exists("quote:{$quote->id}"),
        );

        $loaded = $this->quoteStore->get($quote->id);

        $this->assertNotNull($loaded);
        $this->assertSame($quote->id, $loaded->id);
        $this->assertSame($quote->distanceMeters, $loaded->distanceMeters);

        // Yang paling penting: HARGANYA sama persis setelah bolak-balik ke
        // Redis. Kalau berbeda, penumpang melihat satu harga lalu ditagih harga
        // lain, dan itu keluhan yang sah.
        $this->assertSame(
            $quote->option('ride_bike')->fare->total->amount,
            $loaded->option('ride_bike')->fare->total->amount,
        );

        $this->assertSame(
            $quote->option('ride_bike')->pricingRuleId,
            $loaded->option('ride_bike')->pricingRuleId,
        );
    }

    public function test_quote_punya_ttl_sesuai_config(): void
    {
        config(['antaride.quote.ttl_seconds' => 120]);

        $quote = $this->action->handle($this->createUser(), $this->pickup, $this->destination);

        $ttl = (int) Redis::connection('shared')->ttl("quote:{$quote->id}");

        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(120, $ttl);
    }

    // -------------------------------------------------------------------------
    // Ketersediaan driver
    // -------------------------------------------------------------------------

    public function test_tanpa_driver_layanan_tetap_muncul_dengan_penanda(): void
    {
        $quote = $this->action->handle($this->createUser(), $this->pickup, $this->destination);

        $bike = $quote->option('ride_bike');

        $this->assertSame(0, $bike->availableDrivers);
        $this->assertNull($bike->pickupEtaMinutes);

        // Layanan TETAP muncul. Menyembunyikannya membuat penumpang berpikir
        // layanannya tidak ada, lalu pindah aplikasi.
        $this->assertFalse($bike->isOrderable());
        $this->assertTrue($bike->fare->total->isPositive());
    }

    public function test_menghitung_driver_tersedia_dan_eta(): void
    {
        $zoneId = (int) DB::table('zones')->where('code', 'MDN-KOTA')->value('id');

        // Tiga driver di dekat titik jemput, semuanya siap terima order.
        foreach (self::DRIVERS as $index => $driverId) {
            $this->driverIndex->record(
                'ride_bike',
                $driverId,
                Coordinate::of(3.5952 + ($index * 0.0005), 98.6722),
            );
            $this->driverIndex->markAvailable('ride_bike', $zoneId, $driverId);
        }

        $quote = $this->action->handle($this->createUser(), $this->pickup, $this->destination);
        $bike = $quote->option('ride_bike');

        $this->assertSame(3, $bike->availableDrivers);
        $this->assertTrue($bike->isOrderable());

        // ETA dari OSRM palsu: 240 detik jadi 4 menit.
        $this->assertSame(4, $bike->pickupEtaMinutes);
    }

    /**
     * Driver yang ada di indeks posisi tapi TIDAK terdaftar tersedia harus
     * diabaikan.
     *
     * Ini kasus driver yang sedang mengantar. Posisinya tetap di indeks karena
     * penumpangnya perlu melihat dia bergerak, tapi dia tidak boleh dihitung
     * sebagai driver yang bisa menerima order baru.
     */
    public function test_driver_yang_sedang_mengantar_tidak_dihitung_tersedia(): void
    {
        $zoneId = (int) DB::table('zones')->where('code', 'MDN-KOTA')->value('id');

        // Dua driver punya posisi, tapi hanya satu yang tersedia.
        $this->driverIndex->record('ride_bike', 900101, Coordinate::of(3.5953, 98.6723));
        $this->driverIndex->record('ride_bike', 900102, Coordinate::of(3.5954, 98.6724));
        $this->driverIndex->markAvailable('ride_bike', $zoneId, 900101);

        $quote = $this->action->handle($this->createUser(), $this->pickup, $this->destination);

        $this->assertSame(1, $quote->option('ride_bike')->availableDrivers);
    }

    // -------------------------------------------------------------------------
    // Promo
    // -------------------------------------------------------------------------

    public function test_menemukan_promo_yang_eligible_dengan_diskon_per_layanan(): void
    {
        $userId = $this->createUser();
        $this->createPromo(code: 'HEMAT5K', type: 'fixed', value: 5000);

        $quote = $this->action->handle($userId, $this->pickup, $this->destination);

        $this->assertCount(1, $quote->eligiblePromos);
        $this->assertSame('HEMAT5K', $quote->eligiblePromos[0]['code']);

        // Diskonnya dihitung per layanan.
        $this->assertSame(5000, $quote->promoDiscountFor('HEMAT5K', 'ride_bike'));
        $this->assertSame(5000, $quote->promoDiscountFor('HEMAT5K', 'send'));
    }

    /**
     * Ongkos yang disimpan di quote adalah ongkos PENUH, tanpa diskon.
     *
     * Diskon diterapkan saat order dibuat, dengan nominal dari daftar promo.
     * Kalau diskon dibekukan ke dalam ongkos, penumpang tidak bisa
     * membandingkan harga antar layanan secara adil, karena tidak semua promo
     * berlaku untuk semua layanan.
     */
    public function test_ongkos_di_quote_belum_dipotong_diskon(): void
    {
        $userId = $this->createUser();
        $this->createPromo(code: 'HEMAT5K', type: 'fixed', value: 5000);

        $quote = $this->action->handle($userId, $this->pickup, $this->destination);

        $this->assertSame(
            0,
            $quote->option('ride_bike')->fare->discount->amount,
            'Diskon sudah dibekukan ke dalam ongkos quote.',
        );
    }

    public function test_promo_persen_dibatasi_max_discount(): void
    {
        $userId = $this->createUser();
        $this->createPromo(code: 'DISKON50', type: 'percent', value: 50, maxDiscount: 3000);

        $quote = $this->action->handle($userId, $this->pickup, $this->destination);

        // 50% dari ongkos jelas lebih dari 3.000, jadi diklem.
        $this->assertSame(3000, $quote->promoDiscountFor('DISKON50', 'ride_bike'));
    }

    public function test_promo_dengan_min_order_lebih_tinggi_dari_ongkos_tidak_muncul(): void
    {
        $userId = $this->createUser();
        $this->createPromo(code: 'MAHAL', type: 'fixed', value: 5000, minOrder: 999_999);

        $quote = $this->action->handle($userId, $this->pickup, $this->destination);

        $this->assertSame([], $quote->eligiblePromos);
    }

    public function test_promo_new_user_only_tidak_muncul_untuk_user_lama(): void
    {
        $userId = $this->createUser();
        $this->createPromo(code: 'BARU', type: 'fixed', value: 5000, newUserOnly: true);

        // Quote pertama: user masih baru.
        $first = $this->action->handle($userId, $this->pickup, $this->destination);
        $this->assertCount(1, $first->eligiblePromos);

        // Setelah punya order selesai, dia bukan user baru lagi.
        $this->completeAnOrderFor($userId);

        $second = $this->action->handle($userId, $this->pickup, $this->destination);
        $this->assertSame([], $second->eligiblePromos);
    }

    public function test_promo_yang_kuotanya_habis_tidak_muncul(): void
    {
        $userId = $this->createUser();
        $this->createPromo(code: 'HABIS', type: 'fixed', value: 5000, quotaTotal: 10, usedCount: 10);

        $quote = $this->action->handle($userId, $this->pickup, $this->destination);

        $this->assertSame([], $quote->eligiblePromos);
    }

    public function test_promo_dimatikan_feature_flag_tidak_muncul(): void
    {
        $userId = $this->createUser();
        $this->createPromo(code: 'HEMAT5K', type: 'fixed', value: 5000);

        DB::table('feature_flags')->where('key', 'promo.enabled')->update(['is_enabled' => false]);
        cache()->forget('feature:promo.enabled');

        $quote = $this->action->handle($userId, $this->pickup, $this->destination);

        $this->assertSame([], $quote->eligiblePromos);
    }

    /**
     * Cashback TIDAK memotong yang dibayar sekarang.
     *
     * Kalau diperlakukan sebagai potongan, penumpang membayar lebih sedikit DAN
     * mendapat saldo, jadi platform menanggung dua kali.
     */
    public function test_cashback_tidak_memotong_ongkos(): void
    {
        $userId = $this->createUser();
        $this->createPromo(code: 'CASHBACK', type: 'cashback', value: 5000);

        $quote = $this->action->handle($userId, $this->pickup, $this->destination);

        $this->assertSame([], $quote->eligiblePromos);
    }

    // -------------------------------------------------------------------------
    // Kegagalan
    // -------------------------------------------------------------------------

    public function test_titik_jemput_di_luar_zona_ditolak(): void
    {
        $this->expectException(OutsideServiceAreaException::class);

        $this->action->handle(
            $this->createUser(),
            Coordinate::of(3.9000, 99.5000),
            $this->destination,
        );
    }

    /**
     * Tujuan di luar zona TIDAK menggagalkan quote.
     *
     * Mengantar seseorang ke luar area operasional adalah hal wajar; yang tidak
     * dilayani adalah menjemput dari luar area, karena di sana tidak ada driver.
     */
    public function test_tujuan_di_luar_zona_tetap_diterima(): void
    {
        $quote = $this->action->handle(
            $this->createUser(),
            $this->pickup,
            Coordinate::of(3.9000, 99.5000),
        );

        $this->assertNotSame('', $quote->id);
    }

    /**
     * OSRM mati menggagalkan quote, TIDAK menebak jarak dari garis lurus.
     *
     * Diuji dengan mengganti RoutingService di container, bukan dengan
     * Http::fake. Alasannya: stub Http yang didaftarkan lebih dulu di setUp()
     * menang atas yang didaftarkan kemudian, jadi fake 500 di sini tidak akan
     * pernah kena dan test-nya lulus palsu.
     *
     * Mengganti di container juga lebih tepat sasaran: yang diuji adalah
     * perilaku CreateQuote saat routing gagal, bukan cara OsrmRoutingService
     * menerjemahkan status HTTP (itu sudah diuji OsrmRoutingServiceTest).
     */
    public function test_routing_gagal_menggagalkan_quote(): void
    {
        $this->app->bind(
            RoutingService::class,
            fn () => new class implements RoutingService
            {
                public function route($origin, $destination): RouteResult
                {
                    throw new RoutingUnavailableException('OSRM tidak dapat dihubungi.');
                }

                public function routeVia(array $waypoints): RouteResult
                {
                    throw new RoutingUnavailableException('OSRM tidak dapat dihubungi.');
                }

                public function durationsTo(array $origins, $destination): array
                {
                    throw new RoutingUnavailableException('OSRM tidak dapat dihubungi.');
                }

                public function isAvailable(): bool
                {
                    return false;
                }
            },
        );

        $this->expectException(RoutingUnavailableException::class);

        app(CreateQuote::class)->handle($this->createUser(), $this->pickup, $this->destination);
    }

    // -------------------------------------------------------------------------
    // Pembantu
    // -------------------------------------------------------------------------

    private function fakeOsrm(): void
    {
        Http::fake([
            // Tabel durasi untuk ETA penjemputan.
            '*/table/*' => Http::response([
                'code' => 'Ok',
                'durations' => [[240.0]],
            ], 200),

            // Rute: 8 km, 1350 detik.
            '*/route/*' => Http::response([
                'code' => 'Ok',
                'routes' => [[
                    'distance' => 8000.0,
                    'duration' => 1350.0,
                    'geometry' => '_p~iF~ps|U_ulLnnqC',
                ]],
            ], 200),
        ]);
    }

    private function createUser(): int
    {
        return DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid7(),
            'phone' => '6281'.random_int(100000000, 999999999),
            'name' => 'Penumpang Uji',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createPromo(
        string $code,
        string $type,
        int $value,
        ?int $maxDiscount = null,
        int $minOrder = 0,
        bool $newUserOnly = false,
        ?int $quotaTotal = null,
        int $usedCount = 0,
    ): void {
        DB::table('promos')->insert([
            'uuid' => (string) Str::uuid7(),
            'code' => $code,
            'title' => "Promo {$code}",
            'type' => $type,
            'value' => $value,
            // Promo persen WAJIB punya batas, ditegakkan CHECK constraint.
            'max_discount' => $type === 'percent' ? ($maxDiscount ?? 10000) : $maxDiscount,
            'min_order' => $minOrder,
            'new_user_only' => $newUserOnly,
            'quota_total' => $quotaTotal,
            'used_count' => $usedCount,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(30),
            'is_active' => true,
            'is_visible' => true,
            'cost_bearer' => 'platform',
            'merchant_share_percent' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Membuat satu order selesai untuk pengguna ini.
     *
     * Order `completed` WAJIB punya driver_id dan completed_at, ditegakkan
     * CHECK constraint `orders_completed_shape_check`. Versi pertama helper ini
     * tidak menyertakan driver, dan constraint itu yang menangkapnya, bukan test
     * yang gagal karena alasan lain.
     */
    private function completeAnOrderFor(int $userId): void
    {
        $serviceTypeId = (int) DB::table('service_types')->where('code', 'ride_bike')->value('id');
        $driverId = $this->createDriver();

        DB::table('orders')->insert([
            'uuid' => (string) Str::uuid7(),
            'order_number' => 'RD-TEST-'.random_int(100000, 999999),
            'user_id' => $userId,
            'service_type_id' => $serviceTypeId,
            'driver_id' => $driverId,
            'status' => 'completed',
            'payment_method' => 'cash',
            'distance_m' => 5000,
            'duration_s' => 900,
            // Angka-angka ini harus benar-benar konsisten, bukan sekadar
            // masuk akal: `orders_breakdown_sums_check` menuntut rinciannya
            // menjumlah ke total_fare, dan `orders_split_check` menuntut bagian
            // driver ditambah komisi tidak melebihi yang dibayar penumpang.
            //
            // ongkos transport  = 4000 + 4800            = 8800
            // komisi            = 8800 x 16,7%           = 1470
            // pendapatan driver = 8800 - 1470            = 7330
            // total             = 8800 + biaya app 1000  = 9800
            'base_fare' => 4000,
            'distance_fare' => 4800,
            'platform_fee' => 1000,
            'total_fare' => 9800,
            'driver_earning' => 7330,
            'commission_amount' => 1470,
            'pickup_address' => 'Jl. Uji',
            'pickup_lat' => 3.5952,
            'pickup_lng' => 98.6722,
            'requested_at' => now()->subHour(),
            'completed_at' => now()->subMinutes(30),
            'created_at' => now()->subHour(),
            'updated_at' => now(),
        ]);
    }

    private function createDriver(): int
    {
        $userId = $this->createUser();

        return DB::table('drivers')->insertGetId([
            'uuid' => (string) Str::uuid7(),
            'user_id' => $userId,
            'full_name' => 'Driver Uji',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function cleanRedis(): void
    {
        $raw = Redis::connection('shared');

        foreach (self::DRIVERS as $driverId) {
            $raw->del("drv:meta:{$driverId}");
            $raw->del("drv:zones:{$driverId}");
        }

        foreach (['ride_bike', 'send'] as $service) {
            $raw->del("drv:loc:{$service}");

            foreach (range(1, 20) as $zoneId) {
                $raw->del("drv:available:{$service}:zone:{$zoneId}");
            }
        }

        cache()->forget('feature:promo.enabled');
        cache()->forget('feature:surge.enabled');
    }
}
