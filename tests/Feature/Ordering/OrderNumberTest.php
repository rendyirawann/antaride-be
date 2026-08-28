<?php

declare(strict_types=1);

namespace Tests\Feature\Ordering;

use App\Domain\Identity\Models\User;
use App\Domain\Ordering\Models\Order;
use App\Domain\Shared\Support\BusinessClock;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ============================================================================
 *  YANG DIUJI: APAKAH PENGULANGAN SETELAH TABRAKAN BISA BERHASIL
 * ============================================================================
 *  Nomor order punya unique constraint, jadi tabrakan selalu ketangkap. Yang
 *  menentukan apakah sistem tetap hidup adalah apakah percobaan BERIKUTNYA
 *  menghasilkan nomor yang berbeda.
 *
 *  Rumus lama (`count() + 1`) adalah fungsi murni dari jumlah baris, jadi
 *  begitu jumlah baris berhenti sejalan dengan nomor tertinggi — satu order
 *  dihapus sudah cukup — setiap percobaan menghasilkan nomor yang sama persis
 *  dan pembuatan order mati untuk sisa hari itu.
 * ============================================================================
 */
class OrderNumberTest extends TestCase
{
    use RefreshDatabase;

    public function test_nomor_pertama_hari_itu(): void
    {
        $nomor = Order::generateOrderNumber();

        $tanggal = BusinessClock::now()->format('Ymd');

        $this->assertSame("RD-{$tanggal}-000001", $nomor);
    }

    public function test_nomor_berurut(): void
    {
        $this->buatOrderDenganNomor('RD-'.BusinessClock::now()->format('Ymd').'-000001');
        $this->buatOrderDenganNomor('RD-'.BusinessClock::now()->format('Ymd').'-000002');

        $tanggal = BusinessClock::now()->format('Ymd');

        $this->assertSame("RD-{$tanggal}-000003", Order::generateOrderNumber());
    }

    /**
     * INI kasus yang membuat rumus lama mati.
     */
    public function test_order_yang_dihapus_tidak_membuat_nomor_terulang(): void
    {
        $tanggal = BusinessClock::now()->format('Ymd');

        foreach (range(1, 5) as $i) {
            $this->buatOrderDenganNomor(sprintf('RD-%s-%06d', $tanggal, $i));
        }

        // Satu order dihapus. Jumlah baris jadi 4, nomor tertinggi tetap 5.
        DB::table('orders')->where('order_number', "RD-{$tanggal}-000003")->delete();

        $this->assertSame(
            4,
            DB::table('orders')->count(),
            'Prasyarat: jumlah baris memang sudah berbeda dari nomor tertinggi.'
        );

        $nomor = Order::generateOrderNumber();

        $this->assertSame(
            "RD-{$tanggal}-000006",
            $nomor,
            'Rumus lama menghasilkan 000005 di sini — nomor yang SUDAH ADA. '
            .'Setiap pengulangan akan menghasilkan angka yang sama dan pembuatan '
            .'order mati untuk sisa hari.'
        );

        // Dan nomor itu memang belum dipakai.
        $this->assertSame(
            0,
            DB::table('orders')->where('order_number', $nomor)->count()
        );
    }

    public function test_pengulangan_setelah_tabrakan_menghasilkan_nomor_berbeda(): void
    {
        $tanggal = BusinessClock::now()->format('Ymd');

        // Dua proses menghitung nomor yang sama sebelum salah satu menyimpan.
        $nomorA = Order::generateOrderNumber();
        $nomorB = Order::generateOrderNumber();

        $this->assertSame($nomorA, $nomorB, 'Prasyarat: keduanya memang bertabrakan.');

        // A menyimpan lebih dulu.
        $this->buatOrderDenganNomor($nomorA);

        // B mengulang. Ini yang HARUS berbeda.
        $nomorBUlang = Order::generateOrderNumber();

        $this->assertNotSame(
            $nomorA,
            $nomorBUlang,
            'Pengulangan yang menghasilkan nomor yang sama berarti pengulangan '
            .'itu tidak akan pernah berhasil.'
        );

        $this->assertSame("RD-{$tanggal}-000002", $nomorBUlang);
    }

    public function test_nomor_dihitung_per_hari_bisnis(): void
    {
        $tanggalIni = BusinessClock::now()->format('Ymd');

        // Order kemarin tidak boleh mempengaruhi urutan hari ini.
        $this->buatOrderDenganNomor(
            'RD-'.BusinessClock::now()->subDay()->format('Ymd').'-000042',
            createdAt: now()->subDay(),
        );

        $this->assertSame(
            "RD-{$tanggalIni}-000001",
            Order::generateOrderNumber(),
            'Urutan harus dimulai ulang setiap hari bisnis.'
        );
    }

    public function test_prefix_dengan_tanda_hubung_ditolak(): void
    {
        // Tanda hubung adalah pemisah bagian nomor. Prefix yang memuatnya akan
        // membuat split_part membaca bagian yang salah, dan urutannya diam-diam
        // selalu 1 — artinya setiap order kedua akan bertabrakan selamanya.
        config(['antaride.brand.order_number_prefix' => 'RD-ID']);

        $this->expectException(\LogicException::class);

        Order::generateOrderNumber();
    }

    // =========================================================================

    private function buatOrderDenganNomor(string $nomor, ?\DateTimeInterface $createdAt = null): void
    {
        $createdAt ??= now();

        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid7(),
            'phone' => '628'.random_int(1000000000, 9999999999),
            'name' => 'Penguji',
            'status' => 'active',
            'referral_code' => strtoupper(Str::random(8)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $serviceTypeId = (int) DB::table('service_types')->value('id');

        DB::table('orders')->insert([
            'uuid' => (string) Str::uuid7(),
            'order_number' => $nomor,
            'user_id' => $userId,
            'service_type_id' => $serviceTypeId,
            'status' => 'searching',
            'payment_method' => 'cash',
            'distance_m' => 3000,
            'duration_s' => 600,
            'base_fare' => 4000,
            'distance_fare' => 3600,
            'platform_fee' => 1000,
            'total_fare' => 8600,
            'driver_earning' => 6340,
            'commission_amount' => 1260,
            'pickup_address' => 'Jl. Uji',
            'pickup_lat' => 3.5952,
            'pickup_lng' => 98.6722,
            'requested_at' => $createdAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // generateOrderNumber butuh minimal satu service_type untuk order uji.
        if (DB::table('service_types')->count() === 0) {
            $this->seed(CatalogSeeder::class);
        }

        // Pastikan User class ikut dimuat supaya factory-nya terdaftar; test ini
        // memakai DB::table langsung untuk mengontrol order_number secara persis.
        class_exists(User::class);
    }
}
