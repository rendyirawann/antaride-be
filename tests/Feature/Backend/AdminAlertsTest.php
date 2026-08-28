<?php

declare(strict_types=1);

namespace Tests\Feature\Backend;

use App\Domain\Driver\Models\Driver;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Support\Actions\BuildAdminAlerts;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\SystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ============================================================================
 *  LONCENG YANG SALAH LEBIH BURUK DARIPADA TIDAK ADA LONCENG
 * ============================================================================
 *  Kalau angkanya terlalu tinggi, tim ops mengejar pekerjaan yang sudah beres
 *  lalu berhenti mempercayainya. Kalau terlalu rendah, pekerjaan yang menuntut
 *  perhatian tidak pernah terlihat — dan yang menunggu di ujungnya adalah
 *  penumpang di pinggir jalan atau driver yang uangnya belum bisa ditarik.
 *
 *  Jadi yang diuji di sini angkanya PERSIS, dan setiap sumbernya terpisah.
 * ============================================================================
 */
class AdminAlertsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSeeder::class);
        $this->seed(CatalogSeeder::class);

        // Cache-nya global dan bertahan 30 detik. Tanpa dibersihkan, test kedua
        // akan membaca hasil hitungan test pertama.
        Cache::flush();
    }

    public function test_tanpa_pekerjaan_menunggu_hasilnya_kosong(): void
    {
        $alerts = app(BuildAdminAlerts::class)->handle();

        $this->assertSame(0, $alerts['total']);
        $this->assertSame([], $alerts['items']);
    }

    /**
     * Order yang macet lebih dari 10 menit terhitung.
     *
     * Ini yang paling mendesak dari seluruh daftar: ada penumpang yang sedang
     * menunggu SEKARANG.
     */
    public function test_order_macet_terhitung(): void
    {
        // Dua yang macet: satu `searching`, satu `created`.
        $this->orderDengan(OrderStatus::Searching, now()->subMinutes(15));
        $this->orderDengan(OrderStatus::Created, now()->subMinutes(30));

        // Satu yang baru — belum macet.
        $this->orderDengan(OrderStatus::Searching, now()->subMinutes(2));

        $alerts = app(BuildAdminAlerts::class)->handle();

        $macet = $this->item($alerts, 'stuck_orders');

        $this->assertNotNull($macet, 'Alert order macet tidak muncul.');

        $this->assertSame(
            2,
            $macet['count'],
            'Harus 2. Kalau 3, order yang baru dua menit ikut dihitung — dan '
            .'tim ops akan mengejar order yang belum bermasalah.',
        );
    }

    /**
     * Order tepat di batas 10 menit BELUM terhitung macet.
     *
     * Perbandingannya `<`, bukan `<=`. Bedanya satu order, tapi arahnya penting:
     * alert yang menyala tepat di ambang akan berkedip masuk-keluar untuk order
     * yang sama seiring detik berjalan.
     */
    public function test_order_tepat_di_batas_belum_terhitung(): void
    {
        $this->orderDengan(OrderStatus::Searching, now()->subMinutes(10)->addSeconds(5));

        $alerts = app(BuildAdminAlerts::class)->handle();

        $this->assertNull(
            $this->item($alerts, 'stuck_orders'),
            'Order yang baru 9 menit 55 detik sudah dihitung macet.',
        );
    }

    /**
     * Order yang sudah SELESAI atau DIBATALKAN tidak pernah macet.
     *
     * Yang dicari adalah order yang masih mencari driver. Order lama yang sudah
     * ditutup jumlahnya akan tumbuh selamanya — dan kalau ikut terhitung,
     * angkanya akan naik terus tanpa ada yang bisa dilakukan.
     */
    public function test_order_selesai_dan_dibatalkan_tidak_dihitung_macet(): void
    {
        $this->orderDengan(OrderStatus::Cancelled, now()->subDays(30));
        $this->orderDengan(OrderStatus::NoDriver, now()->subDays(30));

        $alerts = app(BuildAdminAlerts::class)->handle();

        $this->assertNull(
            $this->item($alerts, 'stuck_orders'),
            'Order yang sudah ditutup ikut dihitung macet. Angkanya akan naik '
            .'selamanya tanpa ada yang bisa dikerjakan.',
        );
    }

    public function test_order_butuh_review_ongkos_terhitung(): void
    {
        $order = $this->orderDengan(OrderStatus::Completed, now()->subHour());

        DB::table('orders')->where('id', $order->id)->update([
            'needs_fare_review' => true,
        ]);

        $review = $this->item(app(BuildAdminAlerts::class)->handle(), 'fare_reviews');

        $this->assertNotNull($review);
        $this->assertSame(1, $review['count']);
    }

    /**
     * Setiap alert menunjuk ke URL yang bisa dibuka.
     *
     * ========================================================================
     *  INI YANG MENJAGA PANEL TETAP BISA DIBUKA
     * ========================================================================
     *  `route()` MELEMPAR pada nama yang tidak terdaftar, dan lonceng ini ada di
     *  SETIAP halaman panel. Jadi satu nama route yang salah ketik berarti
     *  seluruh backoffice tidak bisa dibuka — bukan hanya loncengnya yang kosong.
     *
     *  `BuildAdminAlerts::url()` menjaganya dengan `Route::has()`, dan test ini
     *  memastikan penjaga itu benar-benar bekerja untuk setiap sumber.
     * ========================================================================
     */
    public function test_setiap_alert_menunjuk_url_yang_sah(): void
    {
        $this->orderDengan(OrderStatus::Searching, now()->subMinutes(20));

        $order = $this->orderDengan(OrderStatus::Completed, now()->subHour());
        DB::table('orders')->where('id', $order->id)->update(['needs_fare_review' => true]);

        $alerts = app(BuildAdminAlerts::class)->handle();

        $this->assertNotEmpty($alerts['items'], 'Test ini tidak menguji apa pun tanpa alert.');

        foreach ($alerts['items'] as $item) {
            $this->assertNotEmpty($item['url']);

            $this->assertStringStartsWith(
                'http',
                $item['url'],
                "Alert '{$item['key']}' menunjuk ke URL yang tidak sah.",
            );
        }
    }

    /**
     * Hasilnya di-cache, dan `forget()` membuangnya.
     *
     * Yang dijaga: staf yang baru menyetujui approval tidak boleh melihat angka
     * lama — itu terbaca sebagai tindakannya tidak tersimpan, dan dia akan
     * menekan tombol setujunya lagi.
     */
    public function test_cache_dipakai_dan_bisa_dibuang(): void
    {
        $builder = app(BuildAdminAlerts::class);

        $this->assertSame(0, $builder->handle()['total']);

        // Pekerjaan baru muncul SETELAH hitungan pertama masuk cache.
        $this->orderDengan(OrderStatus::Searching, now()->subMinutes(20));

        $this->assertSame(
            0,
            $builder->handle()['total'],
            'Cache tidak dipakai — hitungannya jalan di setiap pemanggilan, dan '
            .'lonceng ini ada di setiap halaman panel.',
        );

        $builder->forget();

        $this->assertSame(
            1,
            $builder->handle()['total'],
            'forget() tidak membuang cache. Staf akan melihat angka lama setelah '
            .'menyelesaikan pekerjaannya.',
        );
    }

    /**
     * Alert bernilai nol TIDAK ditampilkan.
     *
     * "0 approval menunggu" tidak memberi tahu apa pun, dan lima baris nol
     * membuat satu baris yang benar-benar ada isinya sulit ditemukan.
     */
    public function test_alert_bernilai_nol_tidak_ditampilkan(): void
    {
        $this->orderDengan(OrderStatus::Searching, now()->subMinutes(20));

        $alerts = app(BuildAdminAlerts::class)->handle();

        $this->assertCount(1, $alerts['items']);
        $this->assertSame('stuck_orders', $alerts['items'][0]['key']);
    }

    /**
     * Order macet berada DI ATAS review ongkos.
     *
     * Urutannya bukan selera: order macet berarti ada penumpang yang menunggu
     * sekarang, sementara review ongkos bisa menunggu satu jam tanpa ada yang
     * dirugikan.
     */
    public function test_urutan_alert_mengikuti_tingkat_kepentingan(): void
    {
        $this->orderDengan(OrderStatus::Searching, now()->subMinutes(20));

        $review = $this->orderDengan(OrderStatus::Completed, now()->subHour());
        DB::table('orders')->where('id', $review->id)->update(['needs_fare_review' => true]);

        $keys = array_column(app(BuildAdminAlerts::class)->handle()['items'], 'key');

        $this->assertSame(
            ['stuck_orders', 'fare_reviews'],
            $keys,
            'Order macet harus di atas review ongkos — ada penumpang yang '
            .'menunggu di pinggir jalan.',
        );
    }

    // =========================================================================

    private function orderDengan(OrderStatus $status, mixed $requestedAt): Order
    {
        // Dibuat netral dulu lalu dipindahkan — order `completed` wajib punya
        // driver_id dan completed_at menurut `orders_completed_shape_check`.
        $order = Order::factory()->create();

        $isSelesai = $status === OrderStatus::Completed;

        DB::table('orders')->where('id', $order->id)->update(array_filter([
            'status' => $status->value,
            'requested_at' => $requestedAt,
            'driver_id' => $isSelesai
                ? Driver::factory()->create()->id
                : null,
            'completed_at' => $isSelesai ? now() : null,
            'cancelled_at' => $status === OrderStatus::Cancelled ? now() : null,
        ], static fn (mixed $v): bool => $v !== null));

        return $order->fresh();
    }

    /**
     * @param  array{total: int, items: list<array<string, mixed>>}  $alerts
     * @return array<string, mixed>|null
     */
    private function item(array $alerts, string $key): ?array
    {
        foreach ($alerts['items'] as $item) {
            if ($item['key'] === $key) {
                return $item;
            }
        }

        return null;
    }
}
