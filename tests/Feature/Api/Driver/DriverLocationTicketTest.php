<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Driver;

use App\Domain\Driver\Models\Driver;
use App\Domain\Driver\Models\Vehicle;
use App\Domain\Driver\Support\LocationTicket;
use App\Domain\Matching\Contracts\DriverLocationIndex;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\SystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ============================================================================
 *  TANPA TIKET LOKASI, DRIVER ONLINE YANG TIDAK PERNAH DAPAT ORDER
 * ============================================================================
 *  Tiket lokasi adalah satu-satunya cara aplikasi driver bisa mengirim posisi:
 *  ping GPS tidak pergi ke Laravel, dia ke layanan Go, dan layanan itu hanya
 *  menerima permintaan bertiket.
 *
 *  Kalau tiketnya tidak ada, tidak ada satu pun posisi yang terkirim. TTL 60
 *  detik di Redis habis, driver keluar dari indeks ketersediaan, dan tidak ada
 *  tawaran yang masuk — sementara layarnya tetap menyatakan dia online.
 *
 *  Itu kegagalan yang paling mahal di aplikasi driver, dan yang paling senyap:
 *  tidak ada galat, tidak ada log, tidak ada apa pun yang bisa dia hubungkan
 *  dengan penyebabnya.
 * ============================================================================
 *
 * ============================================================================
 *  YANG DIPERBAIKI TEST INI: APLIKASI YANG DI-RESTART
 * ============================================================================
 *  Tiket awalnya HANYA dikirim `POST /driver/online`. Akibatnya driver yang
 *  aplikasinya ditutup Android karena kehabisan memori — atau yang menutupnya
 *  sendiri lalu membukanya lagi — kembali ke aplikasi yang menyatakan dia masih
 *  online, tanpa tiket, dan tanpa cara mendapatkannya.
 *
 *  Satu-satunya jalan keluar sebelumnya adalah menekan offline lalu online lagi,
 *  yang menutup sesinya dan memotong catatan jam kerjanya — dan tidak ada alasan
 *  bagi driver untuk menduga itu yang perlu dia lakukan.
 * ============================================================================
 */
class DriverLocationTicketTest extends TestCase
{
    use RefreshDatabase;

    private const LOKASI = ['lat' => 3.5952, 'lng' => 98.6722];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSeeder::class);
        $this->seed(CatalogSeeder::class);
    }

    public function test_online_mengirim_alamat_dan_tiket_lokasi(): void
    {
        $driver = $this->driverSiapKerja();

        Sanctum::actingAs($driver->user);

        $response = $this->postJson('/api/v1/driver/online', self::LOKASI);

        $response->assertOk();

        $lokasi = $response->json('data.location');

        $this->assertIsArray($lokasi, 'Response online tidak memuat blok `location`.');
        $this->assertNotEmpty($lokasi['url']);
        $this->assertNotEmpty($lokasi['ticket']);
    }

    /**
     * Tiket dari `online` benar-benar sah, bukan sekadar string tidak kosong.
     *
     * Yang dijaga: tiket yang bentuknya benar tapi tanda tangannya salah. Layanan
     * lokasi akan menolaknya 401 di setiap ping, dan aplikasi driver hanya
     * menampilkan "posisi belum terkirim" — tanpa menyebut tiketnya.
     */
    public function test_tiket_dari_online_lolos_verifikasi(): void
    {
        $driver = $this->driverSiapKerja();

        Sanctum::actingAs($driver->user);

        $tiket = (string) $this->postJson('/api/v1/driver/online', self::LOKASI)
            ->json('data.location.ticket');

        $isi = LocationTicket::verify($tiket);

        $this->assertNotNull($isi, 'Tiket dari endpoint online tidak lolos verifikasi.');
        $this->assertSame((int) $driver->id, (int) $isi['driver_id']);

        $this->assertContains(
            'ride_bike',
            $isi['services'],
            'Tiket tidak memuat layanan aktif driver. Layanan lokasi hanya bisa '
            .'MEMPERSEMPIT daftar di tiket — daftar yang kosong berarti posisinya '
            .'tidak dicatat untuk layanan apa pun.',
        );
    }

    /**
     * ========================================================================
     *  INI TEST YANG PALING PENTING DI BERKAS INI
     * ========================================================================
     *  `GET /driver/status` harus mengirim tiket untuk driver yang sesinya masih
     *  terbuka. Itu satu-satunya jalan aplikasi yang baru di-restart bisa
     *  melanjutkan pengiriman posisi tanpa menutup sesi driver.
     * ========================================================================
     */
    public function test_status_mengirim_tiket_untuk_driver_yang_masih_online(): void
    {
        $driver = $this->driverSiapKerja();

        Sanctum::actingAs($driver->user);

        $this->postJson('/api/v1/driver/online', self::LOKASI)->assertOk();

        // Aplikasi di-restart: yang dipanggil hanya `status`, tanpa `online`.
        $response = $this->getJson('/api/v1/driver/status');

        $response->assertOk();

        $this->assertTrue((bool) $response->json('data.online'));

        $lokasi = $response->json('data.location');

        $this->assertIsArray(
            $lokasi,
            'Endpoint status tidak mengirim tiket lokasi. Driver yang '
            .'aplikasinya di-restart tidak akan pernah mengirim posisi lagi '
            .'sampai dia menekan offline lalu online — dan itu memotong catatan '
            .'jam kerjanya.',
        );

        $isi = LocationTicket::verify((string) $lokasi['ticket']);

        $this->assertNotNull($isi);
        $this->assertSame((int) $driver->id, (int) $isi['driver_id']);
    }

    /**
     * Driver yang TIDAK online tidak diberi tiket.
     *
     * Tiket untuk driver yang tidak bekerja adalah kemampuan mencatat posisinya
     * sebagai tersedia. Kalau aplikasi menyimpannya lalu mengirim ping — misalnya
     * karena timer yang terlewat dibatalkan — driver yang sudah pulang akan
     * mendapat tawaran.
     */
    public function test_status_tidak_mengirim_tiket_untuk_driver_offline(): void
    {
        $driver = $this->driverSiapKerja();

        Sanctum::actingAs($driver->user);

        $response = $this->getJson('/api/v1/driver/status');

        $response->assertOk();

        $this->assertFalse((bool) $response->json('data.online'));

        $this->assertNull(
            $response->json('data.location'),
            'Driver yang tidak bekerja diberi tiket lokasi. Posisinya bisa '
            .'tercatat tersedia setelah dia pulang.',
        );
    }

    /**
     * Tiket hilang lagi setelah driver offline.
     *
     * Bukan hal yang sama dengan test di atas: yang ini memeriksa peralihannya.
     * Driver yang pernah online lalu offline melewati jalur kode yang berbeda
     * dari driver yang belum pernah online sama sekali.
     */
    public function test_tiket_hilang_setelah_offline(): void
    {
        $driver = $this->driverSiapKerja();

        Sanctum::actingAs($driver->user);

        $this->postJson('/api/v1/driver/online', self::LOKASI)->assertOk();

        $this->assertIsArray($this->getJson('/api/v1/driver/status')->json('data.location'));

        $this->postJson('/api/v1/driver/offline')->assertOk();

        $this->assertNull(
            $this->getJson('/api/v1/driver/status')->json('data.location'),
            'Tiket masih dikirim setelah driver offline.',
        );
    }

    /**
     * Layanan yang dimatikan driver TIDAK masuk tiket.
     *
     * Ini yang menghormati sakelar per-layanan di aplikasi. Driver yang mematikan
     * `ride_car` karena mobilnya dipakai keluarga tidak boleh tetap mendapat
     * tawaran mobil — dan yang menentukannya adalah daftar di dalam tiket, bukan
     * penyaringan di aplikasi.
     */
    public function test_layanan_yang_dimatikan_driver_tidak_masuk_tiket(): void
    {
        $driver = $this->driverSiapKerja();

        // Layanan kedua ditambahkan, lalu dimatikan driver sendiri.
        $rideCarId = (int) DB::table('service_types')->where('code', 'ride_car')->value('id');

        DB::table('driver_service_eligibility')->insert([
            'driver_id' => $driver->id,
            'service_type_id' => $rideCarId,
            'is_enabled' => true,
            'enabled_by_driver' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($driver->user);

        $tiket = (string) $this->postJson('/api/v1/driver/online', self::LOKASI)
            ->json('data.location.ticket');

        $isi = LocationTicket::verify($tiket);

        $this->assertNotNull($isi);

        $this->assertNotContains(
            'ride_car',
            $isi['services'],
            'Layanan yang dimatikan driver sendiri tetap masuk tiket. Dia akan '
            .'mendapat tawaran untuk layanan yang menurut layarnya sudah mati.',
        );
    }

    /**
     * `url` menunjuk endpoint ping, bukan hanya host layanannya.
     *
     * Aplikasi memakai nilai ini apa adanya — dia tidak menambahkan path apa pun.
     * URL yang hanya berisi host akan menghasilkan 404 di setiap ping, dan yang
     * terlihat di aplikasi hanya "posisi belum terkirim".
     */
    public function test_url_menunjuk_endpoint_ping(): void
    {
        $driver = $this->driverSiapKerja();

        Sanctum::actingAs($driver->user);

        $url = (string) $this->postJson('/api/v1/driver/online', self::LOKASI)
            ->json('data.location.url');

        $this->assertStringEndsWith('/v1/ping', $url);
        $this->assertStringStartsWith('http', $url);
    }

    // =========================================================================

    private function driverSiapKerja(): Driver
    {
        $driver = Driver::factory()->create();
        Vehicle::factory()->create(['driver_id' => $driver->id]);

        $rideBikeId = (int) DB::table('service_types')->where('code', 'ride_bike')->value('id');

        DB::table('driver_service_eligibility')->insert([
            'driver_id' => $driver->id,
            'service_type_id' => $rideBikeId,
            'is_enabled' => true,
            'enabled_by_driver' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $driver->fresh();
    }

    protected function tearDown(): void
    {
        // Indeks ketersediaan hidup di Redis, jadi RefreshDatabase tidak
        // membersihkannya. Sisa dari satu test akan membuat test berikutnya gagal
        // karena alasan yang tidak ada hubungannya dengan yang diuji.
        foreach (Driver::query()->pluck('id') as $driverId) {
            app(DriverLocationIndex::class)->forget((int) $driverId);
            app(DriverLocationIndex::class)->markUnavailableEverywhere((int) $driverId);
        }

        parent::tearDown();
    }
}
