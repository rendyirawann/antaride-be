<?php

declare(strict_types=1);

namespace Tests\Feature\Ordering;

use App\Domain\Driver\Models\Driver;
use App\Domain\Driver\Models\Vehicle;
use App\Domain\Ordering\Actions\AcceptOrder;
use App\Domain\Ordering\Contracts\OrderLock;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Exceptions\DriverBusyException;
use App\Domain\Ordering\Exceptions\NoOfferForDriverException;
use App\Domain\Ordering\Exceptions\OfferExpiredException;
use App\Domain\Ordering\Exceptions\OrderAlreadyTakenException;
use App\Domain\Ordering\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ============================================================================
 *  TIGA LAPIS PERLINDUNGAN, DIUJI SATU PER SATU
 * ============================================================================
 *  Lima driver bisa menekan terima pada milidetik yang sama, dan tepat satu
 *  harus menang. PHPUnit tidak bisa menjalankan lima request sungguhan secara
 *  bersamaan, jadi yang diuji di sini adalah setiap lapis secara terpisah —
 *  dengan keadaan yang dibuat persis seperti saat balapan terjadi:
 *
 *    Lapis 1  lock Redis sudah dipegang driver lain
 *    Lapis 2  status order sudah berubah saat transaksi membacanya ulang
 *    Lapis 3  driver sudah punya order berjalan (partial unique index)
 *
 *  Lapis 3 diuji dua kali: lewat Action (pesan yang bisa dibaca driver) dan
 *  lewat INSERT langsung ke database (bukti bahwa index-nya memang menolak,
 *  bukan hanya kodenya).
 * ============================================================================
 */
class AcceptOrderTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    //  Jalur normal
    // =========================================================================

    public function test_driver_yang_ditawari_berhasil_menerima(): void
    {
        [$order, $driver] = $this->orderWithOffer();
        $vehicle = Vehicle::factory()->create(['driver_id' => $driver->id]);

        $accepted = app(AcceptOrder::class)->handle($order, $driver->fresh());

        $this->assertSame(OrderStatus::Accepted, $accepted->status);
        $this->assertSame($driver->id, (int) $accepted->driver_id);
        $this->assertSame($vehicle->id, (int) $accepted->vehicle_id);
        $this->assertNotNull($accepted->matched_at);
    }

    public function test_penerimaan_tercatat_di_log_status(): void
    {
        [$order, $driver] = $this->orderWithOffer();

        app(AcceptOrder::class)->handle($order, $driver);

        $log = DB::table('order_status_logs')
            ->where('order_id', $order->id)
            ->where('to_status', 'accepted')
            ->first();

        $this->assertNotNull($log, 'Setiap transisi harus meninggalkan jejak.');
        $this->assertSame('driver', $log->actor_type);
        $this->assertSame($driver->id, (int) $log->actor_id);
    }

    public function test_penawaran_driver_lain_ditandai_lost_bukan_timeout(): void
    {
        [$order, $pemenang] = $this->orderWithOffer();
        $yangKalah = $this->driverWithOffer($order);

        app(AcceptOrder::class)->handle($order, $pemenang);

        $this->assertSame(
            'accepted',
            $this->offerResponse($order, $pemenang),
        );

        $this->assertSame(
            'lost',
            $this->offerResponse($order, $yangKalah),
            'Driver yang kalah balapan TIDAK boleh ditandai timeout. Timeout '
            .'menurunkan acceptance_rate, dan dia tidak melakukan kesalahan apa pun.'
        );
    }

    public function test_penawaran_yang_kalah_tidak_dihapus(): void
    {
        [$order, $pemenang] = $this->orderWithOffer();
        $yangKalah = $this->driverWithOffer($order);

        app(AcceptOrder::class)->handle($order, $pemenang);

        $this->assertSame(
            2,
            DB::table('order_offers')->where('order_id', $order->id)->count(),
            'Penawaran yang kalah harus tetap ada sebagai bahan perhitungan '
            .'acceptance_rate. Menghapusnya membuat driver yang kalah tidak bisa '
            .'dibedakan dari driver yang mengabaikan penawaran.'
        );
    }

    // =========================================================================
    //  Lapis 1: lock Redis
    // =========================================================================

    public function test_lapis_1_lock_dipegang_driver_lain(): void
    {
        [$order, $driver] = $this->orderWithOffer();
        $driverLain = $this->driverWithOffer($order);

        // Driver lain sudah menguasai order ini beberapa milidetik lebih dulu.
        app(OrderLock::class)->acquire($order->id, $driverLain->id);

        try {
            app(AcceptOrder::class)->handle($order, $driver);
            $this->fail('Seharusnya ditolak lapis 1.');
        } catch (OrderAlreadyTakenException $e) {
            $this->assertSame(409, $e->httpStatus());
            $this->assertSame($driverLain->id, $e->heldByDriverId);
        }

        // Ordernya tidak tersentuh.
        $this->assertSame(OrderStatus::Searching, $order->fresh()->status);
        $this->assertNull($order->fresh()->driver_id);
    }

    public function test_pesan_penolakan_tidak_menyebut_driver_pemenang(): void
    {
        [$order, $driver] = $this->orderWithOffer();
        $driverLain = $this->driverWithOffer($order);

        app(OrderLock::class)->acquire($order->id, $driverLain->id);

        try {
            app(AcceptOrder::class)->handle($order, $driver);
            $this->fail('Seharusnya ditolak.');
        } catch (OrderAlreadyTakenException $e) {
            $this->assertStringNotContainsString(
                (string) $driverLain->id,
                $e->getMessage(),
                'Driver yang kalah tidak boleh tahu siapa yang menang. Itu membuka '
                .'jalan pemetaan siapa bekerja di area mana.'
            );
            $this->assertStringNotContainsString(
                $driverLain->full_name,
                $e->getMessage(),
            );
        }
    }

    public function test_lock_dilepas_saat_penerimaan_gagal(): void
    {
        // Order tanpa penawaran untuk driver ini: akan gagal di dalam transaksi,
        // SETELAH lock diambil.
        $order = Order::factory()->create();
        $driver = Driver::factory()->create();

        try {
            app(AcceptOrder::class)->handle($order, $driver);
        } catch (NoOfferForDriverException) {
            // diharapkan
        }

        $this->assertNull(
            app(OrderLock::class)->heldBy($order->id),
            'Lock yang tidak dilepas setelah gagal akan menahan order sampai TTL '
            .'habis, dan driver lain yang penawarannya masih berlaku tidak bisa '
            .'mengambilnya.'
        );
    }

    // =========================================================================
    //  Lapis 2: status berubah
    // =========================================================================

    public function test_lapis_2_order_sudah_diterima_driver_lain(): void
    {
        [$order, $driver] = $this->orderWithOffer();
        $driverPertama = $this->driverWithOffer($order);

        // Driver pertama menang.
        app(AcceptOrder::class)->handle($order, $driverPertama);

        // Driver kedua menekan terima sesaat kemudian. Lock milik yang pertama
        // dilepas dulu supaya yang diuji benar-benar lapis 2, bukan lapis 1.
        app(OrderLock::class)->forceRelease($order->id);

        try {
            app(AcceptOrder::class)->handle($order->fresh(), $driver);
            $this->fail('Seharusnya ditolak lapis 2.');
        } catch (OrderAlreadyTakenException $e) {
            $this->assertSame(409, $e->httpStatus());
            $this->assertSame(OrderStatus::Accepted, $e->currentStatus);
        }

        $this->assertSame($driverPertama->id, (int) $order->fresh()->driver_id);
    }

    public function test_order_yang_dibatalkan_penumpang_memberi_pesan_yang_benar(): void
    {
        [$order, $driver] = $this->orderWithOffer();

        $order->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => 'user'])->save();

        try {
            app(AcceptOrder::class)->handle($order->fresh(), $driver);
            $this->fail('Seharusnya ditolak.');
        } catch (OrderAlreadyTakenException $e) {
            $this->assertStringContainsString('dibatalkan', $e->getMessage());
        }
    }

    // =========================================================================
    //  Lapis 3: driver sudah punya order berjalan
    // =========================================================================

    public function test_lapis_3_driver_sudah_punya_order_berjalan(): void
    {
        [$order, $driver] = $this->orderWithOffer();

        // Driver ini sedang mengantar order lain.
        Order::factory()->inProgress($driver->id)->create();

        $this->expectException(DriverBusyException::class);

        app(AcceptOrder::class)->handle($order, $driver);
    }

    public function test_lapis_3_ditegakkan_database_bukan_hanya_kode(): void
    {
        $driver = Driver::factory()->create();
        Order::factory()->inProgress($driver->id)->create();

        // Lewat kode sudah ditolak. Ini membuktikan index-nya sendiri menolak,
        // sehingga jalur mana pun yang lupa memeriksa tetap tidak bisa lolos —
        // termasuk UPDATE langsung lewat psql.
        $orderLain = Order::factory()->create();

        /*
         * UPDATE-nya dijalankan di dalam transaksi bersarang, dan itu bukan
         * kerapian.
         *
         * Pelanggaran constraint di PostgreSQL membatalkan SELURUH transaksi:
         * setiap perintah setelahnya dijawab "current transaction is aborted".
         * RefreshDatabase sendiri membungkus test ini dalam satu transaksi, jadi
         * tanpa transaksi bersarang, exception yang memang diharapkan akan
         * merusak sisa test — termasuk tearDown.
         *
         * Laravel menerjemahkan transaksi bersarang menjadi SAVEPOINT, jadi yang
         * dibatalkan hanya sampai savepoint-nya dan transaksi luar tetap sehat.
         */
        $ditolak = false;

        try {
            DB::transaction(function () use ($orderLain, $driver): void {
                DB::table('orders')
                    ->where('id', $orderLain->id)
                    ->update(['status' => 'accepted', 'driver_id' => $driver->id]);
            });
        } catch (QueryException $e) {
            $ditolak = true;

            $this->assertStringContainsString(
                'orders_one_active_per_driver',
                $e->getMessage(),
                'Penolakannya harus datang dari partial unique index itu, bukan '
                .'dari constraint lain yang kebetulan juga gagal.'
            );
        }

        $this->assertTrue(
            $ditolak,
            'Database HARUS menolak driver memegang dua order berjalan, tanpa '
            .'bergantung pada kode aplikasi yang ingat memeriksanya.'
        );

        // Dan ordernya benar-benar tidak berubah.
        $this->assertSame(OrderStatus::Searching, $orderLain->fresh()->status);
    }

    // =========================================================================
    //  Otorisasi penawaran
    // =========================================================================

    public function test_driver_tanpa_penawaran_ditolak(): void
    {
        $order = Order::factory()->create();
        $driver = Driver::factory()->create();

        try {
            app(AcceptOrder::class)->handle($order, $driver);
            $this->fail('Seharusnya ditolak.');
        } catch (NoOfferForDriverException $e) {
            $this->assertSame(403, $e->httpStatus());
        }

        $this->assertNull($order->fresh()->driver_id);
    }

    public function test_driver_yang_sudah_menolak_tidak_bisa_berubah_pikiran(): void
    {
        [$order, $driver] = $this->orderWithOffer();

        DB::table('order_offers')
            ->where('order_id', $order->id)
            ->where('driver_id', $driver->id)
            ->update(['response' => 'rejected', 'responded_at' => now()]);

        $this->expectException(NoOfferForDriverException::class);

        app(AcceptOrder::class)->handle($order, $driver);
    }

    public function test_penawaran_kadaluarsa_ditolak(): void
    {
        [$order, $driver] = $this->orderWithOffer();

        DB::table('order_offers')
            ->where('order_id', $order->id)
            ->where('driver_id', $driver->id)
            ->update(['expires_at' => now()->subSecond()]);

        try {
            app(AcceptOrder::class)->handle($order, $driver);
            $this->fail('Seharusnya ditolak.');
        } catch (OfferExpiredException $e) {
            $this->assertSame(
                410,
                $e->httpStatus(),
                'Kadaluarsa dibedakan dari "sudah diambil" karena tindak lanjutnya '
                .'berbeda: order ini mungkin masih mencari driver.'
            );
        }
    }

    public function test_kadaluarsa_diukur_dengan_waktu_server(): void
    {
        [$order, $driver] = $this->orderWithOffer();

        DB::table('order_offers')
            ->where('order_id', $order->id)
            ->update(['expires_at' => now()->addSeconds(30)]);

        // Masih berlaku menurut server, jadi diterima — tanpa peduli apa pun
        // yang dikirim client tentang waktu.
        $accepted = app(AcceptOrder::class)->handle($order, $driver);

        $this->assertSame(OrderStatus::Accepted, $accepted->status);
    }

    // =========================================================================
    //  Helper
    // =========================================================================

    /**
     * @return array{Order, Driver}
     */
    private function orderWithOffer(): array
    {
        $order = Order::factory()->create();
        $driver = $this->driverWithOffer($order);

        return [$order, $driver];
    }

    private function driverWithOffer(Order $order): Driver
    {
        $driver = Driver::factory()->create();

        DB::table('order_offers')->insert([
            'order_id' => $order->id,
            'driver_id' => $driver->id,
            'wave' => 1,
            'distance_to_pickup_m' => 800,
            'score' => 0.812,
            'score_breakdown' => json_encode(['distance' => 0.36], JSON_THROW_ON_ERROR),
            'offered_at' => now(),
            'expires_at' => now()->addSeconds(15),
            'response' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $driver;
    }

    private function offerResponse(Order $order, Driver $driver): ?string
    {
        return DB::table('order_offers')
            ->where('order_id', $order->id)
            ->where('driver_id', $driver->id)
            ->value('response');
    }

    protected function tearDown(): void
    {
        // Lock hidup di Redis, bukan di database, jadi RefreshDatabase tidak
        // membersihkannya. Lock yang tertinggal akan membuat test berikutnya
        // gagal karena alasan yang tidak ada hubungannya dengan yang diuji.
        foreach (Order::query()->pluck('id') as $orderId) {
            app(OrderLock::class)->forceRelease((int) $orderId);
        }

        parent::tearDown();
    }
}
