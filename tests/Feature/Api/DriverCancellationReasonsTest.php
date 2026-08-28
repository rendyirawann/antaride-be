<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Driver\Models\Driver;
use App\Domain\Identity\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\SystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ============================================================================
 *  ALASAN PEMBATALAN DRIVER HARUS DATANG DARI BACKEND
 * ============================================================================
 *  `cancellation_reasons` disaring per `actor_type`, dan penyaringan itu
 *  ditegakkan validasi `DriverCancelOrderRequest`: driver yang mengirim kode
 *  milik penumpang ditolak 422.
 *
 *  Sebelum endpoint ini ada, aplikasi driver menyimpan daftarnya sendiri — dan
 *  daftar itu memakai kode yang salah (`driver_vehicle_issue` alih-alih
 *  `DRV_VEHICLE_PROBLEM`), jadi SETIAP pembatalan oleh driver ditolak.
 *
 *  Test ini menjaga tiga hal yang membuat kegagalan itu tidak terulang:
 *
 *    1. kodenya benar-benar bisa dipakai membatalkan (bukan sekadar ada)
 *    2. alasan penumpang TIDAK ikut terkirim
 *    3. `lowers_score` terkirim, supaya driver tahu konsekuensi pilihannya
 * ============================================================================
 */
class DriverCancellationReasonsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSeeder::class);
        $this->seed(CatalogSeeder::class);
    }

    public function test_driver_mendapat_daftar_alasan_pembatalan(): void
    {
        $this->aktingSebagaiDriver();

        $response = $this->getJson('/api/v1/driver/orders/cancellation-reasons');

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $data = $response->json('data');

        $this->assertNotEmpty(
            $data,
            'Daftar kosong berarti aplikasi driver tidak punya satu pun kode '
            .'yang bisa dipakai membatalkan.',
        );

        foreach ($data as $reason) {
            $this->assertArrayHasKey('code', $reason);
            $this->assertArrayHasKey('text', $reason);
            $this->assertArrayHasKey('lowers_score', $reason);
            $this->assertArrayHasKey('may_charge_fee', $reason);
        }
    }

    /**
     * Alasan penumpang tidak boleh ikut terkirim.
     *
     * Kalau ikut, aplikasi driver akan menampilkannya, dan driver yang
     * memilihnya mendapat 422 dari validasi — galat yang tidak menjelaskan
     * apa pun kepadanya.
     */
    public function test_alasan_penumpang_tidak_ikut_terkirim(): void
    {
        $this->aktingSebagaiDriver();

        $kode = collect(
            $this->getJson('/api/v1/driver/orders/cancellation-reasons')->json('data')
        )->pluck('code')->all();

        $this->assertNotEmpty($kode);

        $kodePenumpang = DB::table('cancellation_reasons')
            ->where('actor_type', 'user')
            ->pluck('code')
            ->all();

        $this->assertNotEmpty(
            $kodePenumpang,
            'Seeder harus mengisi alasan penumpang, kalau tidak test ini tidak '
            .'menguji apa pun.',
        );

        foreach ($kodePenumpang as $milikPenumpang) {
            $this->assertNotContains($milikPenumpang, $kode);
        }
    }

    /**
     * Setiap kode yang dikirim benar-benar lolos validasi pembatalan driver.
     *
     * ========================================================================
     *  INI INTI DARI TEST INI
     * ========================================================================
     *  Endpoint yang mengembalikan daftar yang "terlihat benar" tapi kodenya
     *  ditolak validasi adalah kegagalan yang paling sulit dilihat: response-nya
     *  200, isinya wajar, dan yang gagal baru muncul saat driver menekan
     *  batalkan di jalan.
     *
     *  Yang diuji di sini bukan controller-nya, tapi KESEPAKATAN antara
     *  endpoint daftar dan aturan `Rule::exists` di DriverCancelOrderRequest.
     * ========================================================================
     */
    public function test_setiap_kode_lolos_aturan_validasi_pembatalan(): void
    {
        $this->aktingSebagaiDriver();

        $kode = collect(
            $this->getJson('/api/v1/driver/orders/cancellation-reasons')->json('data')
        )->pluck('code')->all();

        foreach ($kode as $satu) {
            $adaDanBolehDipakaiDriver = DB::table('cancellation_reasons')
                ->where('code', $satu)
                ->where('actor_type', 'driver')
                ->where('is_active', true)
                ->exists();

            $this->assertTrue(
                $adaDanBolehDipakaiDriver,
                "Kode '{$satu}' dikirim ke aplikasi tapi tidak lolos aturan "
                .'exists() di DriverCancelOrderRequest — pembatalan dengan kode '
                .'ini akan ditolak 422.',
            );
        }
    }

    /**
     * Setidaknya satu alasan menurunkan skor, dan itu diberitahukan.
     *
     * Kalau seluruh `lowers_score` bernilai false, ada dua kemungkinan: seeder
     * berubah, atau field-nya tidak ikut terkirim. Keduanya membuat driver tidak
     * pernah tahu pilihan mana yang memengaruhi prioritasnya.
     */
    public function test_penanda_penurun_skor_diberitahukan(): void
    {
        $this->aktingSebagaiDriver();

        $data = $this->getJson('/api/v1/driver/orders/cancellation-reasons')
            ->json('data');

        $adaYangMenurunkan = collect($data)
            ->contains(static fn (array $r): bool => $r['lowers_score'] === true);

        $this->assertTrue(
            $adaYangMenurunkan,
            'Tidak ada satu pun alasan bertanda lowers_score. Driver jadi tidak '
            .'punya cara mengetahui pilihan mana yang menurunkan prioritasnya.',
        );
    }

    /**
     * Pengguna yang bukan driver ditolak.
     *
     * Endpoint driver yang bisa dibuka pengguna biasa adalah cara termurah untuk
     * memetakan akun mana yang driver.
     */
    public function test_pengguna_bukan_driver_ditolak(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/driver/orders/cancellation-reasons')
            ->assertForbidden();
    }

    public function test_tanpa_autentikasi_ditolak(): void
    {
        $this->getJson('/api/v1/driver/orders/cancellation-reasons')
            ->assertUnauthorized();
    }

    // -------------------------------------------------------------------------

    private function aktingSebagaiDriver(): Driver
    {
        $driver = Driver::factory()->create();

        Sanctum::actingAs($driver->user);

        return $driver;
    }
}
