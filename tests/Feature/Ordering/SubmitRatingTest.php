<?php

declare(strict_types=1);

namespace Tests\Feature\Ordering;

use App\Domain\Driver\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Ordering\Actions\SubmitRating;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Exceptions\RatingNotAllowedException;
use App\Domain\Ordering\Models\Order;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\SystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ============================================================================
 *  RATING ADALAH SATU-SATUNYA JALUR YANG MENGUBAH `drivers.rating_avg`
 * ============================================================================
 *  Angka itu ditampilkan di kartu driver yang dilihat penumpang, dan ikut
 *  menentukan prioritas driver di `DriverScorer`. Jadi yang diuji di sini bukan
 *  hanya "baris rating tersimpan", tapi bahwa AGREGATNYA benar — karena agregat
 *  itulah yang punya konsekuensi.
 * ============================================================================
 */
class SubmitRatingTest extends TestCase
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

    public function test_penilaian_tersimpan_dan_memperbarui_agregat_driver(): void
    {
        [$order, $user, $driver] = $this->orderSelesai();

        app(SubmitRating::class)->handle($order, (int) $user->id, 4);

        $this->assertDatabaseHas('ratings', [
            'order_id' => $order->id,
            'rater_type' => 'user',
            'ratee_type' => 'driver',
            'ratee_id' => $driver->id,
            'score' => 4,
        ]);

        $driver->refresh();

        $this->assertSame('4.00', (string) $driver->rating_avg);
        $this->assertSame(1, (int) $driver->rating_count);
    }

    /**
     * Rata-rata dihitung dari SELURUH baris, bukan ditambahkan bertahap.
     *
     * Tiga skor 5, 4, dan 3 harus menghasilkan tepat 4.00. Rumus bertahap
     * mengumpulkan galat pembulatan di setiap langkah, dan galat itu tidak bisa
     * dipulihkan tanpa menghitung ulang.
     */
    public function test_rata_rata_dihitung_dari_seluruh_penilaian(): void
    {
        $driver = Driver::factory()->create();

        foreach ([5, 4, 3] as $skor) {
            [$order, $user] = $this->orderSelesai($driver);

            app(SubmitRating::class)->handle($order, (int) $user->id, $skor);
        }

        $driver->refresh();

        $this->assertSame('4.00', (string) $driver->rating_avg);
        $this->assertSame(3, (int) $driver->rating_count);
    }

    /**
     * ========================================================================
     *  RATING YANG DISEMBUNYIKAN ADMIN TIDAK IKUT DIHITUNG
     * ========================================================================
     *  Admin menyembunyikan rating yang berisi hinaan atau jelas dibuat untuk
     *  menjatuhkan. Kalau tetap ikut dihitung, penyembunyiannya tidak berarti
     *  apa pun bagi driver yang dirugikan — dan itu justru satu-satunya alasan
     *  fitur itu ada.
     *
     *  Ini yang TIDAK BISA dilakukan rumus bertahap: dia tidak punya cara
     *  mengeluarkan satu nilai yang sudah masuk ke rata-rata.
     * ========================================================================
     */
    public function test_penilaian_yang_disembunyikan_tidak_ikut_dihitung(): void
    {
        $driver = Driver::factory()->create();

        // Dua penilaian: satu bintang 5, satu bintang 1.
        [$orderA, $userA] = $this->orderSelesai($driver);
        app(SubmitRating::class)->handle($orderA, (int) $userA->id, 5);

        [$orderB, $userB] = $this->orderSelesai($driver);
        app(SubmitRating::class)->handle($orderB, (int) $userB->id, 1);

        $driver->refresh();
        $this->assertSame('3.00', (string) $driver->rating_avg);

        // Admin menyembunyikan yang bintang 1.
        DB::table('ratings')
            ->where('order_id', $orderB->id)
            ->update(['is_hidden' => true]);

        // Penilaian ketiga memicu perhitungan ulang.
        [$orderC, $userC] = $this->orderSelesai($driver);
        app(SubmitRating::class)->handle($orderC, (int) $userC->id, 5);

        $driver->refresh();

        $this->assertSame(
            '5.00',
            (string) $driver->rating_avg,
            'Rata-rata harus 5.00 dari dua bintang 5. Kalau 3.67, penilaian '
            .'yang disembunyikan admin masih ikut dihitung — dan penyembunyiannya '
            .'tidak berarti apa pun bagi driver.',
        );

        $this->assertSame(2, (int) $driver->rating_count);
    }

    public function test_order_yang_belum_selesai_tidak_bisa_dinilai(): void
    {
        [$order, $user] = $this->orderSelesai(status: OrderStatus::InProgress);

        $this->expectException(RatingNotAllowedException::class);

        app(SubmitRating::class)->handle($order, (int) $user->id, 5);
    }

    /**
     * Penilaian kedua untuk order yang sama ditolak.
     *
     * Yang menegakkannya `unique(order_id, rater_type)` di database, bukan
     * pemeriksaan di PHP — pemeriksaan lalu insert menyisakan celah balapan, dan
     * penumpang yang menekan kirim dua kali di jaringan lambat masuk tepat ke
     * celah itu.
     */
    public function test_order_tidak_bisa_dinilai_dua_kali(): void
    {
        [$order, $user] = $this->orderSelesai();

        app(SubmitRating::class)->handle($order, (int) $user->id, 5);

        $this->expectException(RatingNotAllowedException::class);

        app(SubmitRating::class)->handle($order, (int) $user->id, 3);
    }

    public function test_order_orang_lain_tidak_bisa_dinilai(): void
    {
        [$order] = $this->orderSelesai();

        $orangLain = User::factory()->create();

        $this->expectException(RatingNotAllowedException::class);

        app(SubmitRating::class)->handle($order, (int) $orangLain->id, 5);
    }

    public function test_tag_dan_komentar_tersimpan(): void
    {
        [$order, $user] = $this->orderSelesai();

        $rating = app(SubmitRating::class)->handle(
            $order,
            (int) $user->id,
            5,
            tags: ['ramah', 'kendaraan bersih'],
            comment: 'Driver sangat membantu.',
        );

        $this->assertSame(['ramah', 'kendaraan bersih'], $rating->tags);
        $this->assertSame('Driver sangat membantu.', $rating->comment);
    }

    // =========================================================================
    //  HTTP
    // =========================================================================

    public function test_endpoint_penilaian_bekerja(): void
    {
        [$order, $user] = $this->orderSelesai();

        Sanctum::actingAs($user);

        $this->postJson("/api/v1/orders/{$order->uuid}/rating", [
            'score' => 5,
            'tags' => ['ramah'],
            'comment' => 'Cepat dan aman.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.score', 5);
    }

    public function test_endpoint_menolak_skor_di_luar_rentang(): void
    {
        [$order, $user] = $this->orderSelesai();

        Sanctum::actingAs($user);

        $this->postJson("/api/v1/orders/{$order->uuid}/rating", ['score' => 6])
            ->assertStatus(422);

        $this->postJson("/api/v1/orders/{$order->uuid}/rating", ['score' => 0])
            ->assertStatus(422);
    }

    public function test_endpoint_menolak_penilaian_kedua_dengan_409(): void
    {
        [$order, $user] = $this->orderSelesai();

        Sanctum::actingAs($user);

        $this->postJson("/api/v1/orders/{$order->uuid}/rating", ['score' => 5])
            ->assertCreated();

        $this->postJson("/api/v1/orders/{$order->uuid}/rating", ['score' => 3])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'RATING_ALREADY_SUBMITTED');
    }

    /**
     * Order orang lain menghasilkan 404, bukan 403.
     *
     * 403 mengonfirmasi bahwa uuid yang ditebak memang milik seseorang — dan itu
     * satu-satunya informasi yang dibutuhkan untuk tahu bahwa menebak lebih
     * lanjut ada gunanya.
     */
    public function test_endpoint_menyembunyikan_order_orang_lain_sebagai_404(): void
    {
        [$order] = $this->orderSelesai();

        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/orders/{$order->uuid}/rating", ['score' => 5])
            ->assertNotFound();
    }

    // =========================================================================
    //  Resource
    // =========================================================================

    /**
     * ========================================================================
     *  `can_rate` DITENTUKAN BACKEND, BUKAN DISIMPULKAN APLIKASI
     * ========================================================================
     *  Aplikasi hanya bisa memeriksa statusnya — dia tidak tahu apakah order
     *  sudah dinilai dari perangkat lain atau di sesi sebelumnya.
     *
     *  Yang terjadi kalau disimpulkan aplikasi: form penilaian muncul lagi di
     *  riwayat untuk perjalanan yang sudah dinilai, dan pengirimannya ditolak
     *  409.
     * ========================================================================
     */
    public function test_can_rate_menjadi_false_setelah_dinilai(): void
    {
        [$order, $user] = $this->orderSelesai();

        Sanctum::actingAs($user);

        $this->getJson("/api/v1/orders/{$order->uuid}")
            ->assertOk()
            ->assertJsonPath('data.can_rate', true);

        $this->postJson("/api/v1/orders/{$order->uuid}/rating", ['score' => 4])
            ->assertCreated();

        $this->getJson("/api/v1/orders/{$order->uuid}")
            ->assertOk()
            ->assertJsonPath('data.can_rate', false)
            ->assertJsonPath('data.rating.score', 4);
    }

    public function test_can_rate_false_untuk_order_yang_belum_selesai(): void
    {
        [$order, $user] = $this->orderSelesai(status: OrderStatus::InProgress);

        Sanctum::actingAs($user);

        $this->getJson("/api/v1/orders/{$order->uuid}")
            ->assertOk()
            ->assertJsonPath('data.can_rate', false);
    }

    // =========================================================================

    /**
     * Order selesai beserta pemilik dan drivernya.
     *
     * @return array{0: Order, 1: User, 2: Driver}
     */
    private function orderSelesai(
        ?Driver $driver = null,
        OrderStatus $status = OrderStatus::Completed,
    ): array {
        $driver ??= Driver::factory()->create();
        $user = User::factory()->create();

        // Dibuat netral dulu lalu dipindahkan ke status tujuan bersama kolom
        // yang dituntut `orders_completed_shape_check` — order `completed` wajib
        // punya driver_id DAN completed_at.
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'driver_id' => $driver->id,
        ]);

        DB::table('orders')->where('id', $order->id)->update([
            'status' => $status->value,
            'matched_at' => now()->subMinutes(30),
            'started_at' => now()->subMinutes(25),
            'completed_at' => $status === OrderStatus::Completed
                ? now()->subMinutes(5)
                : null,
        ]);

        return [$order->fresh(), $user, $driver];
    }
}
