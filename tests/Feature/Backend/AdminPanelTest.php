<?php

declare(strict_types=1);

namespace Tests\Feature\Backend;

use App\Domain\Identity\Models\Admin;
use App\Domain\Support\Models\FeatureFlag;
use App\Http\Middleware\EnsureAdminTwoFactorEnabled;
use Database\Seeders\AdminSeeder;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ============================================================================
 *  SETIAP HALAMAN PANEL BENAR-BENAR DIRENDER
 * ============================================================================
 *  Blade tidak diperiksa compiler apa pun. Satu nama variabel yang salah, satu
 *  method yang tidak ada, atau satu route yang namanya keliru hanya muncul saat
 *  halamannya dibuka — dan halaman panel admin bisa berbulan-bulan tidak dibuka
 *  sampai ada yang membutuhkannya, biasanya saat sedang ada masalah.
 *
 *  Test ini membuka SETIAP halaman dan menuntut status 200. Itu tidak
 *  membuktikan halamannya berguna, tapi membuktikan dia tidak meledak — dan
 *  kelas bug itulah yang paling sering ada di panel admin.
 * ============================================================================
 *
 * ============================================================================
 *  OTORISASI DIUJI DENGAN ROLE YANG SUNGGUHAN
 * ============================================================================
 *  Bukan dengan `Gate::before` yang meloloskan semuanya. Yang diuji justru
 *  apakah staf CS DITOLAK di halaman keuangan — dan itu tidak bisa diuji dengan
 *  akun yang punya seluruh permission.
 * ============================================================================
 */
class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(AdminSeeder::class);
        $this->seed(SystemSeeder::class);
        $this->seed(CatalogSeeder::class);
    }

    // =========================================================================
    //  Masuk
    // =========================================================================

    public function test_halaman_masuk_bisa_dibuka(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('Panel Backoffice');
    }

    public function test_masuk_dengan_kredensial_benar(): void
    {
        $this->post('/admin/login', [
            'email' => 'superadmin@antaride.test',
            'password' => 'password',
        ])->assertRedirect();

        $this->assertAuthenticatedAs(
            Admin::query()->where('email', 'superadmin@antaride.test')->first(),
            'admin',
        );
    }

    public function test_kata_sandi_salah_ditolak(): void
    {
        $this->post('/admin/login', [
            'email' => 'superadmin@antaride.test',
            'password' => 'salah',
        ])->assertSessionHasErrors('email');

        $this->assertGuest('admin');
    }

    public function test_email_tidak_ada_memberi_pesan_yang_sama(): void
    {
        /*
         * Keduanya diperiksa terhadap pesan yang SAMA dan diketahui.
         *
         * Membandingkan dua pesan yang diekstrak dari session terdengar lebih
         * kuat, tapi punya kelemahan: kalau kedua jalur diam-diam berhenti
         * mengisi pesan, keduanya akan sama-sama null dan test-nya lulus.
         *
         * Menyebutkan teks yang diharapkan membuat test ini gagal untuk dua hal
         * sekaligus — kalau pesannya berbeda, DAN kalau salah satu jalur tidak
         * memberi pesan sama sekali.
         */
        $pesanDiharapkan = 'Email atau kata sandi salah.';

        $this->post('/admin/login', [
            'email' => 'bukansiapasiapa@antaride.test',
            'password' => 'password',
        ])->assertSessionHasErrors(['email' => $pesanDiharapkan]);

        $this->post('/admin/login', [
            'email' => 'superadmin@antaride.test',
            'password' => 'salah',
        ])->assertSessionHasErrors(['email' => $pesanDiharapkan]);
    }

    public function test_upaya_masuk_gagal_dicatat(): void
    {
        $this->post('/admin/login', [
            'email' => 'superadmin@antaride.test',
            'password' => 'salah',
        ]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.login_failed']);
    }

    public function test_kata_sandi_tidak_pernah_masuk_audit_log(): void
    {
        $this->post('/admin/login', [
            'email' => 'superadmin@antaride.test',
            'password' => 'rahasia-sekali-jangan-bocor',
        ]);

        $catatan = DB::table('audit_logs')->where('action', 'admin.login_failed')->first();

        $this->assertNotNull($catatan);
        $this->assertStringNotContainsString(
            'rahasia-sekali-jangan-bocor',
            (string) $catatan->new_values,
            'Kata sandi tidak boleh pernah masuk log, dalam bentuk apa pun.'
        );
    }

    public function test_endpoint_admin_menolak_tanpa_sesi(): void
    {
        $this->get('/admin/')->assertRedirect('/admin/login');
        $this->get('/admin/orders')->assertRedirect('/admin/login');
        $this->get('/admin/finance/withdrawals')->assertRedirect('/admin/login');
    }

    // =========================================================================
    //  2FA
    // =========================================================================

    public function test_admin_tanpa_2fa_diarahkan_ke_penyiapan(): void
    {
        $admin = $this->adminDengan('super-admin', duaFaktorAktif: false);

        $this->actingAs($admin, 'admin')
            ->get('/admin/')
            ->assertRedirect(route('admin.two-factor.setup'));
    }

    public function test_admin_ber_2fa_yang_belum_verifikasi_sesi_diarahkan_ke_tantangan(): void
    {
        /*
         * Ini bug yang paling penting di seluruh test ini.
         *
         * Versi pertama middleware hanya memeriksa apakah 2FA AKTIF, bukan
         * apakah SESI INI sudah melewatinya. Akibatnya admin yang sudah
         * mengaktifkan 2FA tetap masuk hanya dengan email dan kata sandi — dan
         * seluruh 2FA tidak berarti apa pun.
         *
         * Yang membuatnya sulit terlihat: panelnya berperilaku benar dari luar.
         * Status di menu berbunyi "2FA aktif", QR-nya bisa dipindai, kodenya
         * diverifikasi saat penyiapan. Yang tidak pernah terjadi hanya satu:
         * kodenya tidak pernah diminta lagi.
         */
        $admin = $this->adminDengan('super-admin', duaFaktorAktif: true);

        $this->actingAs($admin, 'admin')
            ->get('/admin/')
            ->assertRedirect(route('admin.two-factor.challenge'));
    }

    public function test_sesi_yang_sudah_verifikasi_2fa_boleh_masuk(): void
    {
        $admin = $this->adminDengan('super-admin', duaFaktorAktif: true);

        $this->actingAs($admin, 'admin')
            ->withSession([EnsureAdminTwoFactorEnabled::SESSION_KEY => now()->timestamp])
            ->get('/admin/')
            ->assertOk();
    }

    public function test_verifikasi_2fa_yang_kadaluarsa_menuntut_kode_lagi(): void
    {
        $admin = $this->adminDengan('super-admin', duaFaktorAktif: true);

        $ttl = (int) config('antaride.security.two_factor_ttl_minutes', 720);

        $this->actingAs($admin, 'admin')
            ->withSession([
                EnsureAdminTwoFactorEnabled::SESSION_KEY => now()->subMinutes($ttl + 1)->timestamp,
            ])
            ->get('/admin/')
            ->assertRedirect(route('admin.two-factor.challenge'));
    }

    public function test_halaman_penyiapan_2fa_menghasilkan_qr(): void
    {
        $admin = $this->adminDengan('super-admin', duaFaktorAktif: false);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.two-factor.setup'))
            ->assertOk()
            ->assertSee('<svg', escape: false);
    }

    // =========================================================================
    //  Setiap halaman dirender
    // =========================================================================

    /**
     * @return array<string, array{string}>
     */
    public static function halamanSuperadmin(): array
    {
        return [
            'dashboard' => ['/admin/'],
            'daftar order' => ['/admin/orders'],
            'live map' => ['/admin/livemap'],
            'daftar driver' => ['/admin/drivers'],
            'antrean verifikasi' => ['/admin/drivers/verification'],
            'daftar merchant' => ['/admin/merchants'],
            'aturan tarif' => ['/admin/pricing'],
            'simulator tarif' => ['/admin/pricing/simulator'],
            'form tarif' => ['/admin/pricing/create'],
            'zona' => ['/admin/pricing/zones'],
            'surge' => ['/admin/pricing/surge'],
            'antrean penarikan' => ['/admin/finance/withdrawals'],
            'buku besar' => ['/admin/finance/ledger'],
            'rekonsiliasi' => ['/admin/finance/reconciliation'],
            'kill switch' => ['/admin/settings/flags'],
            'audit log' => ['/admin/audit'],
            'staf' => ['/admin/staff'],
        ];
    }

    #[DataProvider('halamanSuperadmin')]
    public function test_halaman_panel_bisa_dirender(string $url): void
    {
        $admin = $this->adminDengan('super-admin', duaFaktorAktif: true);

        $this->actingAs($admin, 'admin')
            ->withSession([EnsureAdminTwoFactorEnabled::SESSION_KEY => now()->timestamp])
            ->get($url)
            ->assertOk();
    }

    public function test_endpoint_data_order_membalas_json_tanpa_count(): void
    {
        $admin = $this->adminDengan('super-admin', duaFaktorAktif: true);

        $this->actingAs($admin, 'admin')
            ->withSession([EnsureAdminTwoFactorEnabled::SESSION_KEY => now()->timestamp])
            ->getJson('/admin/orders/data?draw=1&length=25&start=0')
            ->assertOk()
            ->assertJsonStructure(['draw', 'recordsTotal', 'recordsFiltered', 'data']);
    }

    public function test_endpoint_data_livemap_membalas_json(): void
    {
        $admin = $this->adminDengan('super-admin', duaFaktorAktif: true);

        $this->actingAs($admin, 'admin')
            ->withSession([EnsureAdminTwoFactorEnabled::SESSION_KEY => now()->timestamp])
            ->getJson('/admin/livemap/data?sw_lat=3.5&sw_lng=98.6&ne_lat=3.7&ne_lng=98.8')
            ->assertOk()
            ->assertJsonStructure(['driver', 'order', 'waktu_server']);
    }

    // =========================================================================
    //  Otorisasi
    // =========================================================================

    public function test_staf_cs_ditolak_di_halaman_keuangan(): void
    {
        $admin = $this->adminDengan('cs-agent', duaFaktorAktif: true);

        $this->actingAs($admin, 'admin')
            ->withSession([EnsureAdminTwoFactorEnabled::SESSION_KEY => now()->timestamp])
            ->get('/admin/finance/withdrawals')
            ->assertForbidden();
    }

    public function test_staf_cs_ditolak_di_kill_switch(): void
    {
        $admin = $this->adminDengan('cs-agent', duaFaktorAktif: true);

        $this->actingAs($admin, 'admin')
            ->withSession([EnsureAdminTwoFactorEnabled::SESSION_KEY => now()->timestamp])
            ->get('/admin/settings/flags')
            ->assertForbidden();
    }

    public function test_verifikator_ditolak_mengubah_tarif(): void
    {
        $admin = $this->adminDengan('driver-verifier', duaFaktorAktif: true);

        $this->actingAs($admin, 'admin')
            ->withSession([EnsureAdminTwoFactorEnabled::SESSION_KEY => now()->timestamp])
            ->post('/admin/pricing', [])
            ->assertForbidden();
    }

    // =========================================================================
    //  Kill switch
    // =========================================================================

    public function test_mematikan_kill_switch_berefek_langsung(): void
    {
        $admin = $this->adminDengan('super-admin', duaFaktorAktif: true);

        // Dibaca dulu supaya nilainya masuk cache.
        $this->assertTrue(FeatureFlag::isEnabled('orders.accepting_new', default: true));

        $this->actingAs($admin, 'admin')
            ->withSession([EnsureAdminTwoFactorEnabled::SESSION_KEY => now()->timestamp])
            ->patch('/admin/settings/flags/orders.accepting_new', [
                'enabled' => 0,
                'reason' => 'uji: memastikan cache dibatalkan saat flag diubah',
            ])
            ->assertRedirect();

        /*
         * Efeknya harus LANGSUNG, bukan menunggu cache 30 detik habis.
         *
         * Tanpa pembatalan cache, kill switch yang ditekan saat insiden tidak
         * berefek sampai setengah menit kemudian — dan yang terjadi di lapangan:
         * tim ops menekan tombolnya, tidak melihat perubahan, lalu menekannya
         * lagi beberapa kali dan menyimpulkan panelnya rusak.
         */
        $this->assertFalse(
            FeatureFlag::isEnabled('orders.accepting_new', default: true),
            'Kill switch harus berefek sekarang, bukan setelah cache habis.'
        );
    }

    public function test_mematikan_tanpa_alasan_ditolak(): void
    {
        $admin = $this->adminDengan('super-admin', duaFaktorAktif: true);

        $this->actingAs($admin, 'admin')
            ->withSession([EnsureAdminTwoFactorEnabled::SESSION_KEY => now()->timestamp])
            ->patch('/admin/settings/flags/orders.accepting_new', ['enabled' => 0])
            ->assertSessionHasErrors('reason');

        // Dan flag-nya tidak berubah.
        $this->assertTrue(FeatureFlag::isEnabled('orders.accepting_new', default: true));
    }

    public function test_menyalakan_kembali_tidak_menuntut_alasan(): void
    {
        $admin = $this->adminDengan('super-admin', duaFaktorAktif: true);

        FeatureFlag::set('orders.accepting_new', false, reason: 'uji');

        $this->actingAs($admin, 'admin')
            ->withSession([EnsureAdminTwoFactorEnabled::SESSION_KEY => now()->timestamp])
            ->patch('/admin/settings/flags/orders.accepting_new', ['enabled' => 1])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertTrue(FeatureFlag::isEnabled('orders.accepting_new', default: true));
    }

    public function test_banner_muncul_saat_ada_switch_yang_mati(): void
    {
        $admin = $this->adminDengan('super-admin', duaFaktorAktif: true);

        FeatureFlag::set('orders.accepting_new', false, reason: 'uji banner');

        $this->actingAs($admin, 'admin')
            ->withSession([EnsureAdminTwoFactorEnabled::SESSION_KEY => now()->timestamp])
            ->get('/admin/')
            ->assertOk()
            /*
             * Banner harus muncul di SETIAP halaman, bukan hanya di halaman
             * pengaturan. Switch yang lupa dinyalakan bisa bertahan berjam-jam
             * kalau peringatannya hanya ada di halaman yang justru tidak dibuka
             * saat semuanya terlihat normal.
             */
            ->assertSee('Order baru sedang DISETOP');
    }

    // =========================================================================

    private function adminDengan(string $role, bool $duaFaktorAktif): Admin
    {
        /** @var Admin $admin */
        $admin = Admin::query()->role($role, 'admin')->firstOrFail();

        $admin->forceFill([
            'two_factor_secret' => $duaFaktorAktif ? encrypt('SECRETUJI234567') : null,
            'two_factor_confirmed_at' => $duaFaktorAktif ? now() : null,
        ])->save();

        return $admin->fresh();
    }
}
