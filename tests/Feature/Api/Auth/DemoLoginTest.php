<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Auth;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * ============================================================================
 *  ENDPOINT INI MENERBITKAN TOKEN TANPA BUKTI APA PUN
 * ============================================================================
 *  Itu memang gunanya: OTP di proyek ini tidak dikirim ke mana pun, jadi tanpa
 *  jalur ini server yang sudah ter-deploy tidak bisa dimasuki siapa pun.
 *
 *  Tapi yang dilakukannya persis yang dilakukan penyerang kalau dia bisa. Yang
 *  membatasinya hanya dua hal, dan KEDUANYA harus benar:
 *
 *    1. Fiturnya mati kecuali dinyalakan eksplisit.
 *    2. Hanya akun bertanda `demo_role` yang bisa dimasuki.
 *
 *  Kalau nomor (2) lepas, siapa pun bisa masuk sebagai pengguna mana pun dengan
 *  menebak uuid — dan tidak ada satu pun galat yang muncul, karena dari sisi
 *  sistem itu login yang berhasil.
 * ============================================================================
 */
class DemoLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['antaride.demo.enabled' => true]);
    }

    // =========================================================================
    //  Penjagaan
    // =========================================================================

    /**
     * ========================================================================
     *  INI TEST YANG PALING PENTING DI BERKAS INI
     * ========================================================================
     *  Akun SUNGGUHAN tidak boleh bisa dimasuki lewat endpoint ini, bahkan
     *  ketika fiturnya menyala dan uuid-nya diketahui.
     *
     *  Kalau penjagaan ini lepas, endpoint ini berubah dari alat pengujian
     *  menjadi pengambilalihan akun massal — dan berhasilnya terlihat persis
     *  sama dengan login yang sah.
     * ========================================================================
     */
    public function test_akun_sungguhan_tidak_bisa_dimasuki(): void
    {
        $korban = User::factory()->create();

        $this->assertNull($korban->demo_role, 'Prasyarat: ini akun biasa.');

        $response = $this->postJson('/api/v1/auth/demo/login', [
            'uuid' => (string) $korban->uuid,
        ]);

        $response->assertStatus(404);

        $this->assertSame(
            0,
            DB::table('personal_access_tokens')->count(),
            'Token diterbitkan untuk akun yang BUKAN akun demo. Siapa pun yang '
            .'tahu uuid seorang pengguna bisa masuk sebagai dia.',
        );
    }

    /**
     * ========================================================================
     *  MATI SECARA BAWAAN
     * ========================================================================
     *  Server yang lupa menyetel `ANTARIDE_DEMO_LOGIN` harus MENOLAK, bukan
     *  mengizinkan. Konfigurasi yang hilang adalah kelalaian, dan kelalaian
     *  harus berakhir tertutup.
     *
     *  404, bukan 403: fitur yang mati sebaiknya tidak mengaku ada.
     * ========================================================================
     */
    public function test_mati_secara_bawaan(): void
    {
        config(['antaride.demo.enabled' => false]);

        $demo = $this->akunDemo('customer');

        $this->postJson('/api/v1/auth/demo/login', [
            'uuid' => (string) $demo->uuid,
        ])->assertStatus(404);

        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    /**
     * Saat fiturnya mati, DAFTAR akun menjawab kosong — bukan galat.
     *
     * Aplikasi memanggilnya di layar masuk, sebelum pengguna melakukan apa pun.
     * Galat di sana muncul sebagai pesan merah di layar pertama, untuk sesuatu
     * yang bukan masalah penggunanya.
     */
    public function test_daftar_kosong_saat_fitur_mati(): void
    {
        config(['antaride.demo.enabled' => false]);

        $this->akunDemo('customer');

        $response = $this->getJson('/api/v1/auth/demo/accounts?role=customer');

        $response->assertOk();
        $response->assertJsonPath('data.enabled', false);
        $response->assertJsonPath('data.accounts', []);
    }

    /**
     * Akun demo yang ditangguhkan tetap ditolak.
     *
     * Kalau tidak, akun demo menjadi cara memutari penangguhan — dan jalur demo
     * yang aturannya berbeda dari jalur sungguhan membuat pengujiannya tidak
     * berarti.
     */
    public function test_akun_demo_yang_ditangguhkan_ditolak(): void
    {
        $demo = $this->akunDemo('customer');

        DB::table('users')->where('id', $demo->id)->update(['status' => 'suspended']);

        $this->postJson('/api/v1/auth/demo/login', [
            'uuid' => (string) $demo->uuid,
        ])->assertStatus(403);
    }

    // =========================================================================
    //  Jalur normal
    // =========================================================================

    public function test_akun_demo_bisa_masuk_dan_dapat_token(): void
    {
        $demo = $this->akunDemo('driver');

        $response = $this->postJson('/api/v1/auth/demo/login', [
            'uuid' => (string) $demo->uuid,
            'device_id' => 'uji',
            'platform' => 'android',
        ]);

        $response->assertOk();

        $this->assertNotEmpty($response->json('data.token'));
        $response->assertJsonPath('data.token_type', 'Bearer');

        /*
         * `is_new_user` harus FALSE.
         *
         * Akun demo sudah punya riwayat, saldo, dan dokumen. Menandainya baru
         * akan memicu alur perkenalan di aplikasi — dan penguji dibawa ke layar
         * pengisian profil untuk akun yang profilnya sudah lengkap.
         */
        $response->assertJsonPath('data.is_new_user', false);
    }

    /**
     * Token dari akun demo benar-benar bisa dipakai.
     *
     * Yang dijaga: token yang terbit tapi ditolak endpoint lain. Itu akan
     * terlihat sebagai login yang berhasil lalu setiap layar menampilkan galat.
     */
    public function test_token_demo_bisa_dipakai_memanggil_api(): void
    {
        $demo = $this->akunDemo('customer');

        $token = (string) $this->postJson('/api/v1/auth/demo/login', [
            'uuid' => (string) $demo->uuid,
        ])->json('data.token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.uuid', (string) $demo->uuid);
    }

    // =========================================================================
    //  Penyaringan peran
    // =========================================================================

    /**
     * ========================================================================
     *  APLIKASI DRIVER TIDAK BOLEH MENAWARKAN AKUN PENUMPANG
     * ========================================================================
     *  Yang menekannya akan masuk sebagai penumpang DI APLIKASI DRIVER — dan
     *  seluruh layarnya kosong, karena akun itu tidak punya baris di tabel
     *  `drivers`.
     *
     *  Tidak ada galat yang muncul untuk menjelaskannya: token-nya sah, dan
     *  endpoint driver hanya menjawab 403 yang terbaca sebagai aplikasi rusak.
     * ========================================================================
     */
    public function test_daftar_disaring_per_peran(): void
    {
        $this->akunDemo('customer', 'Penumpang Demo');
        $this->akunDemo('driver', 'Driver Demo');
        $this->akunDemo('merchant', 'Merchant Demo');

        foreach (['customer', 'driver', 'merchant'] as $role) {
            $akun = $this->getJson("/api/v1/auth/demo/accounts?role=$role")
                ->assertOk()
                ->json('data.accounts');

            $this->assertCount(1, $akun, "Peran $role harus mengembalikan tepat satu akun.");

            $this->assertSame(
                $role,
                $akun[0]['role'],
                "Aplikasi $role menerima akun demo milik peran lain.",
            );
        }
    }

    public function test_peran_asing_mengembalikan_daftar_kosong(): void
    {
        $this->akunDemo('customer');

        $this->getJson('/api/v1/auth/demo/accounts?role=admin')
            ->assertOk()
            ->assertJsonPath('data.accounts', []);
    }

    /**
     * Daftar memuat keterangan yang bisa dibaca penguji.
     *
     * Tanpa `note`, penguji harus mencoba satu per satu untuk menemukan akun
     * yang saldonya cukup atau dokumennya lengkap.
     */
    public function test_daftar_memuat_nama_dan_keterangan(): void
    {
        $this->akunDemo('driver', 'Sutrisno Demo', 'Saldo Rp 100.000, dokumen lengkap.');

        $akun = $this->getJson('/api/v1/auth/demo/accounts?role=driver')
            ->json('data.accounts.0');

        $this->assertSame('Sutrisno Demo', $akun['name']);
        $this->assertSame('Saldo Rp 100.000, dokumen lengkap.', $akun['note']);
        $this->assertNotEmpty($akun['phone']);
        $this->assertNotEmpty($akun['uuid']);
    }

    // =========================================================================
    //  Jejak
    // =========================================================================

    /**
     * Setiap pemakaian dicatat.
     *
     * Masuk lewat akun demo melewati OTP sepenuhnya, jadi tidak ada jejak
     * autentikasi yang biasanya tertinggal. Kalau nanti ada yang bertanya
     * "kenapa akun ini membuat order itu", jawabannya harus ada di log.
     */
    public function test_pemakaian_dicatat_ke_log(): void
    {
        Log::shouldReceive('channel')
            ->with('demo')
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $pesan, array $konteks): bool {
                return str_contains($pesan, 'demo')
                    && array_key_exists('user_uuid', $konteks)
                    && array_key_exists('ip', $konteks);
            });

        $demo = $this->akunDemo('customer');

        $this->postJson('/api/v1/auth/demo/login', [
            'uuid' => (string) $demo->uuid,
        ])->assertOk();
    }

    // =========================================================================

    private function akunDemo(
        string $role,
        string $nama = 'Akun Demo',
        ?string $catatan = null,
    ): User {
        return User::factory()->create([
            'name' => $nama,
            'demo_role' => $role,
            'demo_order' => 1,
            'demo_note' => $catatan,
        ]);
    }
}
