<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Driver;

use App\Domain\Driver\Models\Driver;
use App\Domain\Driver\Models\DriverDocument;
use App\Domain\Identity\Models\User;
use App\Http\Requests\Api\V1\Driver\UploadDocumentRequest;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\SystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ============================================================================
 *  INI TITIK MASUK BERKAS DARI LUAR — YANG DIUJI TERUTAMA YANG DITOLAK
 * ============================================================================
 *  Endpoint unggah adalah satu-satunya tempat di seluruh API yang menerima
 *  BERKAS dari perangkat yang tidak dipercaya, lalu MENULISNYA ke disk kita.
 *  Kesalahan di sini tidak berbentuk data yang salah — dia berbentuk berkas di
 *  server, dengan nama dan isi yang ditentukan orang lain.
 *
 *  Yang dijaga di berkas ini, dan semuanya sudah pernah menjadi kerentanan nyata
 *  di aplikasi lain:
 *
 *    * berkas non-gambar yang dinamai `.jpg`
 *    * nama berkas yang memuat `../` untuk keluar dari direktorinya
 *    * ekstensi yang diambil dari nama berkas client, bukan dari isinya
 *    * dokumen driver A yang bisa dibaca driver B
 *    * dokumen yang sudah DISETUJUI lalu diganti berkas lain tanpa diperiksa
 *      lagi
 * ============================================================================
 */
class DriverDocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSeeder::class);
        $this->seed(CatalogSeeder::class);

        // Disk KYC dipalsukan: test tidak boleh meninggalkan berkas di
        // `storage/app/private/kyc`, dan test yang menulis ke disk sungguhan
        // akan saling melihat berkas satu sama lain.
        Storage::fake('kyc');
    }

    // =========================================================================
    //  Unggah yang berhasil
    // =========================================================================

    public function test_driver_bisa_mengunggah_ktp(): void
    {
        $driver = $this->driver();

        Sanctum::actingAs($driver->user);

        $response = $this->postJson('/api/v1/driver/documents', [
            'type' => 'ktp',
            'file' => $this->gambar('ktp.jpg'),
        ]);

        $response->assertOk();

        $response->assertJsonPath('data.type', 'ktp');
        $response->assertJsonPath('data.status', 'pending');

        $this->assertTrue(
            DB::table('driver_documents')
                ->where('driver_id', $driver->id)
                ->where('type', 'ktp')
                ->exists(),
            'Barisnya tidak tersimpan.',
        );
    }

    /**
     * Berkasnya benar-benar ada di disk, dan namanya BUKAN nama dari client.
     *
     * ========================================================================
     *  NAMA DARI CLIENT TIDAK PERNAH DIPAKAI
     * ========================================================================
     *  Dua hal yang dijaga sekaligus:
     *
     *    Tabrakan   dua driver mengunggah `ktp.jpg`. Yang kedua menimpa yang
     *               pertama, dan KTP driver A menjadi KTP driver B di mata
     *               verifikator. Tanpa satu pun galat.
     *
     *    Ekstensi   nama `foto.jpg.php` yang isinya benar-benar JPEG. Di server
     *               yang salah konfigurasi, itu berkas yang bisa dieksekusi.
     * ========================================================================
     */
    public function test_berkas_tersimpan_dengan_nama_yang_kita_tentukan(): void
    {
        $driver = $this->driver();

        Sanctum::actingAs($driver->user);

        $this->postJson('/api/v1/driver/documents', [
            'type' => 'ktp',
            'file' => $this->gambar('ktp-saya.jpg'),
        ])->assertOk();

        $path = (string) DB::table('driver_documents')
            ->where('driver_id', $driver->id)
            ->value('file_path');

        Storage::disk('kyc')->assertExists($path);

        $this->assertStringNotContainsString(
            'ktp-saya',
            $path,
            'Nama berkas dari client dipakai apa adanya. Dua driver yang '
            .'mengunggah nama yang sama akan saling menimpa dokumennya.',
        );

        $this->assertStringStartsWith(
            'driver/'.$driver->id.'/',
            $path,
            'Berkas tidak masuk direktori per driver. Permintaan penghapusan '
            .'data akan menjadi pencarian di seluruh bucket, bukan satu '
            .'penghapusan direktori.',
        );

        $this->assertStringEndsWith('.jpg', $path);
    }

    /**
     * Nama berkas yang memuat `../` tidak bisa keluar dari direktorinya.
     *
     * Path traversal lewat nama berkas adalah percobaan pertama yang dilakukan
     * siapa pun yang menguji endpoint unggah.
     */
    public function test_nama_berkas_tidak_bisa_keluar_dari_direktori(): void
    {
        $driver = $this->driver();

        Sanctum::actingAs($driver->user);

        $this->postJson('/api/v1/driver/documents', [
            'type' => 'ktp',
            'file' => $this->gambar('../../../../rahasia.jpg'),
        ])->assertOk();

        $path = (string) DB::table('driver_documents')
            ->where('driver_id', $driver->id)
            ->value('file_path');

        $this->assertStringNotContainsString(
            '..',
            $path,
            'Path berkas memuat `..`. Berkas bisa ditulis di luar direktori '
            .'yang dimaksud.',
        );

        $this->assertStringStartsWith('driver/'.$driver->id.'/', $path);
    }

    /**
     * Ekstensi diambil dari ISI berkas, bukan dari namanya.
     *
     * Berkas PNG yang dinamai `.jpg` harus tersimpan sebagai `.png`.
     */
    public function test_ekstensi_mengikuti_isi_berkas_bukan_namanya(): void
    {
        $driver = $this->driver();

        Sanctum::actingAs($driver->user);

        // PNG sungguhan, tapi dinamai .jpg.
        $this->postJson('/api/v1/driver/documents', [
            'type' => 'ktp',
            'file' => UploadedFile::fake()->image('ktp.jpg', 800, 600)->mimeType('image/png'),
        ])->assertOk();

        $path = (string) DB::table('driver_documents')
            ->where('driver_id', $driver->id)
            ->value('file_path');

        $this->assertStringEndsWith(
            '.png',
            $path,
            'Ekstensi diambil dari nama berkas client. Berkas bernama '
            .'`foto.jpg.php` akan tersimpan dengan ekstensi `.php`.',
        );
    }

    // =========================================================================
    //  Yang ditolak
    // =========================================================================

    /**
     * ========================================================================
     *  INI TEST YANG PALING PENTING DI BERKAS INI
     * ========================================================================
     *  Berkas yang BUKAN gambar harus ditolak, walaupun namanya `.jpg` dan
     *  walaupun client menyatakan `Content-Type: image/jpeg`.
     *
     *  Keduanya datang dari client, dan client bisa menuliskan apa pun. Yang
     *  memutuskan harus isi berkasnya.
     * ========================================================================
     */
    public function test_berkas_bukan_gambar_ditolak(): void
    {
        $driver = $this->driver();

        Sanctum::actingAs($driver->user);

        $response = $this->postJson('/api/v1/driver/documents', [
            'type' => 'ktp',

            // Skrip PHP dengan nama .jpg dan MIME yang mengaku gambar.
            'file' => UploadedFile::fake()->createWithContent(
                'ktp.jpg',
                '<?php echo "halo"; ?>',
            )->mimeType('image/jpeg'),
        ]);

        $response->assertStatus(422);

        $this->assertSame(
            0,
            DB::table('driver_documents')->count(),
            'Berkas non-gambar tersimpan sebagai dokumen. Nama dan MIME '
            .'keduanya dari client — yang memutuskan harus isi berkasnya.',
        );

        $this->assertSame(
            [],
            Storage::disk('kyc')->allFiles(),
            'Berkas non-gambar sampai ke disk. Validasi berjalan SETELAH '
            .'penyimpanan.',
        );
    }

    /**
     * Foto yang terlalu kecil ditolak dengan alasan yang bisa ditindak.
     *
     * Foto 100x75 lolos sebagai "gambar" tapi tulisan di KTP-nya tidak terbaca.
     * Verifikator akan menolaknya, driver mengunggah ulang, dan putaran itu
     * berulang beberapa hari — untuk sesuatu yang bisa diberitahukan sejak
     * unggahan pertama.
     */
    public function test_foto_terlalu_kecil_ditolak(): void
    {
        $driver = $this->driver();

        Sanctum::actingAs($driver->user);

        $response = $this->postJson('/api/v1/driver/documents', [
            'type' => 'ktp',
            'file' => UploadedFile::fake()->image('ktp.jpg', 200, 150),
        ]);

        $response->assertStatus(422);

        /*
         * Bentuk galat validasi di API ini BUKAN bentuk bawaan Laravel.
         *
         * `ApiExceptionRenderer` mengubah `ValidationException` menjadi
         * `{success:false, error:{code:'VALIDATION_FAILED', details:{...}}}`, jadi
         * `assertJsonValidationErrors` — yang mencari kunci `errors` di akar —
         * tidak menemukan apa pun dan LULUS untuk alasan yang salah.
         *
         * Yang diperiksa: kode galatnya, dan bahwa kolom `file` yang disebut.
         * Tanpa yang kedua, test ini akan tetap hijau kalau nanti penolakannya
         * datang dari aturan lain — misalnya `type` yang salah.
         */
        $response->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertArrayHasKey(
            'file',
            (array) $response->json('error.details'),
            'Penolakannya bukan karena kolom `file`. Aturan dimensi minimum '
            .'tidak bekerja, dan foto yang tulisannya tidak terbaca akan masuk '
            .'antrean verifikasi.',
        );
    }

    public function test_jenis_dokumen_asing_ditolak(): void
    {
        $driver = $this->driver();

        Sanctum::actingAs($driver->user);

        $this->postJson('/api/v1/driver/documents', [
            'type' => 'paspor',
            'file' => $this->gambar('paspor.jpg'),
        ])->assertStatus(422);
    }

    /**
     * Dokumen yang sudah KADALUARSA tidak masuk antrean verifikasi.
     *
     * Menolaknya di sini menghemat satu putaran penuh: driver langsung tahu dia
     * perlu memperpanjang dulu, bukan menunggu dua hari untuk diberi tahu hal
     * yang sama.
     */
    public function test_tanggal_berlaku_yang_sudah_lewat_ditolak(): void
    {
        $driver = $this->driver();

        Sanctum::actingAs($driver->user);

        $this->postJson('/api/v1/driver/documents', [
            'type' => 'sim',
            'file' => $this->gambar('sim.jpg'),
            'expires_at' => now()->subDay()->toDateString(),
        ])->assertStatus(422);
    }

    /**
     * Akun yang bukan driver ditolak 403, bukan 500.
     *
     * Satu orang bisa punya akun penumpang saja, dan token yang sama dipakai
     * kedua aplikasi. Ini bukan keadaan yang mustahil.
     */
    public function test_akun_bukan_driver_ditolak(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/driver/documents', [
            'type' => 'ktp',
            'file' => $this->gambar('ktp.jpg'),
        ])->assertStatus(403);
    }

    public function test_tanpa_token_ditolak(): void
    {
        $this->postJson('/api/v1/driver/documents', [
            'type' => 'ktp',
        ])->assertStatus(401);
    }

    // =========================================================================
    //  Unggah ulang
    // =========================================================================

    /**
     * ========================================================================
     *  UNGGAH ULANG MENGEMBALIKAN STATUS KE `pending`, TERMASUK YANG SUDAH
     *  DISETUJUI — DAN INI YANG PALING MUDAH TERLEWAT
     * ========================================================================
     *  Tanpa reset: driver mengunggah KTP-nya sendiri, disetujui, lalu
     *  mengunggah KTP orang lain. Barisnya tetap `approved`.
     *
     *  Yang dilihat verifikator berikutnya: dokumen yang sudah lolos. Yang
     *  sebenarnya ada di disk: dokumen yang tidak pernah diperiksa siapa pun.
     * ========================================================================
     */
    public function test_unggah_ulang_mengembalikan_status_ke_pending(): void
    {
        $driver = $this->driver();

        Sanctum::actingAs($driver->user);

        $this->postJson('/api/v1/driver/documents', [
            'type' => 'ktp',
            'file' => $this->gambar('ktp.jpg'),
        ])->assertOk();

        // Disetujui verifikator.
        DB::table('driver_documents')
            ->where('driver_id', $driver->id)
            ->update([
                'status' => 'approved',
                'reviewed_at' => now(),
                'reject_reason' => null,
            ]);

        $this->postJson('/api/v1/driver/documents', [
            'type' => 'ktp',
            'file' => $this->gambar('ktp-baru.jpg'),
        ])->assertOk();

        $baris = DB::table('driver_documents')
            ->where('driver_id', $driver->id)
            ->first();

        $this->assertSame(
            'pending',
            $baris->status,
            'Dokumen yang sudah disetujui tetap `approved` setelah berkasnya '
            .'diganti. Driver bisa menukar KTP-nya dengan milik orang lain '
            .'setelah lolos verifikasi.',
        );

        $this->assertNull(
            $baris->reviewed_at,
            'Jejak review lama tertinggal. Panel admin akan menampilkan '
            .'"sudah diperiksa" untuk berkas yang belum dilihat siapa pun.',
        );
    }

    /**
     * Unggah ulang MENGGANTI barisnya, tidak menambah baris kedua.
     *
     * `unique(driver_id, type)` di database melarangnya — jadi tanpa penanganan
     * yang benar, unggahan kedua akan gagal dengan galat 500 alih-alih mengganti.
     */
    public function test_unggah_ulang_tidak_membuat_baris_kedua(): void
    {
        $driver = $this->driver();

        Sanctum::actingAs($driver->user);

        $this->postJson('/api/v1/driver/documents', [
            'type' => 'ktp',
            'file' => $this->gambar('a.jpg'),
        ])->assertOk();

        $this->postJson('/api/v1/driver/documents', [
            'type' => 'ktp',
            'file' => $this->gambar('b.jpg'),
        ])->assertOk();

        $this->assertSame(
            1,
            DB::table('driver_documents')->where('driver_id', $driver->id)->count(),
        );
    }

    /**
     * Berkas LAMA dibuang dari disk saat diganti.
     *
     * Kalau tidak: setiap unggahan ulang meninggalkan satu foto KTP yang tidak
     * ditunjuk baris mana pun. Tidak bisa ditemukan, tidak bisa dihapus atas
     * permintaan, dan jumlahnya tumbuh selamanya. Untuk data identitas, tumpukan
     * seperti itu adalah kewajiban hukum yang menumpuk tanpa disadari.
     */
    public function test_berkas_lama_dibuang_saat_diganti(): void
    {
        $driver = $this->driver();

        Sanctum::actingAs($driver->user);

        $this->postJson('/api/v1/driver/documents', [
            'type' => 'ktp',
            'file' => $this->gambar('a.jpg'),
        ])->assertOk();

        $pathLama = (string) DB::table('driver_documents')
            ->where('driver_id', $driver->id)
            ->value('file_path');

        Storage::disk('kyc')->assertExists($pathLama);

        $this->postJson('/api/v1/driver/documents', [
            'type' => 'ktp',
            'file' => $this->gambar('b.jpg'),
        ])->assertOk();

        Storage::disk('kyc')->assertMissing($pathLama);

        $this->assertCount(
            1,
            Storage::disk('kyc')->allFiles(),
            'Berkas lama tertinggal di disk. Setiap unggahan ulang menambah '
            .'satu foto KTP tanpa pemilik.',
        );
    }

    /**
     * Nomor dan tanggal yang TIDAK dikirim tidak menghapus yang sudah ada.
     *
     * Driver yang mengunggah ulang fotonya saja tidak perlu mengisi ulang
     * nomornya. Menimpanya dengan null akan menghapus data yang sudah benar, dan
     * verifikator harus menanyakannya lagi.
     */
    public function test_nomor_lama_tidak_terhapus_saat_hanya_foto_diganti(): void
    {
        $driver = $this->driver();

        Sanctum::actingAs($driver->user);

        $this->postJson('/api/v1/driver/documents', [
            'type' => 'sim',
            'file' => $this->gambar('sim.jpg'),
            'number' => '1234567890',
            'expires_at' => now()->addYear()->toDateString(),
        ])->assertOk();

        $this->postJson('/api/v1/driver/documents', [
            'type' => 'sim',
            'file' => $this->gambar('sim-jelas.jpg'),
        ])->assertOk();

        $dokumen = DriverDocument::query()
            ->where('driver_id', $driver->id)
            ->firstOrFail();

        $this->assertSame(
            '1234567890',
            $dokumen->number,
            'Nomor dokumen terhapus karena tidak dikirim ulang. Verifikator '
            .'harus menanyakannya lagi.',
        );

        $this->assertNotNull($dokumen->expires_at);
    }

    // =========================================================================
    //  Daftar
    // =========================================================================

    /**
     * Daftar memuat yang KURANG, bukan hanya yang sudah ada.
     *
     * Driver baru punya daftar kosong, dan layar yang menampilkan daftar kosong
     * tidak memberi tahu dia harus mengunggah apa.
     */
    public function test_daftar_menyebut_dokumen_yang_masih_kurang(): void
    {
        $driver = $this->driver();

        Sanctum::actingAs($driver->user);

        $response = $this->getJson('/api/v1/driver/documents');

        $response->assertOk();

        $wajib = (array) config('antaride.kyc.required_documents');

        $this->assertSame($wajib, $response->json('data.missing'));
        $this->assertFalse($response->json('data.can_go_online'));
        $this->assertSame([], $response->json('data.documents'));
    }

    /**
     * Dokumen `pending` TETAP terhitung kurang.
     *
     * Dia memang belum bisa dipakai bekerja, dan driver yang melihatnya hilang
     * dari daftar kurang akan menyimpulkan dia sudah bisa online.
     */
    public function test_dokumen_pending_masih_terhitung_kurang(): void
    {
        $driver = $this->driver();

        Sanctum::actingAs($driver->user);

        $this->postJson('/api/v1/driver/documents', [
            'type' => 'ktp',
            'file' => $this->gambar('ktp.jpg'),
        ])->assertOk();

        $response = $this->getJson('/api/v1/driver/documents');

        $this->assertContains(
            'ktp',
            $response->json('data.missing'),
            'Dokumen yang masih menunggu verifikasi dianggap sudah beres. '
            .'Driver akan mengira dia sudah bisa online.',
        );
    }

    /**
     * ========================================================================
     *  `file_path` TIDAK PERNAH KELUAR KE APLIKASI
     * ========================================================================
     *  Path mentah tidak berguna bagi aplikasi — disknya privat. Tapi berguna
     *  bagi orang yang sedang mencari cara menebak path dokumen driver lain.
     * ========================================================================
     */
    public function test_path_berkas_tidak_pernah_dikirim_ke_aplikasi(): void
    {
        $driver = $this->driver();

        Sanctum::actingAs($driver->user);

        $this->postJson('/api/v1/driver/documents', [
            'type' => 'ktp',
            'file' => $this->gambar('ktp.jpg'),
        ])->assertOk();

        $path = (string) DB::table('driver_documents')
            ->where('driver_id', $driver->id)
            ->value('file_path');

        foreach (['/api/v1/driver/documents'] as $url) {
            $isi = $this->getJson($url)->content();

            $this->assertStringNotContainsString(
                $path,
                $isi,
                "Path berkas ikut terkirim di $url.",
            );

            $this->assertStringNotContainsString('file_path', $isi);
        }
    }

    /**
     * Driver hanya melihat dokumennya SENDIRI.
     *
     * Kalau tidak: satu driver bisa membaca KTP seluruh driver lain, dan tidak
     * ada di response yang memperlihatkan itu terjadi.
     */
    public function test_driver_hanya_melihat_dokumennya_sendiri(): void
    {
        $a = $this->driver();
        $b = $this->driver();

        Sanctum::actingAs($a->user);

        $this->postJson('/api/v1/driver/documents', [
            'type' => 'ktp',
            'file' => $this->gambar('ktp-a.jpg'),
        ])->assertOk();

        Sanctum::actingAs($b->user);

        $response = $this->getJson('/api/v1/driver/documents');

        $response->assertOk();

        $this->assertSame(
            [],
            $response->json('data.documents'),
            'Driver B melihat dokumen driver A.',
        );
    }

    /**
     * ========================================================================
     *  `can_go_online` HARUS SEPAKAT DENGAN KEPUTUSAN `GoOnline`
     * ========================================================================
     *  Dua tempat menjawab pertanyaan yang sama: endpoint ini, dan `GoOnline`.
     *  Kalau keduanya tidak sepakat, yang terjadi bukan galat — yang terjadi
     *  layar yang menyatakan "Anda sudah bisa mulai bekerja" lalu tombol online
     *  yang ditolak.
     *
     *  Driver tidak punya satu pun petunjuk tentang apa yang salah, karena
     *  layarnya sendiri menyatakan dia siap.
     *
     *  Ini bug yang sudah pernah hidup di endpoint ini: `can_go_online` dihitung
     *  dari status `approved` saja, mengabaikan `expires_at`.
     * ========================================================================
     */
    public function test_dokumen_kadaluarsa_membuat_belum_bisa_online(): void
    {
        $driver = $this->driver();

        Sanctum::actingAs($driver->user);

        /** @var list<string> $wajib */
        $wajib = (array) config('antaride.kyc.required_documents');

        // Semua dokumen wajib disetujui, tapi SIM-nya sudah kadaluarsa.
        foreach ($wajib as $jenis) {
            $this->setujuiDokumen(
                $driver,
                $jenis,
                kadaluarsa: $jenis === 'sim',
            );
        }

        $response = $this->getJson('/api/v1/driver/documents');

        $response->assertOk();

        $this->assertFalse(
            $response->json('data.can_go_online'),
            'Endpoint menyatakan driver siap bekerja padahal SIM-nya '
            .'kadaluarsa. `GoOnline` akan menolaknya, dan layar driver '
            .'menyatakan dia siap — tidak ada petunjuk apa pun tentang '
            .'penyebabnya.',
        );

        $this->assertContains(
            'sim',
            (array) $response->json('data.expired'),
            'Jenis yang kadaluarsa tidak disebut. Driver tidak tahu dokumen '
            .'mana yang perlu diperpanjang.',
        );

        $this->assertContains(
            'sim',
            (array) $response->json('data.missing'),
            'Dokumen kadaluarsa tidak terhitung kurang. Kartunya akan tampil '
            .'sebagai sudah beres.',
        );
    }

    /**
     * Dokumen kadaluarsa yang TIDAK wajib juga menghalangi.
     *
     * `GoOnline` menolak setiap dokumen `approved` yang tanggalnya lewat, tanpa
     * memeriksa apakah jenisnya wajib — dan itu memang yang benar: SKCK
     * kadaluarsa yang tersimpan sebagai disetujui adalah dokumen tidak sah di
     * berkas kita.
     *
     * Kalau endpoint ini mengabaikannya, driver dengan SKCK kadaluarsa melihat
     * "dokumen lengkap" lalu ditolak online.
     */
    public function test_dokumen_kadaluarsa_yang_tidak_wajib_juga_menghalangi(): void
    {
        $driver = $this->driver();

        Sanctum::actingAs($driver->user);

        /** @var list<string> $wajib */
        $wajib = (array) config('antaride.kyc.required_documents');

        foreach ($wajib as $jenis) {
            $this->setujuiDokumen($driver, $jenis);
        }

        // SKCK bukan dokumen wajib, tapi sudah kadaluarsa.
        $this->setujuiDokumen($driver, 'skck', kadaluarsa: true);

        $response = $this->getJson('/api/v1/driver/documents');

        $this->assertFalse(
            $response->json('data.can_go_online'),
            'SKCK kadaluarsa diabaikan karena bukan dokumen wajib. `GoOnline` '
            .'tidak membedakannya, jadi driver akan ditolak tanpa penjelasan.',
        );

        $this->assertContains('skck', (array) $response->json('data.expired'));
    }

    /**
     * Dan sebaliknya: dokumen lengkap yang masih berlaku BOLEH online.
     *
     * Tanpa test ini, penjagaan di atas bisa dipenuhi dengan mengembalikan false
     * selamanya — dan tidak ada satu pun driver yang bisa bekerja.
     */
    public function test_dokumen_lengkap_dan_berlaku_bisa_online(): void
    {
        $driver = $this->driver();

        Sanctum::actingAs($driver->user);

        /** @var list<string> $wajib */
        $wajib = (array) config('antaride.kyc.required_documents');

        foreach ($wajib as $jenis) {
            $this->setujuiDokumen($driver, $jenis);
        }

        $response = $this->getJson('/api/v1/driver/documents');

        $this->assertTrue(
            $response->json('data.can_go_online'),
            'Driver dengan dokumen lengkap dan berlaku dinyatakan belum siap. '
            .'Tidak ada driver yang bisa mulai bekerja.',
        );

        $this->assertSame([], $response->json('data.missing'));
        $this->assertSame([], $response->json('data.expired'));
    }

    // =========================================================================
    //  Kesepakatan dengan database
    // =========================================================================

    /**
     * Daftar jenis di validator SAMA dengan CHECK constraint di database.
     *
     * ========================================================================
     *  DUA DAFTAR YANG HARUS SEPAKAT AKAN MENYIMPANG
     * ========================================================================
     *  Kalau validator lebih longgar: jenis yang lolos validasi ditolak Postgres
     *  sebagai galat 500 — dan yang dilihat driver adalah aplikasi yang rusak.
     *
     *  Kalau validator lebih ketat: jenis yang sah ditolak tanpa alasan yang bisa
     *  dijelaskan kepada driver yang memegang dokumennya.
     * ========================================================================
     */
    public function test_daftar_jenis_dokumen_sama_dengan_constraint_database(): void
    {
        $definisi = (string) DB::selectOne("
            SELECT pg_get_constraintdef(oid) AS def
            FROM pg_constraint
            WHERE conname = 'driver_documents_type_check'
        ")->def;

        foreach (UploadDocumentRequest::JENIS_DOKUMEN as $jenis) {
            $this->assertStringContainsString(
                "'".$jenis."'",
                $definisi,
                "Jenis `$jenis` diterima validator tapi ditolak database. "
                .'Unggahannya akan gagal dengan galat 500.',
            );
        }

        // Dan arah sebaliknya: setiap jenis di database ada di validator.
        preg_match_all("/'([a-z_]+)'/", $definisi, $cocok);

        foreach ($cocok[1] as $jenis) {
            $this->assertContains(
                $jenis,
                UploadDocumentRequest::JENIS_DOKUMEN,
                "Jenis `$jenis` sah menurut database tapi ditolak validator. "
                .'Driver yang memegang dokumen itu tidak bisa mengunggahnya.',
            );
        }
    }

    // =========================================================================

    private function driver(): Driver
    {
        return Driver::factory()->create();
    }

    /**
     * Sisipkan satu dokumen yang sudah disetujui.
     *
     * `expires_at` hanya diisi untuk jenis yang memang punya masa berlaku —
     * mengisinya untuk KTP akan membuat test bergantung pada perilaku yang tidak
     * pernah terjadi di produksi.
     */
    private function setujuiDokumen(
        Driver $driver,
        string $type,
        bool $kadaluarsa = false,
    ): void {
        /** @var list<string> $berlakuTerbatas */
        $berlakuTerbatas = (array) config('antaride.kyc.expiring_documents');

        $punyaMasaBerlaku = in_array($type, $berlakuTerbatas, true);

        DB::table('driver_documents')->insert([
            'uuid' => (string) Str::uuid7(),
            'driver_id' => $driver->id,
            'type' => $type,
            'file_path' => 'driver/'.$driver->id.'/'.$type.'.jpg',
            'status' => 'approved',
            'expires_at' => $punyaMasaBerlaku
                ? ($kadaluarsa
                    ? now()->subMonth()->toDateString()
                    : now()->addYear()->toDateString())
                : null,
            'reviewed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Gambar palsu yang cukup besar untuk lolos aturan dimensi.
     */
    private function gambar(string $nama): UploadedFile
    {
        return UploadedFile::fake()->image($nama, 1000, 700);
    }
}
