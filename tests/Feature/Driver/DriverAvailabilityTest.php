<?php

declare(strict_types=1);

namespace Tests\Feature\Driver;

use App\Domain\Driver\Actions\GoOffline;
use App\Domain\Driver\Actions\GoOnline;
use App\Domain\Driver\Exceptions\DriverNotEligibleException;
use App\Domain\Driver\Models\Driver;
use App\Domain\Driver\Models\Vehicle;
use App\Domain\Matching\Contracts\DriverLocationIndex;
use App\Domain\Ordering\Exceptions\DriverBusyException;
use App\Domain\Ordering\Models\Order;
use App\Domain\Shared\ValueObjects\Coordinate;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\SystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DriverAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private Coordinate $lokasi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSeeder::class);
        $this->seed(CatalogSeeder::class);

        // Lapangan Merdeka Medan, di dalam zona Medan Kota.
        $this->lokasi = Coordinate::of(3.5952, 98.6722);
    }

    // =========================================================================
    //  Online
    // =========================================================================

    public function test_driver_aktif_bisa_online(): void
    {
        $driver = $this->driverSiapKerja();

        $session = app(GoOnline::class)->handle($driver, $this->lokasi);

        $this->assertNotNull($session);
        $this->assertNull($session->ended_at);
        $this->assertSame((int) $driver->id, (int) $session->driver_id);
    }

    public function test_online_mendaftarkan_driver_di_indeks_ketersediaan(): void
    {
        $driver = $this->driverSiapKerja();

        app(GoOnline::class)->handle($driver, $this->lokasi);

        $tersedia = app(DriverLocationIndex::class)
            ->availableDriverIds('ride_bike', $this->zoneIds());

        $this->assertContains(
            (int) $driver->id,
            $tersedia,
            'Tanpa terdaftar di indeks Redis, driver tidak akan pernah muncul '
            .'sebagai kandidat matching walaupun sesi kerjanya terbuka.'
        );
    }

    public function test_online_dua_kali_tidak_membuat_sesi_ganda(): void
    {
        $driver = $this->driverSiapKerja();

        $pertama = app(GoOnline::class)->handle($driver, $this->lokasi);
        $kedua = app(GoOnline::class)->handle($driver->fresh(), $this->lokasi);

        $this->assertSame(
            (int) $pertama->id,
            (int) $kedua->id,
            'Menekan tombol online dua kali sering terjadi saat sinyal jelek. '
            .'Itu tidak boleh menghasilkan dua sesi kerja.'
        );

        $this->assertSame(1, DB::table('driver_sessions')->count());
    }

    public function test_driver_belum_terverifikasi_tidak_bisa_online(): void
    {
        $driver = Driver::factory()->pendingReview()->create();
        Vehicle::factory()->create(['driver_id' => $driver->id]);

        try {
            app(GoOnline::class)->handle($driver, $this->lokasi);
            $this->fail('Seharusnya ditolak.');
        } catch (DriverNotEligibleException $e) {
            $this->assertSame(403, $e->httpStatus());
            $this->assertStringContainsString('diperiksa', $e->getMessage());
        }
    }

    public function test_driver_ditangguhkan_tidak_bisa_online(): void
    {
        $driver = Driver::factory()->suspended()->create();

        $this->expectException(DriverNotEligibleException::class);

        app(GoOnline::class)->handle($driver, $this->lokasi);
    }

    public function test_dokumen_kadaluarsa_menghalangi_online(): void
    {
        $driver = $this->driverSiapKerja();

        DB::table('driver_documents')->insert([
            'uuid' => (string) Str::uuid7(),
            'driver_id' => $driver->id,
            'type' => 'sim',
            'file_path' => 'kyc/uji-sim.jpg',
            'status' => 'approved',
            'expires_at' => now()->subMonth(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(GoOnline::class)->handle($driver, $this->lokasi);
            $this->fail('Seharusnya ditolak.');
        } catch (DriverNotEligibleException $e) {
            $this->assertSame('EXPIRED_DOCUMENTS', $e->reasonCode);
            $this->assertStringContainsString(
                'kadaluarsa',
                $e->getMessage(),
                'Pesannya harus menyebut penyebabnya, supaya driver bisa '
                .'menyelesaikannya sendiri tanpa menelepon CS.'
            );
        }
    }

    public function test_tanpa_layanan_aktif_tidak_bisa_online(): void
    {
        $driver = Driver::factory()->create();
        Vehicle::factory()->create(['driver_id' => $driver->id]);
        // Tidak ada baris driver_service_eligibility.

        try {
            app(GoOnline::class)->handle($driver, $this->lokasi);
            $this->fail('Seharusnya ditolak.');
        } catch (DriverNotEligibleException $e) {
            $this->assertSame('NO_SERVICE_ENABLED', $e->reasonCode);
        }
    }

    public function test_saklar_driver_sendiri_dihormati(): void
    {
        $driver = $this->driverSiapKerja();

        // Driver mematikan layanan ini untuk hari ini. Haknya tetap ada
        // (is_enabled true), tapi dia memilih tidak menerimanya.
        DB::table('driver_service_eligibility')
            ->where('driver_id', $driver->id)
            ->update(['enabled_by_driver' => false]);

        try {
            app(GoOnline::class)->handle($driver, $this->lokasi);
            $this->fail('Seharusnya ditolak.');
        } catch (DriverNotEligibleException $e) {
            $this->assertSame('NO_SERVICE_ENABLED', $e->reasonCode);
        }
    }

    public function test_di_luar_area_layanan_tidak_bisa_online(): void
    {
        $driver = $this->driverSiapKerja();

        // Jakarta, jauh di luar zona Medan.
        try {
            app(GoOnline::class)->handle($driver, Coordinate::of(-6.2088, 106.8456));
            $this->fail('Seharusnya ditolak.');
        } catch (DriverNotEligibleException $e) {
            $this->assertSame('OUTSIDE_SERVICE_AREA', $e->reasonCode);
        }
    }

    public function test_kelas_kendaraan_membatasi_layanan(): void
    {
        $driver = $this->driverSiapKerja();

        // Driver bermotor diberi hak untuk ride_car juga — kesalahan data yang
        // bisa terjadi. Kendaraan yang tidak cocok harus tetap menyaringnya.
        $rideCarId = DB::table('service_types')->where('code', 'ride_car')->value('id');

        if ($rideCarId !== null) {
            DB::table('driver_service_eligibility')->insert([
                'driver_id' => $driver->id,
                'service_type_id' => $rideCarId,
                'is_enabled' => true,
                'enabled_by_driver' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        app(GoOnline::class)->handle($driver, $this->lokasi);

        $index = app(DriverLocationIndex::class);

        $this->assertContains((int) $driver->id, $index->availableDriverIds('ride_bike', $this->zoneIds()));
        $this->assertNotContains(
            (int) $driver->id,
            $index->availableDriverIds('ride_car', $this->zoneIds()),
            'Penumpang yang memesan mobil tidak boleh dijemput sepeda motor.'
        );
    }

    // =========================================================================
    //  Offline
    // =========================================================================

    public function test_offline_menutup_sesi_dan_menghitung_jam_kerja(): void
    {
        $driver = $this->driverSiapKerja();
        $session = app(GoOnline::class)->handle($driver, $this->lokasi);

        // Mundurkan waktu mulai supaya jam kerjanya bisa diukur.
        DB::table('driver_sessions')
            ->where('id', $session->id)
            ->update(['started_at' => now()->subHours(3)]);

        $ditutup = app(GoOffline::class)->handle($driver->fresh());

        $this->assertNotNull($ditutup);
        $this->assertNotNull($ditutup->ended_at);
        $this->assertGreaterThanOrEqual(3 * 3600 - 5, (int) $ditutup->online_seconds);
        $this->assertLessThanOrEqual(3 * 3600 + 5, (int) $ditutup->online_seconds);
    }

    public function test_offline_mencabut_ketersediaan(): void
    {
        $driver = $this->driverSiapKerja();
        app(GoOnline::class)->handle($driver, $this->lokasi);

        app(GoOffline::class)->handle($driver->fresh());

        $this->assertNotContains(
            (int) $driver->id,
            app(DriverLocationIndex::class)->availableDriverIds('ride_bike', $this->zoneIds()),
        );
    }

    public function test_driver_dengan_order_berjalan_tidak_bisa_offline(): void
    {
        $driver = $this->driverSiapKerja();
        app(GoOnline::class)->handle($driver, $this->lokasi);

        Order::factory()->inProgress($driver->id)->create();

        $this->expectException(DriverBusyException::class);

        app(GoOffline::class)->handle($driver->fresh());
    }

    public function test_admin_bisa_memaksa_offline(): void
    {
        $driver = $this->driverSiapKerja();
        app(GoOnline::class)->handle($driver, $this->lokasi);
        Order::factory()->inProgress($driver->id)->create();

        // Jalur intervensi ops: HP driver mati total, ordernya menggantung.
        $ditutup = app(GoOffline::class)->handle($driver->fresh(), force: true);

        $this->assertNotNull($ditutup->ended_at);
    }

    public function test_offline_dua_kali_tidak_error(): void
    {
        $driver = $this->driverSiapKerja();
        app(GoOnline::class)->handle($driver, $this->lokasi);

        app(GoOffline::class)->handle($driver->fresh());

        $this->assertNull(
            app(GoOffline::class)->handle($driver->fresh()),
            'Menekan tombol offline dua kali tidak boleh error; yang kedua '
            .'cukup mengembalikan null.'
        );
    }

    // =========================================================================

    /**
     * Id zona yang benar-benar ada.
     *
     * TIDAK di-hardcode [1,2,3]. RefreshDatabase membungkus setiap test dalam
     * transaksi yang di-rollback, tapi sequence PostgreSQL TIDAK ikut kembali —
     * jadi CatalogSeeder menghasilkan id zona yang naik terus antar test.
     * Angka yang di-hardcode akan benar untuk test pertama dan salah untuk
     * semua sisanya, dan kegagalannya berbunyi "array tidak memuat 2" yang
     * tidak menjelaskan apa pun.
     *
     * @return array<int, int>
     */
    private function zoneIds(): array
    {
        return DB::table('zones')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

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
        // membersihkannya. Sisa dari satu test akan membuat test berikutnya
        // gagal karena alasan yang tidak ada hubungannya dengan yang diuji.
        foreach (Driver::query()->pluck('id') as $driverId) {
            app(DriverLocationIndex::class)->forget((int) $driverId);
            app(DriverLocationIndex::class)->markUnavailableEverywhere((int) $driverId);
        }

        parent::tearDown();
    }
}
