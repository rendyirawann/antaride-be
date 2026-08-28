<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ============================================================================
 *  KENAPA TEST INI ADA
 * ============================================================================
 *  Middleware idempotency belum dipakai satu route pun saat test ini ditulis,
 *  jadi tidak ada test yang gagal karena bug di dalamnya. Dua bug bersembunyi
 *  di sana, dan keduanya baru akan muncul di produksi:
 *
 *   1. KEBOCORAN ANTAR PENGGUNA. `key` adalah primary key global dan
 *      request_hash tidak memuat identitas pemilik. Pengguna B yang memakai
 *      kunci yang sama dengan pengguna A untuk permintaan yang bentuknya sama
 *      akan menerima RESPONSE ORDER A — termasuk UUID order dan alamatnya.
 *
 *   2. KLAIM MATI YANG MENGUNCI 24 JAM. `locked_at` ditulis tapi tidak pernah
 *      dibaca, jadi proses yang mati tanpa exception meninggalkan klaim yang
 *      tidak pernah dilepas, dan pengguna dijawab 409 sampai kadaluarsa.
 *
 *  Route uji didaftarkan di dalam test, bukan menunggu endpoint sungguhan ada.
 *  Yang diuji di sini perilaku middleware-nya, dan itu tidak boleh menunggu
 *  controller mana pun selesai.
 * ============================================================================
 */
class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private const PATH = '/_uji/idempotency';

    private const KEY = 'kunci-uji-0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Route-nya mengembalikan id pengguna yang terautentikasi, dan sebuah
         * angka acak yang berbeda setiap eksekusi.
         *
         * Keduanya penting. Id pengguna yang membuktikan apakah ada response
         * milik orang lain yang bocor; angka acak yang membuktikan apakah
         * badannya benar-benar diputar ulang atau dieksekusi lagi.
         */
        Route::post(self::PATH, function () {
            return response()->json([
                'user_id' => auth()->id(),
                'nonce' => random_int(1, PHP_INT_MAX),
            ]);
        })->middleware(['auth:sanctum', 'idempotency']);

        Route::post(self::PATH.'/gagal', function () {
            return response()->json(['success' => false], 422);
        })->middleware(['auth:sanctum', 'idempotency']);

        Route::post(self::PATH.'/meledak', function () {
            throw new \RuntimeException('sengaja gagal');
        })->middleware(['auth:sanctum', 'idempotency']);
    }

    // =========================================================================
    //  Perilaku dasar
    // =========================================================================

    public function test_permintaan_pertama_dieksekusi_dan_disimpan(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->kirim();

        $response->assertOk();
        $response->assertHeaderMissing('Idempotency-Replayed');

        $this->assertSame(1, DB::table('idempotency_keys')->count());

        $row = DB::table('idempotency_keys')->first();
        $this->assertNotNull($row->response_body);
        $this->assertNull($row->locked_at, 'locked_at harus dilepas setelah selesai.');
        $this->assertSame(200, (int) $row->status_code);
    }

    public function test_permintaan_kedua_yang_sama_diputar_ulang_bukan_dieksekusi(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $pertama = $this->kirim();
        $kedua = $this->kirim();

        $kedua->assertOk();
        $kedua->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame(
            $pertama->json('nonce'),
            $kedua->json('nonce'),
            'nonce yang berbeda berarti route dieksekusi dua kali — idempotency tidak bekerja.'
        );

        $this->assertSame(1, DB::table('idempotency_keys')->count());
    }

    public function test_kunci_sama_dengan_isi_berbeda_ditolak(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->kirim(['jumlah' => 10_000])->assertOk();

        $this->kirim(['jumlah' => 999_000])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
    }

    // =========================================================================
    //  Kebocoran antar pengguna — inti dari perbaikan ini
    // =========================================================================

    public function test_pengguna_lain_dengan_kunci_sama_tidak_menerima_response_pengguna_pertama(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();

        Sanctum::actingAs($a);
        $responseA = $this->kirim(['tujuan' => 'Sun Plaza']);
        $responseA->assertOk();
        $this->assertSame($a->id, $responseA->json('user_id'));

        // Pengguna B memakai kunci YANG SAMA dengan payload YANG SAMA.
        Sanctum::actingAs($b);
        $responseB = $this->kirim(['tujuan' => 'Sun Plaza']);

        $responseB->assertOk();
        $responseB->assertHeaderMissing('Idempotency-Replayed');

        $this->assertSame(
            $b->id,
            $responseB->json('user_id'),
            'Pengguna B menerima response milik pengguna A. Ini kebocoran data.'
        );

        $this->assertNotSame(
            $responseA->json('nonce'),
            $responseB->json('nonce'),
            'Badan response B identik dengan A, artinya response A diputar ulang untuk B.'
        );

        // Dua baris terpisah, satu per pemilik.
        $this->assertSame(2, DB::table('idempotency_keys')->count());
        $this->assertSame(
            2,
            DB::table('idempotency_keys')->distinct()->count('owner_key'),
            'Kunci yang sama harus tersimpan sebagai dua baris dengan owner_key berbeda.'
        );
    }

    public function test_pemilik_kunci_ikut_masuk_ke_request_hash(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();

        Sanctum::actingAs($a);
        $this->kirim(['tujuan' => 'Sun Plaza']);

        Sanctum::actingAs($b);
        $this->kirim(['tujuan' => 'Sun Plaza']);

        $hashes = DB::table('idempotency_keys')->pluck('request_hash')->all();

        $this->assertCount(2, array_unique($hashes),
            'Permintaan dengan isi identik dari dua pengguna berbeda harus punya '
            .'request_hash berbeda. Kalau sama, satu-satunya yang memisahkan '
            .'mereka adalah primary key — dan itu pernah tidak cukup.'
        );
    }

    // =========================================================================
    //  Klaim mati
    // =========================================================================

    public function test_klaim_yang_masih_hidup_dijawab_409(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->kirim()->assertOk();

        // Simulasi proses yang mati SEKARANG: response dibuang, klaim dipasang
        // ulang dengan waktu sekarang. Baris ini masih dianggap hidup.
        $this->simulasikanKlaimMati(detikLalu: 5);

        $this->kirim()
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_IN_PROGRESS');
    }

    public function test_klaim_yang_sudah_mati_diambil_alih_dan_dieksekusi_ulang(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $pertama = $this->kirim();
        $pertama->assertOk();

        // Proses yang memegang klaim mati sepuluh menit lalu tanpa exception.
        // Tanpa takeover, pengguna terkunci 409 sampai 24 jam.
        $this->simulasikanKlaimMati(detikLalu: 600);

        $kedua = $this->kirim();

        $kedua->assertOk();
        $this->assertNotSame(
            $pertama->json('nonce'),
            $kedua->json('nonce'),
            'Klaim mati harus diambil alih dan permintaannya dieksekusi, bukan dijawab 409 selamanya.'
        );

        // Setelah diambil alih dan selesai, barisnya kembali normal.
        $row = DB::table('idempotency_keys')->first();
        $this->assertNotNull($row->response_body);
        $this->assertNull($row->locked_at);
    }

    public function test_batas_klaim_mati_bisa_diatur(): void
    {
        config(['antaride.idempotency.lock_ttl_seconds' => 3600]);

        Sanctum::actingAs(User::factory()->create());
        $this->kirim()->assertOk();

        // Sepuluh menit lalu, tapi batasnya sekarang satu jam. Masih hidup.
        $this->simulasikanKlaimMati(detikLalu: 600);

        $this->kirim()->assertStatus(409);
    }

    // =========================================================================
    //  Syarat masuk
    // =========================================================================

    public function test_tanpa_header_ditolak(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson(self::PATH, [])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED');
    }

    public function test_header_terlalu_pendek_ditolak(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson(self::PATH, [], ['Idempotency-Key' => 'pendek'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_INVALID');
    }

    public function test_header_dengan_karakter_tidak_wajar_ditolak(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson(self::PATH, [], ['Idempotency-Key' => 'kunci dengan spasi 1234567'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_INVALID');
    }

    // =========================================================================
    //  Kegagalan tidak boleh mengunci kunci
    // =========================================================================

    public function test_response_gagal_tidak_mengunci_kunci(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson(self::PATH.'/gagal', [], ['Idempotency-Key' => self::KEY])
            ->assertStatus(422);

        $this->assertSame(
            0,
            DB::table('idempotency_keys')->count(),
            'Kegagalan validasi harus boleh diperbaiki dan dikirim ulang dengan kunci yang sama.'
        );
    }

    public function test_exception_melepas_kunci(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->withoutExceptionHandling();

        try {
            $this->postJson(self::PATH.'/meledak', [], ['Idempotency-Key' => self::KEY]);
            $this->fail('Route uji seharusnya melempar exception.');
        } catch (\RuntimeException $e) {
            $this->assertSame('sengaja gagal', $e->getMessage());
        }

        $this->assertSame(
            0,
            DB::table('idempotency_keys')->count(),
            'Kunci harus dilepas saat eksekusi melempar exception, kalau tidak client '
            .'tidak bisa mencoba lagi walaupun tidak ada apa pun yang tersimpan.'
        );
    }

    // =========================================================================

    /**
     * @param  array<string, mixed>  $payload
     */
    private function kirim(array $payload = ['tujuan' => 'Merdeka Walk']): TestResponse
    {
        return $this->postJson(self::PATH, $payload, ['Idempotency-Key' => self::KEY]);
    }

    /**
     * Mengubah baris klaim menjadi seperti ditinggalkan proses yang mati:
     * response_body kosong, locked_at sekian detik yang lalu.
     *
     * Dilakukan dengan UPDATE, bukan INSERT baru, supaya request_hash-nya tetap
     * hash yang sebenarnya dihitung middleware. Menghitung ulang hash itu di
     * dalam test berarti menduplikasi logika yang sedang diuji, dan test seperti
     * itu akan tetap lulus walaupun rumus hash-nya salah.
     */
    private function simulasikanKlaimMati(int $detikLalu): void
    {
        DB::table('idempotency_keys')->update([
            'response_body' => null,
            'status_code' => null,
            'locked_at' => now()->subSeconds($detikLalu),
        ]);
    }
}
