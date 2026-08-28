<?php

declare(strict_types=1);

namespace Tests\Feature\Ordering;

use App\Domain\Driver\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Ordering\Actions\CancelOrder;
use App\Domain\Ordering\Actions\CompleteOrder;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Exceptions\OrderNotCancellableException;
use App\Domain\Ordering\Models\Order;
use App\Domain\Shared\ValueObjects\Coordinate;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Shared\ValueObjects\Polyline;
use App\Domain\Wallet\Actions\HoldFunds;
use App\Domain\Wallet\Actions\PostLedgerEntries;
use App\Domain\Wallet\DTOs\LedgerEntry;
use App\Domain\Wallet\Models\Wallet;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\SystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pembatalan dan penyelesaian order.
 *
 * ============================================================================
 *  YANG PALING DIPERIKSA DI SINI: UANG TIDAK TERTINGGAL DI MANA PUN
 * ============================================================================
 *  Setiap test yang menyangkut order berbayar wallet memeriksa `held_balance`
 *  setelahnya. Alasannya: kegagalan melepas dana tertahan adalah satu-satunya
 *  bug di jalur ini yang TIDAK menghasilkan error apa pun — saldo penumpang
 *  berkurang, tidak ada order berjalan, log bersih, dan yang menemukannya
 *  adalah penumpang yang menghitung saldonya sendiri.
 * ============================================================================
 */
class OrderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSeeder::class);
        $this->seed(CatalogSeeder::class);
    }

    // =========================================================================
    //  Pembatalan
    // =========================================================================

    public function test_penumpang_membatalkan_order_yang_masih_mencari(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id]);

        $cancelled = app(CancelOrder::class)->handle(
            order: $order,
            actorType: 'user',
            actorId: (int) $user->id,
            reasonCode: 'CANCEL_CHANGE_PLAN',
        );

        $this->assertSame(OrderStatus::Cancelled, $cancelled->status);
        $this->assertSame('user', $cancelled->cancelled_by);
        $this->assertNotNull($cancelled->cancelled_at);
    }

    public function test_belum_ada_driver_berarti_tidak_ada_biaya_pembatalan(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id]);

        // CANCEL_CHANGE_PLAN bertanda charges_fee, tapi belum ada driver yang
        // berkendara ke mana pun, jadi tidak ada yang perlu diganti.
        $cancelled = app(CancelOrder::class)->handle(
            $order, 'user', (int) $user->id, 'CANCEL_CHANGE_PLAN',
        );

        $this->assertSame(0, (int) $cancelled->cancellation_fee);
    }

    public function test_batal_dalam_jendela_gratis_tidak_dikenai_biaya(): void
    {
        $user = User::factory()->create();
        $driver = Driver::factory()->create();

        $order = Order::factory()->accepted($driver->id)->create([
            'user_id' => $user->id,
            'matched_at' => now()->subSeconds(30),
        ]);

        $cancelled = app(CancelOrder::class)->handle(
            $order, 'user', (int) $user->id, 'CANCEL_CHANGE_PLAN',
        );

        $this->assertSame(0, (int) $cancelled->cancellation_fee);
    }

    public function test_batal_setelah_jendela_gratis_dikenai_biaya(): void
    {
        $user = User::factory()->create();
        $driver = Driver::factory()->create();
        $this->fundWallet('user', (int) $user->id, 100_000);

        $order = Order::factory()->accepted($driver->id)->create([
            'user_id' => $user->id,
            'matched_at' => now()->subMinutes(10),
            'payment_method' => 'wallet',
            'payment_status' => 'held',
        ]);
        $this->holdFor($order);

        $cancelled = app(CancelOrder::class)->handle(
            $order, 'user', (int) $user->id, 'CANCEL_CHANGE_PLAN',
        );

        $fee = (int) config('antaride.order.cancellation_fee');

        $this->assertSame($fee, (int) $cancelled->cancellation_fee);
    }

    public function test_seluruh_biaya_pembatalan_masuk_ke_driver(): void
    {
        $user = User::factory()->create();
        $driver = Driver::factory()->create();
        $this->fundWallet('user', (int) $user->id, 100_000);

        $order = Order::factory()->accepted($driver->id)->create([
            'user_id' => $user->id,
            'matched_at' => now()->subMinutes(10),
            'payment_method' => 'wallet',
            'payment_status' => 'held',
        ]);
        $this->holdFor($order);

        app(CancelOrder::class)->handle($order, 'user', (int) $user->id, 'CANCEL_CHANGE_PLAN');

        $fee = (int) config('antaride.order.cancellation_fee');
        $driverWallet = Wallet::forOwner('driver', (int) $driver->id)->fresh();

        $this->assertSame(
            $fee,
            (int) $driverWallet->balance,
            'Driver menerima SELURUH biaya pembatalan. Platform tidak mengambil '
            .'komisi dari kejadian yang merugikan kedua pihak.'
        );

        // Dan platform revenue tidak bertambah dari pembatalan ini.
        $revenue = Wallet::platform(Wallet::PLATFORM_REVENUE)->fresh();
        $this->assertSame(0, (int) $revenue->balance);
    }

    public function test_alasan_tanpa_biaya_tidak_menagih_apa_pun(): void
    {
        $user = User::factory()->create();
        $driver = Driver::factory()->create();

        $order = Order::factory()->accepted($driver->id)->create([
            'user_id' => $user->id,
            'matched_at' => now()->subMinutes(10),
        ]);

        // Driver tidak bisa dihubungi: bukan kesalahan penumpang.
        $cancelled = app(CancelOrder::class)->handle(
            $order, 'user', (int) $user->id, 'CANCEL_DRIVER_UNREACHABLE',
        );

        $this->assertSame(0, (int) $cancelled->cancellation_fee);
    }

    public function test_driver_membatalkan_tidak_menagih_penumpang(): void
    {
        $user = User::factory()->create();
        $driver = Driver::factory()->create();

        $order = Order::factory()->accepted($driver->id)->create([
            'user_id' => $user->id,
            'matched_at' => now()->subMinutes(10),
        ]);

        $cancelled = app(CancelOrder::class)->handle(
            $order, 'driver', (int) $driver->id, 'DRV_VEHICLE_PROBLEM',
        );

        $this->assertSame(0, (int) $cancelled->cancellation_fee);
        $this->assertSame('driver', $cancelled->cancelled_by);
    }

    public function test_alasan_milik_pihak_lain_tidak_bisa_dipakai(): void
    {
        $user = User::factory()->create();
        $driver = Driver::factory()->create();
        $this->fundWallet('user', (int) $user->id, 100_000);

        $order = Order::factory()->accepted($driver->id)->create([
            'user_id' => $user->id,
            'matched_at' => now()->subMinutes(10),
        ]);

        // Driver mengirim kode alasan MILIK PENUMPANG yang bertanda charges_fee.
        // Kalau lolos, driver bisa menagih biaya pembatalan kepada penumpang atas
        // pembatalan yang dia lakukan sendiri.
        $cancelled = app(CancelOrder::class)->handle(
            $order, 'driver', (int) $driver->id, 'CANCEL_CHANGE_PLAN',
        );

        $this->assertSame(0, (int) $cancelled->cancellation_fee);
        $this->assertNull(
            $cancelled->cancellation_reason_id,
            'Kode alasan yang bukan milik pihak pembatal harus diabaikan seluruhnya.'
        );
    }

    public function test_dana_tertahan_dilepas_saat_dibatalkan(): void
    {
        $user = User::factory()->create();
        $this->fundWallet('user', (int) $user->id, 100_000);

        $order = Order::factory()->wallet()->create(['user_id' => $user->id]);
        $this->holdFor($order);

        $sebelum = Wallet::forOwner('user', (int) $user->id)->fresh();
        $this->assertSame((int) $order->total_fare, (int) $sebelum->held_balance);

        app(CancelOrder::class)->handle($order, 'user', (int) $user->id, 'CANCEL_WAIT_TOO_LONG');

        $sesudah = Wallet::forOwner('user', (int) $user->id)->fresh();

        $this->assertSame(
            0,
            (int) $sesudah->held_balance,
            'Dana tertahan yang tidak dilepas akan hilang dari saldo penumpang '
            .'SELAMANYA, tanpa satu pun error di log.'
        );
        $this->assertSame(100_000, (int) $sesudah->balance);
    }

    public function test_order_yang_sedang_berjalan_tidak_bisa_dibatalkan(): void
    {
        $user = User::factory()->create();
        $driver = Driver::factory()->create();
        $order = Order::factory()->inProgress($driver->id)->create(['user_id' => $user->id]);

        try {
            app(CancelOrder::class)->handle($order, 'user', (int) $user->id, 'CANCEL_CHANGE_PLAN');
            $this->fail('Order yang sedang berjalan seharusnya tidak bisa dibatalkan.');
        } catch (OrderNotCancellableException $e) {
            $this->assertSame(409, $e->httpStatus());
            $this->assertStringContainsString('sudah dimulai', $e->getMessage());
        }
    }

    public function test_order_yang_sudah_selesai_tidak_bisa_dibatalkan(): void
    {
        $driver = Driver::factory()->create();
        $order = Order::factory()->completed($driver->id)->create();

        $this->expectException(OrderNotCancellableException::class);

        app(CancelOrder::class)->handle($order, 'user', (int) $order->user_id, 'CANCEL_OTHER');
    }

    public function test_penawaran_menggantung_ditandai_cancelled_bukan_timeout(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id]);
        $driver = Driver::factory()->create();

        DB::table('order_offers')->insert([
            'order_id' => $order->id,
            'driver_id' => $driver->id,
            'wave' => 1,
            'distance_to_pickup_m' => 700,
            'score' => 0.8,
            'offered_at' => now(),
            'expires_at' => now()->addSeconds(15),
            'response' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(CancelOrder::class)->handle($order, 'user', (int) $user->id, 'CANCEL_WAIT_TOO_LONG');

        $this->assertSame(
            'cancelled',
            DB::table('order_offers')->where('order_id', $order->id)->value('response'),
            'Driver yang belum menjawab tidak melakukan kesalahan; timeout akan '
            .'menurunkan acceptance_rate-nya tanpa alasan.'
        );
    }

    // =========================================================================
    //  Penyelesaian
    // =========================================================================

    public function test_order_selesai_dan_uangnya_dibagi(): void
    {
        $user = User::factory()->create();
        $driver = Driver::factory()->create();

        $order = Order::factory()->inProgress($driver->id)->create(['user_id' => $user->id]);

        $completed = app(CompleteOrder::class)->handle(
            order: $order,
            driverId: (int) $driver->id,
            at: Coordinate::of(3.5833, 98.6742),
        );

        $this->assertSame(OrderStatus::Completed, $completed->status);
        $this->assertNotNull($completed->completed_at);
        $this->assertSame('paid', $completed->payment_status);

        // Order tunai: driver menerima uang dari penumpang, jadi yang bergerak
        // di pembukuan hanya bagian platform. Saldo driver ikut berkurang.
        $driverWallet = Wallet::forOwner('driver', (int) $driver->id)->fresh();
        $platformShare = (int) $order->total_fare - (int) $order->driver_earning;

        $this->assertSame(-$platformShare, (int) $driverWallet->balance);

        $revenue = Wallet::platform(Wallet::PLATFORM_REVENUE)->fresh();
        $this->assertSame($platformShare, (int) $revenue->balance);
    }

    public function test_penghitung_order_driver_bertambah(): void
    {
        $driver = Driver::factory()->create(['completed_orders' => 10]);
        $order = Order::factory()->inProgress($driver->id)->create();

        app(CompleteOrder::class)->handle($order, (int) $driver->id);

        $this->assertSame(11, (int) $driver->fresh()->completed_orders);
    }

    public function test_jarak_yang_menyimpang_jauh_menunda_settlement(): void
    {
        $user = User::factory()->create();
        $driver = Driver::factory()->create();

        // Estimasi 5 km, aktual 15 km: menyimpang 200%.
        $order = Order::factory()->forDistance(5_000)->inProgress($driver->id)->create([
            'user_id' => $user->id,
        ]);

        $completed = app(CompleteOrder::class)->handle(
            order: $order,
            driverId: (int) $driver->id,
            actualDistanceM: 15_000,
        );

        $this->assertTrue($completed->needs_fare_review);
        $this->assertSame(
            OrderStatus::Completed,
            $completed->status,
            'Ordernya tetap SELESAI — penumpang sudah sampai. Yang ditunda '
            .'pembagian uangnya, bukan status ordernya.'
        );
        $this->assertSame(
            'unpaid',
            $completed->payment_status,
            'Settlement harus menunggu review manusia.'
        );

        $revenue = Wallet::platform(Wallet::PLATFORM_REVENUE)->fresh();
        $this->assertSame(0, (int) $revenue->balance);
    }

    public function test_penyimpangan_wajar_tetap_di_settle(): void
    {
        $driver = Driver::factory()->create();

        // Estimasi 10 km, aktual 11 km: 10%, masih di bawah batas 30%.
        $order = Order::factory()->forDistance(10_000)->inProgress($driver->id)->create();

        $completed = app(CompleteOrder::class)->handle(
            order: $order,
            driverId: (int) $driver->id,
            actualDistanceM: 11_000,
        );

        $this->assertFalse($completed->needs_fare_review);
        $this->assertSame('paid', $completed->payment_status);
    }

    public function test_jarak_aktual_dihitung_dari_polyline_bukan_dari_client(): void
    {
        $driver = Driver::factory()->create();
        $order = Order::factory()->forDistance(3_000)->inProgress($driver->id)->create();

        // Client mengirim angka jarak yang dibesar-besarkan, tapi jejak GPS-nya
        // hanya beberapa ratus meter. Yang dipercaya adalah jejaknya.
        $route = Polyline::of([
            Coordinate::of(3.5952, 98.6722),
            Coordinate::of(3.5962, 98.6732),
            Coordinate::of(3.5972, 98.6742),
        ]);

        $completed = app(CompleteOrder::class)->handle(
            order: $order,
            driverId: (int) $driver->id,
            actualRoute: $route,
            actualDistanceM: 999_000,
        );

        $this->assertNotSame(
            999_000,
            (int) $completed->actual_distance_m,
            'Angka jarak dari client tidak boleh dipercaya kalau ada jejak GPS.'
        );
        $this->assertLessThan(5_000, (int) $completed->actual_distance_m);
    }

    public function test_polyline_aktual_disimpan(): void
    {
        $driver = Driver::factory()->create();
        $order = Order::factory()->inProgress($driver->id)->create();

        $route = Polyline::of([
            Coordinate::of(3.5952, 98.6722),
            Coordinate::of(3.5960, 98.6730),
            Coordinate::of(3.5970, 98.6740),
            Coordinate::of(3.5980, 98.6750),
        ]);

        $completed = app(CompleteOrder::class)->handle(
            $order, (int) $driver->id, actualRoute: $route,
        );

        $this->assertNotNull($completed->actual_polyline);
        $this->assertNotSame('', $completed->actual_polyline);
    }

    // =========================================================================
    //  Pembantu
    // =========================================================================

    private function fundWallet(string $ownerType, int $ownerId, int $amount): void
    {
        $wallet = Wallet::forOwner($ownerType, $ownerId);
        $settlement = Wallet::platform(Wallet::PLATFORM_SETTLEMENT);

        app(PostLedgerEntries::class)->handle([
            LedgerEntry::debit(
                walletId: (int) $settlement->getKey(),
                type: 'topup',
                amount: Money::of($amount),
                referenceType: 'topup',
                referenceId: $ownerId,
                description: 'Uji: dana masuk',
            ),
            LedgerEntry::credit(
                walletId: (int) $wallet->getKey(),
                type: 'topup',
                amount: Money::of($amount),
                referenceType: 'topup',
                referenceId: $ownerId,
                description: 'Uji: top up',
            ),
        ]);
    }

    /**
     * Tahan dana untuk order, lewat jalur yang sama seperti CreateOrder.
     */
    private function holdFor(Order $order): void
    {
        app(HoldFunds::class)->handle(
            wallet: Wallet::forOwner('user', (int) $order->user_id),
            amount: $order->totalFare(),
            referenceType: 'order',
            referenceId: (int) $order->getKey(),
        );
    }
}
