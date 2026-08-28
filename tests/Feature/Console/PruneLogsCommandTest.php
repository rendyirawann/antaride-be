<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Ordering\Models\Order;
use App\Domain\Shared\Support\BusinessClock;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\SystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ============================================================================
 *  PERINTAH YANG MENGHAPUS DATA HARUS DIUJI DARI DUA ARAH
 * ============================================================================
 *  Bukan hanya "apakah yang lama terhapus", tapi juga — dan ini yang lebih
 *  penting — "apakah yang MASIH DIBUTUHKAN tetap ada".
 *
 *  Kesalahan yang mungkin di sini bersifat permanen. Log yang terhapus terlalu
 *  cepat tidak bisa dikembalikan, dan yang menyadarinya adalah orang yang
 *  membuka panel untuk menyelidiki sengketa dan tidak menemukan riwayatnya.
 * ============================================================================
 */
class PruneLogsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSeeder::class);
        $this->seed(CatalogSeeder::class);
    }

    public function test_membuang_log_status_yang_melewati_masa_retensi(): void
    {
        $order = Order::factory()->create();

        $umur = (int) config('antaride.retention.order_status_logs_days');

        $lama = $this->logStatus($order, BusinessClock::now()->subDays($umur + 5));
        $baru = $this->logStatus($order, BusinessClock::now()->subDays(2));

        $this->artisan('antaride:prune-logs')->assertSuccessful();

        $this->assertDatabaseMissing('order_status_logs', ['id' => $lama]);

        $this->assertTrue(
            DB::table('order_status_logs')->where('id', $baru)->exists(),
            'Log berumur dua hari masih di dalam masa retensi dan HARUS tetap '
            .'ada. Sengketa order praktis selalu diajukan dalam hitungan hari.',
        );
    }

    /**
     * Baris tepat di batas retensi TETAP DISIMPAN.
     *
     * Perbandingannya `<`, bukan `<=`. Bedanya satu baris, tapi arah yang
     * dipilih penting: kalau ragu, simpan. Data yang tersimpan sehari lebih lama
     * tidak merugikan siapa pun; data yang terhapus sehari lebih cepat tidak
     * bisa dikembalikan.
     */
    public function test_baris_tepat_di_batas_tetap_disimpan(): void
    {
        $order = Order::factory()->create();

        $umur = (int) config('antaride.retention.order_status_logs_days');

        // Satu jam LEBIH BARU dari batasnya.
        $diBatas = $this->logStatus(
            $order,
            BusinessClock::now()->subDays($umur)->addHour(),
        );

        $this->artisan('antaride:prune-logs')->assertSuccessful();

        $this->assertDatabaseHas('order_status_logs', ['id' => $diBatas]);
    }

    /**
     * `--dry-run` tidak menghapus apa pun.
     *
     * Ini yang dipakai untuk memeriksa dampaknya sebelum menjalankannya
     * sungguhan. Kalau dry-run ternyata ikut menghapus, seluruh gunanya hilang —
     * dan yang menemukannya adalah orang yang menjalankannya justru untuk
     * berhati-hati.
     */
    public function test_dry_run_tidak_menghapus_apa_pun(): void
    {
        $order = Order::factory()->create();

        $umur = (int) config('antaride.retention.order_status_logs_days');

        $lama = $this->logStatus($order, BusinessClock::now()->subDays($umur + 30));

        $this->artisan('antaride:prune-logs --dry-run')->assertSuccessful();

        $this->assertTrue(
            DB::table('order_status_logs')->where('id', $lama)->exists(),
            '--dry-run ikut menghapus. Seluruh gunanya adalah memeriksa tanpa '
            .'mengubah apa pun.',
        );
    }

    /**
     * ========================================================================
     *  DELETE HARUS BENAR-BENAR BERTAHAP
     * ========================================================================
     *  PostgreSQL TIDAK mendukung LIMIT pada DELETE. Query builder Laravel
     *  menerima `->limit()` tanpa mengeluh lalu MENGABAIKANNYA di PostgreSQL —
     *  dan yang terjadi adalah DELETE seluruh tabel dalam satu transaksi, tepat
     *  yang coba dihindari.
     *
     *  Test ini memakai batch 2 pada 7 baris: kalau batching-nya bekerja,
     *  ketujuhnya tetap habis lewat beberapa putaran. Kalau `ctid IN (SELECT
     *  ... LIMIT)` diganti `->limit()` yang diabaikan, hasil akhirnya sama —
     *  jadi yang dijaga di sini adalah KEBENARAN hasilnya di bawah batch kecil,
     *  bukan jumlah putarannya.
     * ========================================================================
     */
    public function test_menghapus_seluruh_baris_lama_walau_batch_kecil(): void
    {
        $order = Order::factory()->create();

        $umur = (int) config('antaride.retention.order_status_logs_days');

        for ($i = 0; $i < 7; $i++) {
            $this->logStatus($order, BusinessClock::now()->subDays($umur + 10 + $i));
        }

        $this->artisan('antaride:prune-logs --batch=2')->assertSuccessful();

        $this->assertSame(
            0,
            DB::table('order_status_logs')
                ->where('created_at', '<', BusinessClock::now()->subDays($umur))
                ->count(),
            'Tujuh baris lama dengan batch 2 harus habis lewat empat putaran. '
            .'Sisa berarti loop-nya berhenti terlalu cepat.',
        );
    }

    /**
     * ========================================================================
     *  YANG TIDAK BOLEH IKUT TERHAPUS
     * ========================================================================
     *  Tiga tabel ini sengaja tidak ada di daftar pemangkasan, dan test ini
     *  yang menjaganya tetap begitu:
     *
     *    wallet_transactions   buku besar keuangan, append-only, ditegakkan
     *                          trigger database
     *    orders                riwayat penumpang dan dasar sengketa
     *    audit_logs            justru yang paling dibutuhkan saat investigasi
     *
     *  Kalau ada yang menambahkannya ke daftar nanti, test ini gagal — dan
     *  kegagalannya menyebutkan alasannya.
     * ========================================================================
     */
    public function test_tabel_keuangan_dan_audit_tidak_pernah_dipangkas(): void
    {
        $order = Order::factory()->create();

        // Order berumur dua tahun. Kalau `orders` masuk daftar pemangkasan
        // dengan retensi apa pun yang wajar, baris ini akan hilang.
        DB::table('orders')->where('id', $order->id)->update([
            'created_at' => BusinessClock::now()->subYears(2),
            'requested_at' => BusinessClock::now()->subYears(2),
        ]);

        $auditId = DB::table('audit_logs')->insertGetId([
            'uuid' => (string) Str::uuid7(),
            'admin_id' => null,
            'action' => 'uji.retensi',
            'auditable_type' => 'order',
            'auditable_id' => (int) $order->id,
            'created_at' => BusinessClock::now()->subYears(2),
        ]);

        $this->artisan('antaride:prune-logs')->assertSuccessful();

        $this->assertTrue(
            DB::table('orders')->where('id', $order->id)->exists(),
            'Order berumur dua tahun terhapus. `orders` adalah riwayat penumpang '
            .'dan dasar sengketa — tidak boleh pernah dipangkas.',
        );

        $this->assertTrue(
            DB::table('audit_logs')->where('id', $auditId)->exists(),
            'Audit log berumur dua tahun terhapus. Justru itu yang dibutuhkan '
            .'saat ada investigasi, dan investigasi selalu tentang masa lalu.',
        );
    }

    public function test_berjalan_tanpa_galat_saat_tidak_ada_yang_perlu_dibuang(): void
    {
        $this->artisan('antaride:prune-logs')
            ->expectsOutputToContain('tidak ada yang perlu dibuang')
            ->assertSuccessful();
    }

    // -------------------------------------------------------------------------

    private function logStatus(Order $order, mixed $waktu): int
    {
        return DB::table('order_status_logs')->insertGetId([
            'order_id' => $order->id,
            'from_status' => 'created',
            'to_status' => 'searching',
            'actor_type' => 'system',
            'created_at' => $waktu,
        ]);
    }
}
