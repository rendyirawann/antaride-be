<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Domain\Driver\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\StateMachine\OrderStateMachine;
use App\Domain\Ordering\StateMachine\OrderTransition;
use App\Domain\Support\Actions\SendNotification;
use App\Domain\Support\Models\Notification;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\SystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ============================================================================
 *  NOTIFIKASI TIDAK BOLEH MENGGAGALKAN APA PUN
 * ============================================================================
 *  Notifikasi lahir dari dalam alur yang jauh lebih penting daripada dirinya:
 *  transisi status order, penyelesaian perjalanan, pembukuan dompet.
 *
 *  Yang diuji di sini karena itu bukan hanya "notifikasinya dibuat", tapi juga
 *  bahwa kegagalannya TIDAK menjatuhkan alur pemanggilnya — dan bahwa duplikat
 *  tidak menumpuk.
 * ============================================================================
 */
class NotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSeeder::class);
        $this->seed(CatalogSeeder::class);
    }

    // =========================================================================
    //  Action
    // =========================================================================

    public function test_notifikasi_tersimpan(): void
    {
        $dibuat = app(SendNotification::class)->handle(
            recipientType: 'user',
            recipientId: 1,
            type: Notification::ANNOUNCEMENT,
            title: 'Selamat datang',
            body: 'Antaride kini hadir di Medan.',
        );

        $this->assertTrue($dibuat);

        $this->assertDatabaseHas('notifications', [
            'recipient_type' => 'user',
            'recipient_id' => 1,
            'type' => Notification::ANNOUNCEMENT,
            'title' => 'Selamat datang',
        ]);
    }

    /**
     * ========================================================================
     *  DUPLIKAT DIABAIKAN, TIDAK MELEMPAR
     * ========================================================================
     *  Yang memicunya di lapangan: job yang di-retry, atau transisi status yang
     *  dijalankan dua kali karena request yang diulang.
     *
     *  Yang harus terjadi: baris kedua tidak dibuat, DAN pemanggilnya tidak
     *  melihat galat. Kalau melempar, retry job yang wajar akan menggagalkan
     *  seluruh job — untuk satu baris notifikasi.
     * ========================================================================
     */
    public function test_notifikasi_ganda_diabaikan_tanpa_galat(): void
    {
        $kirim = fn (): bool => app(SendNotification::class)->forOrder(
            recipientType: 'user',
            recipientId: 1,
            type: Notification::ORDER_ACCEPTED,
            title: 'Driver ditemukan',
            body: 'Driver sudah menerima pesanan Anda.',
            orderUuid: 'uuid-yang-sama',
        );

        $this->assertTrue($kirim(), 'Yang pertama harus dibuat.');
        $this->assertFalse($kirim(), 'Yang kedua harus diabaikan.');
        $this->assertFalse($kirim(), 'Yang ketiga juga.');

        $this->assertSame(
            1,
            DB::table('notifications')->where('recipient_id', 1)->count(),
            'Harus tepat satu baris. Lebih dari itu berarti unique index '
            .'notifications_dedupe_idx tidak bekerja.',
        );
    }

    /**
     * Order yang BERBEDA tetap menghasilkan notifikasi terpisah.
     *
     * Kunci dedupe memuat `action`, yang memuat uuid order. Kalau dedupe-nya
     * hanya (penerima, type), penumpang hanya akan pernah mendapat SATU
     * notifikasi "driver ditemukan" sepanjang hidup akunnya.
     */
    public function test_order_berbeda_menghasilkan_notifikasi_terpisah(): void
    {
        foreach (['uuid-a', 'uuid-b', 'uuid-c'] as $uuid) {
            app(SendNotification::class)->forOrder(
                recipientType: 'user',
                recipientId: 1,
                type: Notification::ORDER_ACCEPTED,
                title: 'Driver ditemukan',
                body: 'Driver sudah menerima pesanan Anda.',
                orderUuid: $uuid,
            );
        }

        $this->assertSame(
            3,
            DB::table('notifications')->where('recipient_id', 1)->count(),
        );
    }

    /**
     * Pengumuman tanpa `action` juga dijaga unik.
     *
     * Index-nya memakai `NULLS NOT DISTINCT`. Tanpa itu, `NULL = NULL` bernilai
     * unknown di SQL dan setiap pengumuman bisa masuk berkali-kali.
     */
    public function test_pengumuman_tanpa_action_dijaga_unik(): void
    {
        for ($i = 0; $i < 3; $i++) {
            app(SendNotification::class)->handle(
                recipientType: 'user',
                recipientId: 1,
                type: Notification::ANNOUNCEMENT,
                title: 'Pengumuman',
                body: 'Isi pengumuman.',
            );
        }

        $this->assertSame(
            1,
            DB::table('notifications')->where('recipient_id', 1)->count(),
            'Pengumuman tanpa action masuk berkali-kali — NULLS NOT DISTINCT '
            .'tidak bekerja.',
        );
    }

    /**
     * Judul yang terlalu panjang DIPANGKAS, bukan menggagalkan.
     *
     * Kolomnya varchar(160). Judul yang lebih panjang akan ditolak Postgres
     * dengan galat yang menggagalkan seluruh alur pemanggil — transisi status
     * order — untuk satu notifikasi.
     */
    public function test_judul_terlalu_panjang_dipangkas(): void
    {
        $dibuat = app(SendNotification::class)->handle(
            recipientType: 'user',
            recipientId: 1,
            type: Notification::ANNOUNCEMENT,
            title: str_repeat('a', 500),
            body: str_repeat('b', 2000),
        );

        $this->assertTrue($dibuat, 'Judul panjang tidak boleh menggagalkan.');

        $baris = DB::table('notifications')->where('recipient_id', 1)->first();

        $this->assertLessThanOrEqual(160, mb_strlen((string) $baris->title));
        $this->assertLessThanOrEqual(500, mb_strlen((string) $baris->body));
    }

    /**
     * Jenis penerima yang tidak sah ditolak database, dan Action-nya TIDAK
     * melempar.
     *
     * CHECK constraint di tabel yang menolaknya. Yang diuji: kegagalan itu
     * ditelan dan dilaporkan sebagai false — bukan naik sebagai exception ke
     * alur pemanggil.
     */
    public function test_penerima_tidak_sah_ditolak_tanpa_melempar(): void
    {
        $dibuat = app(SendNotification::class)->handle(
            recipientType: 'admin',
            recipientId: 1,
            type: Notification::ANNOUNCEMENT,
            title: 'Untuk admin',
            body: 'Notifikasi admin diturunkan dari keadaan, bukan disimpan.',
        );

        $this->assertFalse($dibuat);
        $this->assertSame(0, DB::table('notifications')->count());
    }

    // =========================================================================
    //  Lahir dari transisi status order
    // =========================================================================

    public function test_transisi_ke_accepted_memberi_tahu_penumpang(): void
    {
        [$order, $user, $driver] = $this->orderSiapTransisi();

        app(OrderStateMachine::class)->apply(
            $order,
            OrderTransition::byDriver(
                to: OrderStatus::Accepted,
                driverId: (int) $driver->id,
            ),
        );

        $this->assertDatabaseHas('notifications', [
            'recipient_type' => 'user',
            'recipient_id' => $user->id,
            'type' => Notification::ORDER_ACCEPTED,
        ]);
    }

    /**
     * ========================================================================
     *  TIDAK SETIAP TRANSISI MENGHASILKAN NOTIFIKASI
     * ========================================================================
     *  Order melewati enam status. Memberitahu penumpang di setiap langkah
     *  berarti enam notifikasi untuk satu perjalanan — dan lonceng yang berisi
     *  enam baris untuk satu order membuat notifikasi berhenti dibaca.
     *
     *  `driver_arriving` sengaja TIDAK memberi tahu: tidak ada yang perlu
     *  dilakukan penumpang saat driver mulai bergerak.
     * ========================================================================
     */
    public function test_transisi_driver_arriving_tidak_memberi_tahu(): void
    {
        [$order, $user, $driver] = $this->orderSiapTransisi();

        $diterima = app(OrderStateMachine::class)->apply(
            $order,
            OrderTransition::byDriver(
                to: OrderStatus::Accepted,
                driverId: (int) $driver->id,
            ),
        );

        $sebelum = DB::table('notifications')
            ->where('recipient_id', $user->id)
            ->count();

        app(OrderStateMachine::class)->apply(
            $diterima,
            OrderTransition::byDriver(
                to: OrderStatus::DriverArriving,
                driverId: (int) $driver->id,
            ),
        );

        $this->assertSame(
            $sebelum,
            DB::table('notifications')->where('recipient_id', $user->id)->count(),
            'driver_arriving menghasilkan notifikasi. Enam notifikasi untuk satu '
            .'perjalanan membuat loncengnya berhenti dibaca.',
        );
    }

    public function test_driver_tiba_memberi_tahu_penumpang(): void
    {
        [$order, $user, $driver] = $this->orderSiapTransisi();

        $sm = app(OrderStateMachine::class);

        $o = $sm->apply($order, OrderTransition::byDriver(
            to: OrderStatus::Accepted, driverId: (int) $driver->id,
        ));

        $o = $sm->apply($o, OrderTransition::byDriver(
            to: OrderStatus::DriverArriving, driverId: (int) $driver->id,
        ));

        $sm->apply($o, OrderTransition::byDriver(
            to: OrderStatus::DriverArrived, driverId: (int) $driver->id,
        ));

        $this->assertDatabaseHas('notifications', [
            'recipient_type' => 'user',
            'recipient_id' => $user->id,
            'type' => Notification::ORDER_DRIVER_ARRIVED,
        ]);
    }

    // =========================================================================
    //  HTTP
    // =========================================================================

    public function test_daftar_notifikasi_beserta_jumlah_belum_dibaca(): void
    {
        $user = User::factory()->create();

        foreach (['a', 'b', 'c'] as $uuid) {
            app(SendNotification::class)->forOrder(
                recipientType: 'user',
                recipientId: (int) $user->id,
                type: Notification::ORDER_COMPLETED,
                title: 'Perjalanan selesai',
                body: 'Beri penilaian.',
                orderUuid: $uuid,
            );
        }

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.unread_count', 3)
            ->assertJsonPath('data.0.is_read', false);
    }

    public function test_menandai_satu_notifikasi_dibaca(): void
    {
        $user = User::factory()->create();

        app(SendNotification::class)->handle(
            recipientType: 'user',
            recipientId: (int) $user->id,
            type: Notification::ANNOUNCEMENT,
            title: 'Pengumuman',
            body: 'Isi.',
        );

        Sanctum::actingAs($user);

        $uuid = $this->getJson('/api/v1/notifications')->json('data.0.uuid');

        $this->postJson("/api/v1/notifications/{$uuid}/read")
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        $this->getJson('/api/v1/notifications')
            ->assertJsonPath('data.0.is_read', true);
    }

    public function test_menandai_semua_dibaca(): void
    {
        $user = User::factory()->create();

        foreach (['a', 'b', 'c'] as $uuid) {
            app(SendNotification::class)->forOrder(
                recipientType: 'user',
                recipientId: (int) $user->id,
                type: Notification::ORDER_COMPLETED,
                title: 'Selesai',
                body: 'Beri penilaian.',
                orderUuid: $uuid,
            );
        }

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.marked', 3)
            ->assertJsonPath('data.unread_count', 0);

        // Dipanggil lagi: tidak ada yang perlu ditandai, dan itu bukan galat.
        $this->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.marked', 0);
    }

    /**
     * Notifikasi orang lain TIDAK terlihat.
     *
     * Ini pemeriksaan yang paling mendasar, dan yang paling mudah terlewat pada
     * endpoint yang "hanya membaca daftar".
     */
    public function test_notifikasi_orang_lain_tidak_terlihat(): void
    {
        $milikOrangLain = User::factory()->create();

        app(SendNotification::class)->handle(
            recipientType: 'user',
            recipientId: (int) $milikOrangLain->id,
            type: Notification::ANNOUNCEMENT,
            title: 'Rahasia',
            body: 'Tidak boleh terbaca orang lain.',
        );

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.unread_count', 0);
    }

    /**
     * ========================================================================
     *  NOTIFIKASI PENUMPANG DAN DRIVER TERPISAH DI AKUN YANG SAMA
     * ========================================================================
     *  Satu orang bisa jadi penumpang DAN driver — driver memesan ojek saat
     *  kendaraannya di bengkel.
     *
     *  Kalau `recipient_type` disimpulkan dari "apakah akun ini punya baris di
     *  tabel drivers", maka setiap driver yang memesan ojek akan melihat
     *  notifikasi drivernya di aplikasi penumpang, dan tidak akan pernah melihat
     *  notifikasi penumpangnya.
     * ========================================================================
     */
    public function test_notifikasi_penumpang_dan_driver_terpisah(): void
    {
        $driver = Driver::factory()->create();
        $user = $driver->user;

        app(SendNotification::class)->handle(
            recipientType: 'user',
            recipientId: (int) $user->id,
            type: Notification::ANNOUNCEMENT,
            title: 'Untuk penumpang',
            body: 'Sebagai penumpang.',
        );

        app(SendNotification::class)->handle(
            recipientType: 'driver',
            recipientId: (int) $driver->id,
            type: Notification::ANNOUNCEMENT,
            title: 'Untuk driver',
            body: 'Sebagai driver.',
        );

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Untuk penumpang');

        $this->getJson('/api/v1/notifications?as=driver')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Untuk driver');
    }

    public function test_pengguna_bukan_driver_ditolak_saat_meminta_as_driver(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/notifications?as=driver')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'NOT_A_DRIVER');
    }

    public function test_endpoint_jumlah_belum_dibaca(): void
    {
        $user = User::factory()->create();

        app(SendNotification::class)->handle(
            recipientType: 'user',
            recipientId: (int) $user->id,
            type: Notification::ANNOUNCEMENT,
            title: 'Pengumuman',
            body: 'Isi.',
        );

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1);
    }

    /**
     * `unread-count` tidak boleh tertangkap sebagai uuid.
     *
     * Route `/{uuid}/read` dan `/unread-count` berada di prefix yang sama. Kalau
     * urutannya terbalik, `unread-count` akan diperlakukan sebagai uuid — dan
     * endpoint yang paling sering dipanggil aplikasi mengembalikan 404.
     */
    public function test_unread_count_tidak_tertangkap_sebagai_uuid(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/notifications/unread-count')->assertOk();
    }

    // =========================================================================

    /**
     * Order berstatus `searching` dengan driver yang siap menerimanya.
     *
     * @return array{0: Order, 1: User, 2: Driver}
     */
    private function orderSiapTransisi(): array
    {
        $driver = Driver::factory()->create();
        $user = User::factory()->create();

        $order = Order::factory()->create([
            'user_id' => $user->id,
        ]);

        DB::table('orders')->where('id', $order->id)->update([
            'status' => OrderStatus::Searching->value,
        ]);

        return [$order->fresh(), $user, $driver];
    }
}
