<?php

declare(strict_types=1);

namespace Tests\Feature\Metrics;

use App\Domain\Driver\Models\Driver;
use App\Domain\Metrics\Actions\AggregateDailyMetrics;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Shared\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\SystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ============================================================================
 *  YANG DIJAGA DI SINI ADALAH ANGKANYA, BUKAN BAHWA JOB-NYA JALAN
 * ============================================================================
 *  Agregasi yang berjalan tanpa galat tapi menghasilkan angka salah adalah
 *  kegagalan yang paling sulit dilihat: dashboard menampilkan grafik yang
 *  meyakinkan, tim ops mengambil keputusan dari grafik itu, dan tidak ada
 *  satu pun galat di log.
 *
 *  Karena itu setiap test di bawah menyatakan angka yang PERSIS, dan setiap
 *  angka itu dihitung dari data yang dibuat test-nya sendiri.
 * ============================================================================
 */
class AggregateDailyMetricsTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $hari;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSeeder::class);
        $this->seed(CatalogSeeder::class);

        // Kemarin, di zona bisnis. Dipakai supaya rentang harinya sudah pasti
        // tertutup dan tidak bergantung jam saat test dijalankan.
        $this->hari = CarbonImmutable::instance(BusinessClock::now()->subDay())
            ->startOfDay();
    }

    // =========================================================================
    //  metrics_daily
    // =========================================================================

    public function test_menghitung_jumlah_order_per_status(): void
    {
        $this->orderPada($this->hari, OrderStatus::Completed);
        $this->orderPada($this->hari, OrderStatus::Completed);
        $this->orderPada($this->hari, OrderStatus::Cancelled);
        $this->orderPada($this->hari, OrderStatus::NoDriver);

        app(AggregateDailyMetrics::class)->handle($this->hari);

        $baris = $this->barisTotal();

        $this->assertSame(4, (int) $baris->orders_created);
        $this->assertSame(2, (int) $baris->orders_completed);
        $this->assertSame(1, (int) $baris->orders_cancelled);
        $this->assertSame(1, (int) $baris->orders_no_driver);
    }

    /**
     * GMV HANYA dari order yang selesai.
     *
     * ========================================================================
     *  INI KESALAHAN YANG PALING MUDAH TERJADI
     * ========================================================================
     *  Order yang dibatalkan punya nominal ongkos di barisnya — dihitung saat
     *  order dibuat — tapi tidak pernah ditagih.
     *
     *  Kalau ikut dijumlahkan, GMV akan NAIK setiap kali ada pembatalan. Grafik
     *  pendapatan akan terlihat paling bagus tepat pada hari yang paling buruk,
     *  dan tidak ada yang akan mencurigainya.
     * ========================================================================
     */
    public function test_gmv_hanya_dari_order_selesai(): void
    {
        $this->orderPada($this->hari, OrderStatus::Completed, totalFare: 20_000);
        $this->orderPada($this->hari, OrderStatus::Completed, totalFare: 30_000);

        // Order batal dengan ongkos BESAR. Kalau ikut terhitung, GMV jadi
        // 150.000 alih-alih 50.000.
        $this->orderPada($this->hari, OrderStatus::Cancelled, totalFare: 100_000);

        app(AggregateDailyMetrics::class)->handle($this->hari);

        $this->assertSame(
            50_000,
            (int) $this->barisTotal()->gmv,
            'GMV harus 50.000 (dua order selesai). Kalau 150.000, order batal '
            .'ikut dijumlahkan — dan pendapatan akan naik setiap ada pembatalan.',
        );
    }

    public function test_pendapatan_driver_dan_komisi_dari_order_selesai(): void
    {
        $this->orderPada(
            $this->hari,
            OrderStatus::Completed,
            totalFare: 20_000,
            driverEarning: 16_000,
            commission: 4_000,
        );

        $this->orderPada(
            $this->hari,
            OrderStatus::Cancelled,
            totalFare: 50_000,
            driverEarning: 40_000,
            commission: 10_000,
        );

        app(AggregateDailyMetrics::class)->handle($this->hari);

        $baris = $this->barisTotal();

        $this->assertSame(16_000, (int) $baris->driver_earning);
        $this->assertSame(4_000, (int) $baris->commission);
    }

    /**
     * Order yang tidak pernah dapat driver TIDAK dihitung sebagai waktu tunggu.
     *
     * Memasukkannya sebagai nol akan MENURUNKAN rata-rata waktu tunggu justru
     * pada hari yang paling buruk — hari dengan banyak order tanpa driver akan
     * terlihat sebagai hari dengan pencocokan tercepat.
     */
    public function test_waktu_tunggu_mengabaikan_order_tanpa_driver(): void
    {
        // Dua order yang cocok: 60 detik dan 120 detik. Rata-rata 90.
        $this->orderPada($this->hari, OrderStatus::Completed, waitSeconds: 60);
        $this->orderPada($this->hari, OrderStatus::Completed, waitSeconds: 120);

        // Order tanpa driver: matched_at null.
        $this->orderPada($this->hari, OrderStatus::NoDriver);

        app(AggregateDailyMetrics::class)->handle($this->hari);

        $this->assertSame(
            90,
            (int) $this->barisTotal()->avg_wait_seconds,
            'Rata-rata harus 90 detik dari dua order yang cocok. Kalau 60, '
            .'order tanpa driver ikut dihitung sebagai tunggu nol.',
        );
    }

    /**
     * p90 menangkap ekor yang disembunyikan rata-rata.
     *
     * Sembilan order 10 detik dan satu order 300 detik: rata-ratanya 39 detik,
     * yang terlihat sehat. p90 yang menunjukkan bahwa ada penumpang yang
     * menunggu lima menit.
     */
    public function test_persentil_menangkap_ekor_waktu_tunggu(): void
    {
        for ($i = 0; $i < 9; $i++) {
            $this->orderPada($this->hari, OrderStatus::Completed, waitSeconds: 10);
        }

        $this->orderPada($this->hari, OrderStatus::Completed, waitSeconds: 300);

        app(AggregateDailyMetrics::class)->handle($this->hari);

        $baris = $this->barisTotal();

        $this->assertSame(10, (int) $baris->p50_wait_seconds);

        $this->assertGreaterThan(
            (int) $baris->p50_wait_seconds,
            (int) $baris->p90_wait_seconds,
            'p90 harus lebih besar dari p50 saat ada ekor. Kalau sama, '
            .'persentilnya tidak benar-benar dihitung.',
        );
    }

    public function test_pelanggan_unik_dihitung_sekali_walau_pesan_berkali(): void
    {
        $order = $this->orderPada($this->hari, OrderStatus::Completed);

        // Dua order lagi dari pengguna yang SAMA.
        $this->orderPada($this->hari, OrderStatus::Completed, userId: (int) $order->user_id);
        $this->orderPada($this->hari, OrderStatus::Completed, userId: (int) $order->user_id);

        app(AggregateDailyMetrics::class)->handle($this->hari);

        $this->assertSame(
            1,
            (int) $this->barisTotal()->unique_customers,
            'Tiga order dari satu pengguna adalah SATU pelanggan unik.',
        );
    }

    /**
     * Order di hari LAIN tidak boleh ikut terhitung.
     *
     * Batas harinya zona bisnis (Asia/Jakarta), bukan UTC. Kalau batasnya UTC,
     * order antara 00:00 dan 07:00 WIB akan masuk ke hari sebelumnya — dan
     * angka penutupan hari tidak akan pernah cocok dengan hitungan manual
     * tim ops.
     */
    public function test_hanya_menghitung_order_pada_hari_yang_diminta(): void
    {
        $this->orderPada($this->hari, OrderStatus::Completed);

        // Satu hari sebelum dan satu hari sesudah.
        $this->orderPada($this->hari->subDay(), OrderStatus::Completed);
        $this->orderPada($this->hari->addDay(), OrderStatus::Completed);

        app(AggregateDailyMetrics::class)->handle($this->hari);

        $this->assertSame(1, (int) $this->barisTotal()->orders_created);
    }

    /**
     * Order tepat di detik pertama dan terakhir hari bisnis IKUT terhitung.
     *
     * Batas rentang yang salah satu ujungnya eksklusif akan melewatkan order
     * tengah malam — dan jam-jam itu justru jam sibuk untuk ride-hailing.
     */
    public function test_order_di_batas_hari_ikut_terhitung(): void
    {
        [$mulai, $selesai] = BusinessClock::dayRange($this->hari);

        $this->orderPada($this->hari, OrderStatus::Completed, requestedAt: $mulai);
        $this->orderPada($this->hari, OrderStatus::Completed, requestedAt: $selesai);

        app(AggregateDailyMetrics::class)->handle($this->hari);

        $this->assertSame(
            2,
            (int) $this->barisTotal()->orders_created,
            'Order di detik pertama dan terakhir hari bisnis harus ikut. Kalau '
            .'hanya satu, salah satu ujung rentangnya eksklusif.',
        );
    }

    // =========================================================================
    //  Idempotensi
    // =========================================================================

    /**
     * ========================================================================
     *  DIJALANKAN DUA KALI HARUS MENGHASILKAN ANGKA YANG SAMA
     * ========================================================================
     *  Perintahnya berjalan tiap 15 menit untuk hari yang sama. Kalau
     *  agregasinya menambah alih-alih menimpa, angka di dashboard akan
     *  BERLIPAT setiap 15 menit — dan yang terlihat adalah pertumbuhan
     *  eksplosif yang tidak pernah terjadi.
     * ========================================================================
     */
    public function test_agregasi_ulang_menimpa_bukan_menambah(): void
    {
        $this->orderPada($this->hari, OrderStatus::Completed, totalFare: 25_000);

        app(AggregateDailyMetrics::class)->handle($this->hari);
        app(AggregateDailyMetrics::class)->handle($this->hari);
        app(AggregateDailyMetrics::class)->handle($this->hari);

        $baris = $this->barisTotal();

        $this->assertSame(1, (int) $baris->orders_created);
        $this->assertSame(25_000, (int) $baris->gmv);

        $this->assertSame(
            1,
            DB::table('metrics_daily')
                ->where('date', $this->hari->toDateString())
                ->whereNull('zone_id')
                ->whereNull('service_type_id')
                ->count(),
            'Harus tepat SATU baris total per tanggal. Lebih dari satu berarti '
            .'unique index dengan NULLS NOT DISTINCT tidak bekerja, dan grafik '
            .'akan membaca baris yang sembarang.',
        );
    }

    public function test_order_yang_dibatalkan_setelah_agregasi_ikut_terkoreksi(): void
    {
        $order = $this->orderPada($this->hari, OrderStatus::Completed, totalFare: 25_000);

        app(AggregateDailyMetrics::class)->handle($this->hari);

        $this->assertSame(25_000, (int) $this->barisTotal()->gmv);

        // Pembatalan terlambat — misalnya keputusan CS keesokan harinya.
        DB::table('orders')->where('id', $order->id)->update([
            'status' => OrderStatus::Cancelled->value,
            'cancelled_at' => now(),
        ]);

        app(AggregateDailyMetrics::class)->handle($this->hari);

        $this->assertSame(
            0,
            (int) $this->barisTotal()->gmv,
            'Agregasi ulang harus mengoreksi GMV. Kalau tetap 25.000, angka '
            .'lama tidak pernah diperbaiki dan laporan bulanan akan salah.',
        );
    }

    // =========================================================================
    //  Rincian per zona dan layanan
    // =========================================================================

    public function test_membuat_baris_rincian_per_zona_dan_per_layanan(): void
    {
        $this->orderPada($this->hari, OrderStatus::Completed);

        app(AggregateDailyMetrics::class)->handle($this->hari);

        $tanggal = $this->hari->toDateString();

        $this->assertSame(
            1,
            DB::table('metrics_daily')->where('date', $tanggal)
                ->whereNotNull('zone_id')->count(),
            'Harus ada baris per zona untuk perbandingan antar zona.',
        );

        $this->assertSame(
            1,
            DB::table('metrics_daily')->where('date', $tanggal)
                ->whereNotNull('service_type_id')->count(),
            'Harus ada baris per layanan untuk perbandingan antar layanan.',
        );

        /*
         * Kombinasi (zona X, layanan Y) sengaja TIDAK dibuat.
         *
         * Untuk 20 zona × 6 layanan itu 120 baris per hari yang belum satu pun
         * layar membacanya. Test ini menyatakan keputusan itu supaya siapa pun
         * yang menambahkannya nanti melakukannya dengan sadar.
         */
        $this->assertSame(
            0,
            DB::table('metrics_daily')->where('date', $tanggal)
                ->whereNotNull('zone_id')->whereNotNull('service_type_id')->count(),
        );
    }

    public function test_hari_tanpa_order_tetap_menghasilkan_baris_nol(): void
    {
        app(AggregateDailyMetrics::class)->handle($this->hari);

        $baris = $this->barisTotal();

        $this->assertNotNull(
            $baris,
            'Hari tanpa order tetap harus punya baris. Tanpa itu, grafik 14 hari '
            .'akan melompati hari sepi — dan lompatannya terbaca sebagai job '
            .'agregasi yang tidak jalan.',
        );

        $this->assertSame(0, (int) $baris->orders_created);
        $this->assertSame(0, (int) $baris->gmv);
    }

    // =========================================================================
    //  driver_daily_metrics
    // =========================================================================

    public function test_menghitung_pendapatan_driver_harian(): void
    {
        $driver = Driver::factory()->create();

        $this->orderPada(
            $this->hari,
            OrderStatus::Completed,
            driverId: (int) $driver->id,
            driverEarning: 16_000,
            commission: 4_000,
        );

        $this->orderPada(
            $this->hari,
            OrderStatus::Completed,
            driverId: (int) $driver->id,
            driverEarning: 12_000,
            commission: 3_000,
        );

        app(AggregateDailyMetrics::class)->handle($this->hari);

        $baris = DB::table('driver_daily_metrics')
            ->where('date', $this->hari->toDateString())
            ->where('driver_id', $driver->id)
            ->first();

        $this->assertNotNull($baris);
        $this->assertSame(2, (int) $baris->orders_completed);
        $this->assertSame(28_000, (int) $baris->gross_earning);
        $this->assertSame(7_000, (int) $baris->commission_paid);

        /*
         * `driver_earning` di tabel orders SUDAH bersih dari komisi.
         *
         * Jadi net = bruto. Mengurangkan komisi lagi di sini akan memotongnya
         * dua kali, dan laporan pendapatan driver akan lebih KECIL daripada yang
         * benar-benar masuk ke dompetnya — keluhan yang paling sulit dijelaskan.
         */
        $this->assertSame(
            28_000,
            (int) $baris->net_earning,
            'Net harus sama dengan bruto: driver_earning sudah bersih dari '
            .'komisi. Kalau 21.000, komisinya dipotong dua kali.',
        );
    }

    /**
     * Driver yang online tanpa satu order pun TETAP punya baris.
     *
     * Justru itu baris yang paling perlu dilihat tim ops. Kalau daftar
     * driver-nya diambil dari tabel `orders` saja, driver itu hilang dari
     * laporan — dan yang tampak adalah tidak ada masalah.
     */
    public function test_driver_online_tanpa_order_tetap_punya_baris(): void
    {
        $driver = Driver::factory()->create();

        [$mulai] = BusinessClock::dayRange($this->hari);

        DB::table('driver_sessions')->insert([
            'driver_id' => $driver->id,
            'started_at' => $mulai,
            'ended_at' => $mulai->copy()->addHours(6),
            'online_seconds' => 21_600,
            'orders_taken' => 0,
            'orders_completed' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(AggregateDailyMetrics::class)->handle($this->hari);

        $baris = DB::table('driver_daily_metrics')
            ->where('date', $this->hari->toDateString())
            ->where('driver_id', $driver->id)
            ->first();

        $this->assertNotNull(
            $baris,
            'Driver yang bekerja enam jam tanpa satu order pun harus muncul di '
            .'laporan. Itu justru baris yang paling perlu dilihat.',
        );

        $this->assertSame(21_600, (int) $baris->online_seconds);
        $this->assertSame(0, (int) $baris->orders_completed);
    }

    // =========================================================================
    //  Pembantu
    // =========================================================================

    /**
     * Id zona pertama dari CatalogSeeder.
     *
     * Diambil dengan query, bukan di-hardcode: sequence PostgreSQL TIDAK kembali
     * saat RefreshDatabase me-rollback transaksinya, jadi id zona naik terus
     * antar test. Angka tetap akan benar untuk test pertama dan salah untuk
     * semua sisanya.
     */
    private function zonaPertama(): int
    {
        return (int) DB::table('zones')->orderBy('id')->value('id');
    }

    private function barisTotal(): ?object
    {
        return DB::table('metrics_daily')
            ->where('date', $this->hari->toDateString())
            ->whereNull('zone_id')
            ->whereNull('service_type_id')
            ->first();
    }

    /**
     * Membuat satu order pada tanggal tertentu.
     *
     * Nominalnya dilewati langsung ke DB lewat update setelah factory, bukan
     * lewat factory state: `orders_breakdown_sums_check` di database menuntut
     * rincian ongkos berjumlah tepat sama dengan total, dan menyusunnya per
     * pemanggilan test akan mengaburkan apa yang sebenarnya diuji.
     */
    private function orderPada(
        CarbonImmutable $hari,
        OrderStatus $status,
        int $totalFare = 20_000,
        int $driverEarning = 16_000,
        int $commission = 4_000,
        ?int $userId = null,
        ?int $driverId = null,
        ?int $waitSeconds = null,
        mixed $requestedAt = null,
    ): Order {
        $diminta = $requestedAt ?? BusinessClock::dayRange($hari)[0]->copy()->addHours(10);

        /*
         * Dibuat dengan status BAWAAN factory dulu, bukan langsung status
         * tujuannya.
         *
         * `orders_completed_shape_check` menuntut order berstatus `completed`
         * punya `driver_id` DAN `completed_at`. Menyisipkannya langsung sebagai
         * completed tanpa keduanya ditolak database — dan penolakannya terjadi
         * sebelum baris ini sempat dilengkapi.
         *
         * Jadi urutannya: sisipkan netral, lalu satu UPDATE yang memasang
         * status beserta seluruh kolom yang dituntut constraint sekaligus.
         */
        $order = Order::factory()->create(array_filter([
            'user_id' => $userId,
            'driver_id' => $driverId,
        ], static fn (mixed $v): bool => $v !== null));

        $cocok = null;

        if ($status !== OrderStatus::NoDriver) {
            $cocok = $diminta->copy()->addSeconds($waitSeconds ?? 45);
        }

        /*
         * Order yang selesai WAJIB punya driver.
         *
         * Kalau pemanggil tidak menyebutkan driver, satu dibuat di sini — bukan
         * karena test-nya peduli siapa drivernya, tapi karena tanpa itu
         * constraint-nya menolak dan kegagalannya akan terbaca sebagai masalah
         * agregasi.
         */
        $driverUntukSelesai = $driverId;

        if ($status === OrderStatus::Completed && $driverUntukSelesai === null) {
            $driverUntukSelesai = (int) Driver::factory()->create()->id;
        }

        /*
         * Ongkos ditulis lewat query langsung, dan rincian dasarnya diisi supaya
         * `orders_breakdown_sums_check` tetap terpenuhi:
         *
         *   total = base + distance + time + surge + regulatory
         *           + platform_fee + service_fee - discount
         *
         * Seluruh total ditaruh di `base_fare` dan sisanya nol. Itu bentuk
         * paling sederhana yang lolos constraint, dan yang diuji di sini adalah
         * agregasinya — bukan penyusunan tarif.
         */
        DB::table('orders')->where('id', $order->id)->update([
            'status' => $status->value,
            'driver_id' => $driverUntukSelesai,

            /*
             * Zona diisi eksplisit, karena `OrderFactory` membiarkannya null.
             *
             * Agregasi membuat baris rincian per zona hanya untuk order yang
             * punya zona — order tanpa zona tidak bisa dibandingkan antar zona.
             * Tanpa baris ini, test rincian per zona akan gagal karena DATANYA
             * yang kurang, bukan karena agregasinya salah.
             */
            'zone_id' => $this->zonaPertama(),

            'base_fare' => $totalFare,
            'distance_fare' => 0,
            'time_fare' => 0,
            'surge_amount' => 0,
            'regulatory_adjustment' => 0,
            'platform_fee' => 0,
            'service_fee' => 0,
            'discount_amount' => 0,
            'total_fare' => $totalFare,
            'driver_earning' => $driverEarning,
            'commission_amount' => $commission,

            'requested_at' => $diminta,
            'matched_at' => $cocok,
            'started_at' => $status === OrderStatus::Completed ? $cocok : null,
            'completed_at' => $status === OrderStatus::Completed
                ? $cocok?->copy()->addMinutes(20)
                : null,
            'cancelled_at' => $status === OrderStatus::Cancelled ? $diminta : null,
        ]);

        return $order->fresh();
    }
}
