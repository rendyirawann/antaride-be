<?php

declare(strict_types=1);

namespace Tests\Feature\Ordering;

use App\Domain\Driver\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Ordering\Actions\CreateOrder;
use App\Domain\Ordering\DTOs\NewOrderRequest;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Exceptions\QuoteNotFoundException;
use App\Domain\Ordering\Exceptions\UserHasActiveOrderException;
use App\Domain\Ordering\Models\Order;
use App\Domain\Pricing\Actions\CreateQuote;
use App\Domain\Pricing\Contracts\QuoteStore;
use App\Domain\Pricing\DTOs\Quote;
use App\Domain\Shared\ValueObjects\Coordinate;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Wallet\Actions\PostLedgerEntries;
use App\Domain\Wallet\DTOs\LedgerEntry;
use App\Domain\Wallet\Exceptions\InsufficientBalanceException;
use App\Domain\Wallet\Models\Wallet;
use App\Jobs\MatchDriverJob;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\SystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ============================================================================
 *  QUOTE-NYA SUNGGUHAN, BUKAN DIPALSUKAN
 * ============================================================================
 *  Test ini memanggil CreateQuote yang asli, lalu memakai quote_id-nya. Yang
 *  dipalsukan hanya OSRM (HTTP keluar), karena itu satu-satunya bagian yang
 *  butuh layanan eksternal.
 *
 *  Alasannya: bug paling mahal di jalur ini bukan di dalam CreateOrder, tapi di
 *  SAMBUNGAN antara quote dan order. Diskon promo, misalnya, tersimpan di
 *  tempat yang berbeda dari yang saya duga saat pertama menulis CreateOrder —
 *  di `eligiblePromos`, bukan di `fare`. Quote palsu yang saya susun sendiri
 *  akan mengikuti dugaan saya, bukan kenyataan, dan test-nya akan lulus dengan
 *  bug yang masih ada.
 * ============================================================================
 */
class CreateOrderTest extends TestCase
{
    use RefreshDatabase;

    private Coordinate $pickup;

    private Coordinate $destination;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSeeder::class);
        $this->seed(CatalogSeeder::class);

        $this->pickup = Coordinate::of(3.5952, 98.6722);
        $this->destination = Coordinate::of(3.6000, 98.6800);

        $this->fakeOsrm();
        Queue::fake();
    }

    // =========================================================================
    //  Jalur normal
    // =========================================================================

    public function test_order_dibuat_dari_quote(): void
    {
        $user = User::factory()->create();
        $quote = $this->quoteFor($user);

        $order = app(CreateOrder::class)->handle($user, $this->request($quote));

        $this->assertSame(OrderStatus::Searching, $order->status);
        $this->assertSame((int) $user->id, (int) $order->user_id);
        $this->assertSame(8000, (int) $order->distance_m);
        $this->assertNotNull($order->pickup_code);
        $this->assertSame(4, strlen((string) $order->pickup_code));
    }

    public function test_harga_diambil_dari_quote_bukan_dari_client(): void
    {
        $user = User::factory()->create();
        $quote = $this->quoteFor($user);
        $option = $quote->option('ride_bike');

        $order = app(CreateOrder::class)->handle($user, $this->request($quote));

        $this->assertSame(
            (int) $option->fare->total->amount,
            (int) $order->total_fare,
            'Ongkos order harus sama persis dengan yang dihitung backend di quote.'
        );
        $this->assertSame(
            (int) $option->fare->driverEarning->amount,
            (int) $order->driver_earning,
        );
    }

    public function test_rincian_ongkos_menjumlah_ke_total(): void
    {
        $user = User::factory()->create();
        $order = app(CreateOrder::class)->handle($user, $this->request($this->quoteFor($user)));

        $jumlah = (int) $order->base_fare
            + (int) $order->distance_fare
            + (int) $order->time_fare
            + (int) $order->surge_amount
            + (int) $order->regulatory_adjustment
            + (int) $order->platform_fee
            + (int) $order->service_fee
            - (int) $order->discount_amount;

        $this->assertSame(
            (int) $order->total_fare,
            $jumlah,
            'Struk yang tidak menjumlah adalah keluhan yang tidak bisa dijawab tanpa membongkar kode.'
        );
    }

    public function test_quote_dihapus_setelah_dipakai(): void
    {
        $user = User::factory()->create();
        $quote = $this->quoteFor($user);

        app(CreateOrder::class)->handle($user, $this->request($quote));

        $this->assertNull(
            app(QuoteStore::class)->get($quote->id),
            'Quote yang sama tidak boleh bisa dipakai membuat order kedua.'
        );
    }

    public function test_matching_dijadwalkan(): void
    {
        $user = User::factory()->create();
        $order = app(CreateOrder::class)->handle($user, $this->request($this->quoteFor($user)));

        Queue::assertPushed(
            MatchDriverJob::class,
            fn (MatchDriverJob $job): bool => $job->orderId === (int) $order->id && $job->wave === 1,
        );
    }

    public function test_riwayat_status_punya_baris_pembuka(): void
    {
        $user = User::factory()->create();
        $order = app(CreateOrder::class)->handle($user, $this->request($this->quoteFor($user)));

        $log = DB::table('order_status_logs')->where('order_id', $order->id)->first();

        $this->assertNotNull($log);
        $this->assertNull($log->from_status, 'Baris pertama tidak punya status sebelumnya.');
        $this->assertSame('searching', $log->to_status);
        $this->assertSame('user', $log->actor_type);
        $this->assertSame((int) $user->id, (int) $log->actor_id);
    }

    // =========================================================================
    //  Quote
    // =========================================================================

    public function test_quote_tidak_ada_ditolak(): void
    {
        $user = User::factory()->create();

        $this->expectException(QuoteNotFoundException::class);

        app(CreateOrder::class)->handle($user, new NewOrderRequest(
            quoteId: (string) Str::uuid7(),
            serviceCode: 'ride_bike',
            paymentMethod: 'cash',
            pickupAddress: 'Jl. Uji',
        ));
    }

    public function test_quote_milik_pengguna_lain_ditolak(): void
    {
        $pemilik = User::factory()->create();
        $penyusup = User::factory()->create();

        $quote = $this->quoteFor($pemilik);

        try {
            app(CreateOrder::class)->handle($penyusup, $this->request($quote));
            $this->fail('Quote pengguna lain seharusnya ditolak.');
        } catch (QuoteNotFoundException $e) {
            // Pesannya HARUS sama dengan pesan quote tidak ada. Membedakannya
            // memberi tahu penyerang bahwa quote_id yang dia pegang benar-benar
            // ada, dan itu satu-satunya informasi yang dia butuhkan.
            $this->assertSame(
                QuoteNotFoundException::make()->getMessage(),
                $e->getMessage(),
            );
        }

        $this->assertSame(0, Order::query()->count());
    }

    public function test_layanan_yang_tidak_ada_di_quote_ditolak(): void
    {
        $user = User::factory()->create();
        $quote = $this->quoteFor($user);

        $this->expectException(QuoteNotFoundException::class);

        app(CreateOrder::class)->handle($user, new NewOrderRequest(
            quoteId: $quote->id,
            serviceCode: 'food',
            paymentMethod: 'cash',
            pickupAddress: 'Jl. Uji',
        ));
    }

    // =========================================================================
    //  Satu order aktif per pengguna
    // =========================================================================

    public function test_pengguna_dengan_order_berjalan_tidak_bisa_membuat_order_baru(): void
    {
        $user = User::factory()->create();
        Order::factory()->create(['user_id' => $user->id, 'status' => 'searching']);

        $quote = $this->quoteFor($user);

        try {
            app(CreateOrder::class)->handle($user, $this->request($quote));
            $this->fail('Seharusnya ditolak.');
        } catch (UserHasActiveOrderException $e) {
            $this->assertSame(409, $e->httpStatus());
            $this->assertNotNull(
                $e->activeOrderUuid,
                'UUID order yang menghalangi harus dibawa, supaya aplikasi bisa '
                .'membukanya langsung alih-alih hanya menampilkan pesan.'
            );
        }
    }

    public function test_order_yang_sudah_selesai_tidak_menghalangi(): void
    {
        $user = User::factory()->create();
        $driver = Driver::factory()->create();
        Order::factory()->completed($driver->id)->create(['user_id' => $user->id]);

        $order = app(CreateOrder::class)->handle($user, $this->request($this->quoteFor($user)));

        $this->assertSame(OrderStatus::Searching, $order->status);
    }

    // =========================================================================
    //  Pembayaran wallet
    // =========================================================================

    public function test_pembayaran_wallet_menahan_dana(): void
    {
        $user = User::factory()->create();
        $this->fundUserWallet($user, 200_000);

        $quote = $this->quoteFor($user);
        $total = (int) $quote->option('ride_bike')->fare->total->amount;

        $order = app(CreateOrder::class)->handle(
            $user,
            $this->request($quote, paymentMethod: 'wallet'),
        );

        $wallet = Wallet::forOwner('user', (int) $user->id)->fresh();

        $this->assertSame($total, (int) $wallet->held_balance);
        $this->assertSame(200_000 - $total, (int) $wallet->balance);
        $this->assertSame('held', $order->payment_status);
    }

    public function test_saldo_kurang_membatalkan_seluruh_order(): void
    {
        $user = User::factory()->create();
        $this->fundUserWallet($user, 1_000);

        $quote = $this->quoteFor($user);

        try {
            app(CreateOrder::class)->handle(
                $user,
                $this->request($quote, paymentMethod: 'wallet'),
            );
            $this->fail('Seharusnya ditolak karena saldo kurang.');
        } catch (InsufficientBalanceException) {
            // diharapkan
        }

        $this->assertSame(
            0,
            Order::query()->count(),
            'Order TIDAK boleh tersimpan kalau penahanan dananya gagal. Order '
            .'menggantung tanpa dana tertahan adalah order yang tidak bisa '
            .'diselesaikan maupun ditagih.'
        );

        $wallet = Wallet::forOwner('user', (int) $user->id)->fresh();
        $this->assertSame(1_000, (int) $wallet->balance);
        $this->assertSame(0, (int) $wallet->held_balance);
    }

    public function test_pembayaran_tunai_tidak_menahan_dana(): void
    {
        $user = User::factory()->create();
        $this->fundUserWallet($user, 200_000);

        app(CreateOrder::class)->handle($user, $this->request($this->quoteFor($user)));

        $wallet = Wallet::forOwner('user', (int) $user->id)->fresh();

        $this->assertSame(0, (int) $wallet->held_balance);
        $this->assertSame(200_000, (int) $wallet->balance);
    }

    // =========================================================================
    //  Nomor order
    // =========================================================================

    public function test_nomor_order_unik_untuk_beberapa_order(): void
    {
        $nomor = [];

        foreach (range(1, 5) as $i) {
            $user = User::factory()->create();
            $order = app(CreateOrder::class)->handle($user, $this->request($this->quoteFor($user)));
            $nomor[] = $order->order_number;
        }

        $this->assertCount(5, array_unique($nomor));
    }

    // =========================================================================
    //  Pembantu
    // =========================================================================

    private function quoteFor(User $user): Quote
    {
        return app(CreateQuote::class)->handle(
            (int) $user->id,
            $this->pickup,
            $this->destination,
        );
    }

    private function request(Quote $quote, string $paymentMethod = 'cash', ?string $promoCode = null): NewOrderRequest
    {
        return new NewOrderRequest(
            quoteId: $quote->id,
            serviceCode: 'ride_bike',
            paymentMethod: $paymentMethod,
            pickupAddress: 'Jl. Putri Hijau No. 1, Medan',
            destinationAddress: 'Sun Plaza, Medan',
            promoCode: $promoCode,
        );
    }

    /**
     * Isi saldo pengguna lewat jalur pembukuan yang sah.
     *
     * Bukan dengan UPDATE langsung ke wallets.balance. Saldo adalah cache dari
     * ledger, dan menulisnya tanpa baris ledger berarti test berjalan di atas
     * keadaan yang tidak mungkin terjadi di produksi — lalu satu hari nanti
     * gagal karena alasan yang tidak ada hubungannya dengan yang diuji.
     */
    private function fundUserWallet(User $user, int $amount): void
    {
        $userWallet = Wallet::forOwner('user', (int) $user->id);
        $settlement = Wallet::platform(Wallet::PLATFORM_SETTLEMENT);

        app(PostLedgerEntries::class)->handle([
            LedgerEntry::debit(
                walletId: (int) $settlement->getKey(),
                type: 'topup',
                amount: Money::of($amount),
                referenceType: 'topup',
                referenceId: 1,
                description: 'Uji: dana masuk dari gateway',
            ),
            LedgerEntry::credit(
                walletId: (int) $userWallet->getKey(),
                type: 'topup',
                amount: Money::of($amount),
                referenceType: 'topup',
                referenceId: 1,
                description: 'Uji: top up',
            ),
        ]);
    }

    private function fakeOsrm(): void
    {
        Http::fake([
            '*/table/*' => Http::response(['code' => 'Ok', 'durations' => [[240.0]]], 200),
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
}
