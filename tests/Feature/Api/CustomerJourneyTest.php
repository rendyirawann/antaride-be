<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Driver\Models\Driver;
use App\Domain\Driver\Models\Vehicle;
use App\Domain\Identity\Models\User;
use App\Domain\Matching\Actions\DispatchOfferWave;
use App\Domain\Matching\Contracts\DriverLocationIndex;
use App\Domain\Ordering\Contracts\OrderLock;
use App\Domain\Ordering\Models\Order;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Wallet\Actions\HoldFunds;
use App\Domain\Wallet\Actions\PostLedgerEntries;
use App\Domain\Wallet\DTOs\LedgerEntry;
use App\Domain\Wallet\Models\Wallet;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\SystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ============================================================================
 *  SELURUH PERJALANAN, LEWAT HTTP
 * ============================================================================
 *  Test ini memanggil endpoint sungguhan dengan request HTTP, bukan Action
 *  langsung. Yang diuji bukan lagi logika bisnisnya — itu sudah ada test-nya
 *  sendiri — tapi SAMBUNGANNYA:
 *
 *    - apakah middleware idempotency dan auth terpasang di route yang benar
 *    - apakah FormRequest menerima bentuk payload yang dikirim aplikasi
 *    - apakah exception domain diterjemahkan ke kode HTTP yang benar
 *    - apakah Resource membocorkan field yang seharusnya tidak keluar
 *
 *  Empat hal itu tidak akan pernah tertangkap test Action, karena Action tidak
 *  tahu apa pun soal HTTP.
 * ============================================================================
 */
class CustomerJourneyTest extends TestCase
{
    use RefreshDatabase;

    private const PICKUP = ['lat' => 3.5952, 'lng' => 98.6722];

    private const DEST = ['lat' => 3.6000, 'lng' => 98.6800];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSeeder::class);
        $this->seed(CatalogSeeder::class);

        $this->fakeOsrm();
    }

    // =========================================================================
    //  Autentikasi
    // =========================================================================

    public function test_daftar_dan_masuk_lewat_otp(): void
    {
        $minta = $this->postJson('/api/v1/auth/otp/request', [
            'phone' => '081234567890',
        ]);

        $minta->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.phone_masked', '0812-****-7890');

        $kode = $minta->json('data.debug_code');

        $this->assertNotNull(
            $kode,
            'Di luar produksi, kodenya dibalikkan supaya alur ini bisa diuji '
            .'tanpa gateway SMS.'
        );

        $verifikasi = $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => '081234567890',
            'code' => $kode,
            'device_id' => 'uji-perangkat-1',
            'platform' => 'android',
        ]);

        $verifikasi->assertStatus(201)
            ->assertJsonPath('data.is_new_user', true)
            ->assertJsonStructure(['data' => ['token', 'user' => ['uuid', 'name', 'phone']]]);

        // Nomornya tersimpan dalam bentuk kanonik, bukan bentuk yang dikirim.
        $this->assertDatabaseHas('users', ['phone' => '6281234567890']);

        // Dompetnya sudah ada sejak awal, bukan menunggu top up pertama.
        $userId = (int) DB::table('users')->where('phone', '6281234567890')->value('id');
        $this->assertDatabaseHas('wallets', ['owner_type' => 'user', 'owner_id' => $userId]);
    }

    public function test_bentuk_nomor_apa_pun_menghasilkan_akun_yang_sama(): void
    {
        // Empat bentuk penulisan nomor yang sama. Kalau normalisasinya tidak
        // konsisten, satu orang akan punya empat akun dengan saldo terpisah.
        foreach (['081234567890', '+6281234567890', '6281234567890', '0812 3456 7890'] as $bentuk) {
            $minta = $this->postJson('/api/v1/auth/otp/request', ['phone' => $bentuk]);

            // Yang kedua dan seterusnya kena jeda pengiriman ulang — itu benar,
            // dan justru membuktikan jedanya berlaku per NOMOR, bukan per bentuk
            // penulisan.
            if ($minta->status() === 429) {
                continue;
            }

            $minta->assertOk();
        }

        $this->assertSame(
            1,
            DB::table('otp_requests')->distinct()->count('phone'),
            'Keempat bentuk harus dinormalkan ke satu nomor yang sama.'
        );
    }

    public function test_kode_salah_ditolak_dengan_sisa_percobaan(): void
    {
        $this->postJson('/api/v1/auth/otp/request', ['phone' => '081234567890'])->assertOk();

        $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => '081234567890',
            'code' => '9999',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_OTP')
            ->assertJsonPath('error.details.remaining_attempts', 4);
    }

    public function test_jeda_kirim_ulang_ditegakkan(): void
    {
        $this->postJson('/api/v1/auth/otp/request', ['phone' => '081234567890'])->assertOk();

        $this->postJson('/api/v1/auth/otp/request', ['phone' => '081234567890'])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'OTP_COOLDOWN')
            ->assertJsonStructure(['error' => ['details' => ['retry_after_seconds']]]);
    }

    public function test_endpoint_terlindungi_menolak_tanpa_token(): void
    {
        $this->postJson('/api/v1/quotes', [
            'pickup' => self::PICKUP,
            'destination' => self::DEST,
        ])->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    // =========================================================================
    //  Estimasi harga dan pembuatan order
    // =========================================================================

    public function test_alur_lengkap_quote_sampai_order_selesai(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // --- 1. Estimasi harga ---
        $quote = $this->postJson('/api/v1/quotes', [
            'pickup' => self::PICKUP,
            'destination' => self::DEST,
        ]);

        $quote->assertOk()->assertJsonStructure([
            'data' => ['quote_id', 'services', 'expires_at', 'route'],
        ]);

        $quoteId = $quote->json('data.quote_id');

        $this->assertNotEmpty($quote->json('data.services'), 'Harus ada pilihan layanan.');

        // --- 2. Buat order ---
        $order = $this->postJson('/api/v1/orders', [
            'quote_id' => $quoteId,
            'service_code' => 'ride_bike',
            'payment_method' => 'cash',
            'pickup_address' => 'Jl. Putri Hijau No. 1, Medan',
            'destination_address' => 'Sun Plaza, Medan',
        ], ['Idempotency-Key' => 'kunci-order-0123456789ab']);

        $order->assertStatus(201)
            ->assertJsonPath('data.status', 'searching')
            ->assertJsonStructure(['data' => ['uuid', 'order_number', 'fare' => ['total', 'lines']]]);

        $orderUuid = $order->json('data.uuid');

        // --- 3. Order aktif terbaca ---
        $this->getJson('/api/v1/orders/active')
            ->assertOk()
            ->assertJsonPath('data.uuid', $orderUuid);

        // --- 4. Riwayat memuatnya ---
        $this->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonPath('data.0.uuid', $orderUuid);
    }

    public function test_pembuatan_order_idempotent(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $quoteId = $this->postJson('/api/v1/quotes', [
            'pickup' => self::PICKUP,
            'destination' => self::DEST,
        ])->json('data.quote_id');

        $payload = [
            'quote_id' => $quoteId,
            'service_code' => 'ride_bike',
            'payment_method' => 'cash',
            'pickup_address' => 'Jl. Uji',
        ];
        $header = ['Idempotency-Key' => 'kunci-ganda-0123456789abcd'];

        $pertama = $this->postJson('/api/v1/orders', $payload, $header);
        $pertama->assertStatus(201);

        // Penumpang menekan "Pesan" dua kali karena responsnya lambat.
        $kedua = $this->postJson('/api/v1/orders', $payload, $header);

        $kedua->assertStatus(201)
            ->assertHeader('Idempotency-Replayed', 'true')
            ->assertJsonPath('data.uuid', $pertama->json('data.uuid'));

        $this->assertSame(
            1,
            Order::query()->count(),
            'Dua tekanan tombol tidak boleh menghasilkan dua order.'
        );
    }

    public function test_pembuatan_order_tanpa_idempotency_key_ditolak(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $quoteId = $this->postJson('/api/v1/quotes', [
            'pickup' => self::PICKUP,
            'destination' => self::DEST,
        ])->json('data.quote_id');

        $this->postJson('/api/v1/orders', [
            'quote_id' => $quoteId,
            'service_code' => 'ride_bike',
            'payment_method' => 'cash',
            'pickup_address' => 'Jl. Uji',
        ])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED');
    }

    public function test_quote_pengguna_lain_tidak_bisa_dibaca(): void
    {
        $pemilik = User::factory()->create();
        $penyusup = User::factory()->create();

        Sanctum::actingAs($pemilik);
        $quoteId = $this->postJson('/api/v1/quotes', [
            'pickup' => self::PICKUP,
            'destination' => self::DEST,
        ])->json('data.quote_id');

        Sanctum::actingAs($penyusup);

        $this->getJson("/api/v1/quotes/{$quoteId}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'QUOTE_EXPIRED');
    }

    public function test_order_pengguna_lain_tampak_tidak_ada(): void
    {
        $pemilik = User::factory()->create();
        $penyusup = User::factory()->create();

        $order = Order::factory()->create(['user_id' => $pemilik->id]);

        Sanctum::actingAs($penyusup);

        $this->getJson("/api/v1/orders/{$order->uuid}")
            ->assertStatus(404, '404, bukan 403: 403 mengonfirmasi bahwa uuid-nya memang ada.');
    }

    // =========================================================================
    //  Kebocoran data
    // =========================================================================

    public function test_kode_jemput_tidak_pernah_terkirim_ke_driver(): void
    {
        $user = User::factory()->create();
        $driver = $this->driverSiapKerja();

        $order = Order::factory()->accepted($driver->id)->create([
            'user_id' => $user->id,
            'pickup_code' => '4271',
        ]);

        Sanctum::actingAs($driver->user);

        $response = $this->getJson('/api/v1/driver/orders/active');
        $response->assertOk();

        $badan = $response->getContent();

        $this->assertStringNotContainsString(
            '4271',
            $badan,
            'Kode jemput yang bocor ke aplikasi driver menghapus seluruh gunanya: '
            .'driver bisa menandai "sudah menjemput" tanpa pernah bertemu penumpang.'
        );
        $this->assertStringNotContainsString('pickup_code', $badan);
    }

    public function test_penumpang_melihat_kode_jemputnya_sendiri(): void
    {
        $user = User::factory()->create();
        $driver = $this->driverSiapKerja();

        $order = Order::factory()->accepted($driver->id)->create([
            'user_id' => $user->id,
            'pickup_code' => '4271',
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/v1/orders/{$order->uuid}")
            ->assertOk()
            ->assertJsonPath('data.pickup_code', '4271');
    }

    public function test_kode_jemput_hilang_setelah_perjalanan_dimulai(): void
    {
        $user = User::factory()->create();
        $driver = $this->driverSiapKerja();

        $order = Order::factory()->inProgress($driver->id)->create([
            'user_id' => $user->id,
            'pickup_code' => '4271',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/orders/{$order->uuid}");

        $response->assertOk();
        $this->assertArrayNotHasKey(
            'pickup_code',
            $response->json('data'),
            'Kodenya sudah dipakai. Menampilkannya di riwayat order hanya '
            .'menambah data yang bisa dibaca orang lain yang memegang HP itu.'
        );
    }

    public function test_id_internal_tidak_bocor_di_profil(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/me');

        $response->assertOk();

        $data = $response->json('data');

        $this->assertArrayNotHasKey('id', $data);
        $this->assertArrayNotHasKey('password', $data);
        $this->assertArrayNotHasKey('referred_by_user_id', $data);
        $this->assertArrayHasKey('uuid', $data);
    }

    // =========================================================================
    //  Sisi driver
    // =========================================================================

    public function test_driver_online_lalu_menerima_order(): void
    {
        $driver = $this->driverSiapKerja();
        $this->fundDriver($driver, 50_000);

        Sanctum::actingAs($driver->user);

        // --- 1. Online ---
        $this->postJson('/api/v1/driver/online', self::PICKUP)
            ->assertOk()
            ->assertJsonPath('data.online', true);

        // --- 2. Status memuat ringkasan ---
        $this->getJson('/api/v1/driver/status')
            ->assertOk()
            ->assertJsonPath('data.online', true)
            ->assertJsonPath('data.wallet.can_take_cash_orders', true);

        // --- 3. Ada order dan gelombang penawaran dijalankan ---
        $order = Order::factory()->forDistance(4_000)->create([
            'pickup_lat' => self::PICKUP['lat'],
            'pickup_lng' => self::PICKUP['lng'],
            'zone_id' => (int) DB::table('zones')->value('id'),
        ]);

        app(DispatchOfferWave::class)->handle($order, 1);

        // --- 4. Driver melihat penawarannya ---
        $this->getJson('/api/v1/driver/orders/offers')
            ->assertOk()
            ->assertJsonPath('data.0.order_uuid', (string) $order->uuid);

        // --- 5. Driver menerima ---
        $this->postJson("/api/v1/driver/orders/{$order->uuid}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');
    }

    public function test_driver_tidak_bisa_menerima_order_yang_tidak_ditawarkan(): void
    {
        $driver = $this->driverSiapKerja();
        $order = Order::factory()->create();

        Sanctum::actingAs($driver->user);

        $this->postJson("/api/v1/driver/orders/{$order->uuid}/accept")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'NO_OFFER_FOR_DRIVER');
    }

    public function test_pengguna_bukan_driver_ditolak_di_endpoint_driver(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/driver/status')->assertStatus(403);
    }

    public function test_driver_tidak_bisa_mengubah_order_driver_lain(): void
    {
        $driverA = $this->driverSiapKerja();
        $driverB = $this->driverSiapKerja();

        $order = Order::factory()->accepted($driverA->id)->create();

        Sanctum::actingAs($driverB->user);

        $this->patchJson("/api/v1/driver/orders/{$order->uuid}/status", [
            'status' => 'driver_arriving',
            'lat' => self::PICKUP['lat'],
            'lng' => self::PICKUP['lng'],
        ])->assertStatus(404);
    }

    public function test_transisi_status_menuntut_posisi(): void
    {
        $driver = $this->driverSiapKerja();
        $order = Order::factory()->accepted($driver->id)->create();

        Sanctum::actingAs($driver->user);

        $this->patchJson("/api/v1/driver/orders/{$order->uuid}/status", [
            'status' => 'driver_arriving',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['lat', 'lng']]]);
    }

    public function test_kode_jemput_salah_menolak_mulai_perjalanan(): void
    {
        $driver = $this->driverSiapKerja();

        $order = Order::factory()->create([
            'status' => 'driver_arrived',
            'driver_id' => $driver->id,
            'matched_at' => now()->subMinutes(5),
            'arrived_at' => now()->subMinute(),
            'pickup_code' => '4271',
        ]);

        Sanctum::actingAs($driver->user);

        $this->postJson("/api/v1/driver/orders/{$order->uuid}/start", [
            'pickup_code' => '0000',
            'lat' => self::PICKUP['lat'],
            'lng' => self::PICKUP['lng'],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_PICKUP_CODE');

        // Dan kode yang benar diterima.
        $this->postJson("/api/v1/driver/orders/{$order->uuid}/start", [
            'pickup_code' => '4271',
            'lat' => self::PICKUP['lat'],
            'lng' => self::PICKUP['lng'],
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');
    }

    // =========================================================================
    //  Dompet
    // =========================================================================

    public function test_dompet_menampilkan_tiga_angka(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/wallet')
            ->assertOk()
            ->assertJsonStructure(['data' => ['balance', 'held', 'total', 'is_frozen']]);
    }

    public function test_mutasi_tidak_menampilkan_baris_hold_dan_release(): void
    {
        $user = User::factory()->create();
        $this->fundWallet('user', (int) $user->id, 100_000);

        $wallet = Wallet::forOwner('user', (int) $user->id);

        app(HoldFunds::class)->handle(
            wallet: $wallet,
            amount: Money::of(25_000),
            referenceType: 'order',
            referenceId: 1,
        );

        Sanctum::actingAs($user);

        $mutasi = $this->getJson('/api/v1/wallet/transactions');
        $mutasi->assertOk();

        $tipe = collect($mutasi->json('data'))->pluck('type')->all();

        $this->assertNotContains(
            'hold',
            $tipe,
            'Baris hold benar secara pembukuan tapi membuat pengguna menyimpulkan '
            .'dia dipotong dua kali untuk satu perjalanan.'
        );
        $this->assertContains('topup', $tipe);
    }

    // =========================================================================
    //  Pembantu
    // =========================================================================

    private function driverSiapKerja(): Driver
    {
        $driver = Driver::factory()->create();
        Vehicle::factory()->create(['driver_id' => $driver->id]);

        DB::table('driver_service_eligibility')->insert([
            'driver_id' => $driver->id,
            'service_type_id' => (int) DB::table('service_types')->where('code', 'ride_bike')->value('id'),
            'is_enabled' => true,
            'enabled_by_driver' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $driver->fresh()->load('user');
    }

    private function fundDriver(Driver $driver, int $amount): void
    {
        $this->fundWallet('driver', (int) $driver->id, $amount);
    }

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
