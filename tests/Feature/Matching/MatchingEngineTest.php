<?php

declare(strict_types=1);

namespace Tests\Feature\Matching;

use App\Domain\Driver\Actions\GoOnline;
use App\Domain\Driver\Models\Driver;
use App\Domain\Driver\Models\Vehicle;
use App\Domain\Matching\Actions\DispatchOfferWave;
use App\Domain\Matching\Actions\FindCandidateDrivers;
use App\Domain\Matching\Contracts\DriverLocationIndex;
use App\Domain\Matching\Scoring\DriverScorer;
use App\Domain\Ordering\Actions\AcceptOrder;
use App\Domain\Ordering\Contracts\OrderLock;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Shared\ValueObjects\Coordinate;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Wallet\Actions\PostLedgerEntries;
use App\Domain\Wallet\DTOs\LedgerEntry;
use App\Domain\Wallet\Models\Wallet;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\SystemSeeder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ============================================================================
 *  DIUJI DARI UJUNG KE UJUNG, DENGAN REDIS SUNGGUHAN
 * ============================================================================
 *  Indeks posisi driver hidup di Redis, dan tidak dipalsukan di sini. Alasannya
 *  sama seperti test yang berjalan di PostgreSQL alih-alih SQLite: yang paling
 *  sering salah di lapisan ini bukan logikanya, tapi bentuk data yang
 *  dipertukarkan dengan Redis — nama key, urutan argumen GEORADIUS, dan prefix
 *  koneksi.
 *
 *  Redis palsu akan setuju dengan apa pun yang saya tulis, termasuk yang salah.
 * ============================================================================
 */
class MatchingEngineTest extends TestCase
{
    use RefreshDatabase;

    /** Lapangan Merdeka Medan. */
    private Coordinate $pickup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSeeder::class);
        $this->seed(CatalogSeeder::class);

        $this->pickup = Coordinate::of(3.5952, 98.6722);
    }

    // =========================================================================
    //  Pencarian kandidat
    // =========================================================================

    public function test_driver_online_di_dekat_penjemputan_jadi_kandidat(): void
    {
        $driver = $this->onlineDriverAt($this->pickup);
        $this->fundDriver($driver, 50_000);
        $order = $this->orderAtPickup();

        $kandidat = app(FindCandidateDrivers::class)->handle($order, 2_000, 3);

        $this->assertCount(1, $kandidat);
        $this->assertSame((int) $driver->id, $kandidat[0]->driverId());
    }

    public function test_driver_di_luar_radius_bukan_kandidat(): void
    {
        /*
         * Titiknya harus tetap DI DALAM zona layanan, hanya jauh dari
         * penjemputan.
         *
         * Versi pertama test ini memakai Belawan, yang ada di luar seluruh zona
         * Medan — jadi yang menolak adalah GoOnline, bukan filter radius, dan
         * test-nya akan tetap lulus walaupun filter radiusnya rusak. Pusat zona
         * Medan Timur berjarak 3.730 m dari Lapangan Merdeka: di luar radius
         * 2.000 m, tapi masih terlayani.
         */
        $driver = $this->onlineDriverAt(Coordinate::of(3.6125, 98.7010));
        $this->fundDriver($driver, 50_000);

        $order = $this->orderAtPickup();

        $this->assertSame(
            [],
            app(FindCandidateDrivers::class)->handle($order, 2_000, 3),
            'Driver 3,7 km jauh tidak boleh masuk gelombang pertama yang radiusnya 2 km.'
        );

        // Dan pada radius gelombang keempat, dia MASUK — yang membuktikan
        // penyaringnya memang radius, bukan hal lain.
        $this->assertCount(
            1,
            app(FindCandidateDrivers::class)->handle($order, 8_000, 5),
        );
    }

    public function test_driver_offline_bukan_kandidat(): void
    {
        $driver = Driver::factory()->create();
        Vehicle::factory()->create(['driver_id' => $driver->id]);

        // Posisinya dicatat, tapi ketersediaannya tidak. Ini keadaan driver yang
        // sedang mengantar: penumpangnya perlu melihat dia bergerak, jadi
        // posisinya tetap di indeks, tapi dia tidak boleh ditawari order baru.
        app(DriverLocationIndex::class)->record('ride_bike', (int) $driver->id, $this->pickup);

        $order = $this->orderAtPickup();

        $this->assertSame(
            [],
            app(FindCandidateDrivers::class)->handle($order, 2_000, 3),
            'Indeks POSISI memuat semua driver online; yang menentukan siapa '
            .'boleh ditawari adalah set KETERSEDIAAN.'
        );
    }

    public function test_driver_yang_sedang_memegang_order_bukan_kandidat(): void
    {
        $driver = $this->onlineDriverAt($this->pickup);

        // Driver ini sedang mengantar, tapi Redis belum diperbarui — keadaan
        // yang terjadi kalau proses yang mencabut ketersediaan mati.
        Order::factory()->inProgress($driver->id)->create();

        $order = $this->orderAtPickup();

        $this->assertSame(
            [],
            app(FindCandidateDrivers::class)->handle($order, 2_000, 3),
            'Tabel orders adalah sumber kebenaran terakhir. Redis bisa tertinggal.'
        );
    }

    public function test_driver_yang_sudah_ditawari_tidak_ditawari_lagi(): void
    {
        $driver = $this->onlineDriverAt($this->pickup);
        $order = $this->orderAtPickup();

        // Gelombang pertama.
        app(DispatchOfferWave::class)->handle($order, 1);

        // Gelombang kedua tidak boleh menawarkan lagi ke orang yang sama.
        $kandidat = app(FindCandidateDrivers::class)->handle($order, 3_200, 5);

        $this->assertSame(
            [],
            $kandidat,
            'Menawarkan ulang ke driver yang baru saja menolak adalah cara '
            .'tercepat membuat dia mematikan aplikasi.'
        );
    }

    public function test_order_tunai_menyaring_driver_bersaldo_kurang(): void
    {
        $driver = $this->onlineDriverAt($this->pickup);
        $order = $this->orderAtPickup(); // bawaan: tunai

        // Saldo nol: tidak lolos ambang deposit.
        $this->assertSame([], app(FindCandidateDrivers::class)->handle($order, 2_000, 3));

        // Setelah diisi di atas ambang, dia lolos.
        $this->fundDriver($driver, 50_000);

        $kandidat = app(FindCandidateDrivers::class)->handle($order, 2_000, 3);

        $this->assertCount(
            1,
            $kandidat,
            'Ambang deposit ada supaya komisi order tunai bisa ditagih. Tanpa '
            .'itu, driver bersaldo nol menerima order tunai dan komisinya tidak '
            .'pernah bisa dipotong.'
        );
    }

    public function test_order_wallet_tidak_menuntut_deposit_driver(): void
    {
        $this->onlineDriverAt($this->pickup);
        $order = $this->orderAtPickup(wallet: true);

        $this->assertCount(
            1,
            app(FindCandidateDrivers::class)->handle($order, 2_000, 3),
            'Pada order wallet, uangnya ada di platform. Driver tidak memegang '
            .'uang siapa pun, jadi deposit tidak relevan.'
        );
    }

    // =========================================================================
    //  Skoring
    // =========================================================================

    public function test_driver_lebih_dekat_mendapat_skor_lebih_tinggi(): void
    {
        // Dua driver identik kecuali jaraknya.
        $dekat = $this->onlineDriverAt(Coordinate::of(3.5955, 98.6725));
        $jauh = $this->onlineDriverAt(Coordinate::of(3.6100, 98.6900));
        $this->fundDriver($dekat, 50_000);
        $this->fundDriver($jauh, 50_000);

        $order = $this->orderAtPickup();

        $kandidat = app(FindCandidateDrivers::class)->handle($order, 8_000, 5);

        $this->assertCount(2, $kandidat);
        $this->assertSame(
            (int) $dekat->id,
            $kandidat[0]->driverId(),
            'Jarak berbobot 0,45 — paling besar dari kelima faktor.'
        );
    }

    public function test_driver_baru_tidak_kalah_hanya_karena_belum_punya_riwayat(): void
    {
        /*
         * Ini invariant keadilan yang paling menentukan kelangsungan platform.
         *
         * Driver baru punya rating 5,00 dengan nol penilaian dan belum pernah
         * menerima penawaran. Kalau bonus idle-nya dihitung nol, dia akan kalah
         * terus dari driver lama, tidak pernah dapat order, tidak pernah
         * membangun riwayat, dan berhenti dalam dua minggu.
         *
         * Yang terlihat di dashboard: jumlah driver aktif tidak pernah naik
         * walaupun pendaftaran terus masuk.
         */
        $baru = $this->onlineDriverAt($this->pickup, Driver::factory()->newcomer());
        $this->fundDriver($baru, 50_000);

        $order = $this->orderAtPickup();
        $kandidat = app(FindCandidateDrivers::class)->handle($order, 2_000, 3);

        $this->assertCount(1, $kandidat);

        $rincian = $kandidat[0]->scoreBreakdown;
        $bobotIdle = (float) config('antaride.matching.weights.idle');

        $this->assertEqualsWithDelta(
            $bobotIdle,
            $rincian['idle'],
            0.001,
            'Driver tanpa riwayat penerimaan harus mendapat bonus keadilan PENUH, '
            .'bukan nol.'
        );
    }

    public function test_skor_tertinggi_yang_mungkin_adalah_jumlah_bobot_positif(): void
    {
        /*
         * Kelima bobot berjumlah 1,00, tapi yang bisa DICAPAI hanya 0,95 —
         * karena `cancellation` hanya mengurangi, tidak pernah menambah.
         *
         * Angka ini yang dipakai panel admin untuk menampilkan skor sebagai
         * persentase. Kalau pembaginya 1,00, skor sempurna akan tampil 95% dan
         * tidak akan pernah ada driver yang mencapai 100% — angka yang membuat
         * staf ops menyimpulkan ada yang rusak.
         */
        $bobot = config('antaride.matching.weights');

        $this->assertEqualsWithDelta(
            1.00,
            array_sum($bobot),
            0.0001,
            'Kelima bobot harus berjumlah 1,00 supaya rentang skor tetap sama antar zona.'
        );

        $this->assertEqualsWithDelta(
            0.95,
            app(DriverScorer::class)->maxPossibleScore(),
            0.0001,
            'Yang bisa dicapai hanya keempat bobot positif.'
        );
    }

    public function test_rincian_skor_disimpan_di_penawaran(): void
    {
        $driver = $this->onlineDriverAt($this->pickup);
        $this->fundDriver($driver, 50_000);
        $order = $this->orderAtPickup();

        app(DispatchOfferWave::class)->handle($order, 1);

        $rincian = json_decode(
            (string) DB::table('order_offers')->where('order_id', $order->id)->value('score_breakdown'),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        // Ini yang menjawab keluhan "kenapa saya tidak pernah dapat order"
        // dengan angka alih-alih dugaan.
        foreach (['distance', 'rating', 'acceptance', 'idle', 'cancellation'] as $faktor) {
            $this->assertArrayHasKey($faktor, $rincian);
        }

        $this->assertArrayHasKey('raw_distance_m', $rincian);
    }

    // =========================================================================
    //  Gelombang
    // =========================================================================

    public function test_radius_melebar_setiap_gelombang(): void
    {
        $wave = app(DispatchOfferWave::class);

        $this->assertSame(2_000, $wave->radiusForWave(1));
        $this->assertSame(3_200, $wave->radiusForWave(2));
        $this->assertSame(5_120, $wave->radiusForWave(3));
        $this->assertSame(
            8_000,
            $wave->radiusForWave(4),
            'Gelombang 4 menghasilkan 8.192 m tapi dipotong batas maksimum.'
        );
    }

    public function test_gelombang_pertama_menawari_lebih_sedikit(): void
    {
        $wave = app(DispatchOfferWave::class);

        $this->assertSame(3, $wave->candidateLimitForWave(1));
        $this->assertSame(5, $wave->candidateLimitForWave(2));
    }

    public function test_gelombang_menyimpan_penawaran_dan_batas_waktunya(): void
    {
        $driver = $this->onlineDriverAt($this->pickup);
        $this->fundDriver($driver, 50_000);
        $order = $this->orderAtPickup();

        $hasil = app(DispatchOfferWave::class)->handle($order, 1);

        $this->assertSame('offered', $hasil->outcome);
        $this->assertSame(1, $hasil->offeredCount());

        $offer = DB::table('order_offers')->where('order_id', $order->id)->first();

        $this->assertSame('pending', $offer->response);
        $this->assertSame(1, (int) $offer->wave);
        $this->assertNotNull($offer->expires_at);
    }

    public function test_gelombang_tidak_jalan_kalau_order_sudah_tidak_mencari(): void
    {
        $driver = $this->onlineDriverAt($this->pickup);
        $this->fundDriver($driver, 50_000);

        $order = Order::factory()->accepted($driver->id)->create();

        $hasil = app(DispatchOfferWave::class)->handle($order, 1);

        $this->assertSame('stopped', $hasil->outcome);
        $this->assertSame(
            0,
            DB::table('order_offers')->count(),
            'Menawarkan order yang sudah punya driver adalah kesalahan yang '
            .'langsung terlihat driver dan langsung merusak kepercayaan mereka.'
        );
    }

    public function test_tanpa_kandidat_hasilnya_empty_bukan_stopped(): void
    {
        // Bedanya menentukan: job harus MELANJUTKAN ke gelombang berikutnya
        // dengan radius lebih lebar, bukan berhenti.
        $order = $this->orderAtPickup();

        $hasil = app(DispatchOfferWave::class)->handle($order, 1);

        $this->assertSame('empty', $hasil->outcome);
        $this->assertTrue($hasil->shouldContinue());
    }

    // =========================================================================
    //  Ujung ke ujung
    // =========================================================================

    public function test_alur_lengkap_online_sampai_diterima(): void
    {
        $driver = $this->onlineDriverAt($this->pickup);
        $this->fundDriver($driver, 50_000);

        $order = $this->orderAtPickup();

        // 1. Gelombang penawaran.
        $hasil = app(DispatchOfferWave::class)->handle($order, 1);
        $this->assertSame('offered', $hasil->outcome);

        // 2. Driver menerima.
        $diterima = app(AcceptOrder::class)->handle($order->fresh(), $driver->fresh());

        $this->assertSame(OrderStatus::Accepted, $diterima->status);
        $this->assertSame((int) $driver->id, (int) $diterima->driver_id);

        // 3. Ketersediaannya dicabut, jadi dia tidak akan ditawari order lain.
        $this->assertNotContains(
            (int) $driver->id,
            app(DriverLocationIndex::class)->availableDriverIds('ride_bike', $this->zoneIds()),
        );

        // 4. Penawarannya tercatat diterima.
        $this->assertSame(
            'accepted',
            DB::table('order_offers')
                ->where('order_id', $order->id)
                ->where('driver_id', $driver->id)
                ->value('response'),
        );
    }

    // =========================================================================
    //  Pembantu
    // =========================================================================

    private function onlineDriverAt(Coordinate $at, ?Factory $factory = null): Driver
    {
        $driver = ($factory ?? Driver::factory())->create();
        Vehicle::factory()->create(['driver_id' => $driver->id]);

        DB::table('driver_service_eligibility')->insert([
            'driver_id' => $driver->id,
            'service_type_id' => (int) DB::table('service_types')->where('code', 'ride_bike')->value('id'),
            'is_enabled' => true,
            'enabled_by_driver' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(GoOnline::class)->handle($driver->fresh(), $at);

        return $driver->fresh();
    }

    private function orderAtPickup(bool $wallet = false): Order
    {
        $factory = Order::factory()->forDistance(4_000);

        if ($wallet) {
            $factory = $factory->wallet();
        }

        return $factory->create([
            'pickup_lat' => $this->pickup->lat,
            'pickup_lng' => $this->pickup->lng,
            'zone_id' => (int) DB::table('zones')->value('id'),
        ]);
    }

    private function fundDriver(Driver $driver, int $amount): void
    {
        $wallet = Wallet::forOwner('driver', (int) $driver->id);
        $settlement = Wallet::platform(Wallet::PLATFORM_SETTLEMENT);

        app(PostLedgerEntries::class)->handle([
            LedgerEntry::debit(
                walletId: (int) $settlement->getKey(),
                type: 'topup',
                amount: Money::of($amount),
                referenceType: 'topup',
                referenceId: (int) $driver->id,
                description: 'Uji: deposit driver',
            ),
            LedgerEntry::credit(
                walletId: (int) $wallet->getKey(),
                type: 'topup',
                amount: Money::of($amount),
                referenceType: 'topup',
                referenceId: (int) $driver->id,
                description: 'Uji: deposit driver',
            ),
        ]);
    }

    /**
     * @return array<int, int>
     */
    private function zoneIds(): array
    {
        return DB::table('zones')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    protected function tearDown(): void
    {
        foreach (Driver::query()->pluck('id') as $driverId) {
            app(DriverLocationIndex::class)->forget((int) $driverId);
            app(DriverLocationIndex::class)->markUnavailableEverywhere((int) $driverId);
        }

        foreach (Order::query()->pluck('id') as $orderId) {
            app(OrderLock::class)->forceRelease((int) $orderId);
        }

        parent::tearDown();
    }
}
