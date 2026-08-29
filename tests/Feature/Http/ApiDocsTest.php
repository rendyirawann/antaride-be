<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * ============================================================================
 *  DOKUMENTASI API: DIJAGA, DAN TIDAK PERNAH PUTIH POLOS
 * ============================================================================
 *  Dua hal yang diuji berkas ini, dan keduanya gagal dengan cara yang tidak
 *  terlihat:
 *
 *    AUTENTIKASI    Spesifikasi OpenAPI memuat SELURUH permukaan serangan dalam
 *                   satu berkas rapi: setiap endpoint, setiap nama field, setiap
 *                   aturan validasi. Kalau penjagaannya lepas, tidak ada yang
 *                   memberi tahu — halamannya terbuka dan bekerja sempurna.
 *
 *    HALAMAN PUTIH  Swagger UI menggambar seluruh halaman dari JavaScript.
 *                   Kalau asetnya tidak ada atau spesifikasinya gagal dimuat,
 *                   yang tersisa halaman KOSONG tanpa satu pun pesan — dan yang
 *                   membukanya menyimpulkan server mati.
 * ============================================================================
 */
class ApiDocsTest extends TestCase
{
    private const USER = 'itds';

    private const SANDI = 'itds123';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'antaride.docs.username' => self::USER,
            'antaride.docs.password' => self::SANDI,
        ]);
    }

    // =========================================================================
    //  Autentikasi
    // =========================================================================

    public function test_tanpa_kredensial_ditolak(): void
    {
        $response = $this->get('/api/documentation');

        $response->assertStatus(401);

        /*
         * Header `WWW-Authenticate` WAJIB ada.
         *
         * Tanpa dia browser tidak menampilkan dialog nama pengguna dan sandi —
         * yang terlihat hanya halaman 401 kosong, tanpa cara memasukkan
         * kredensial sama sekali. Halamannya jadi tidak bisa dibuka siapa pun,
         * termasuk yang punya sandinya.
         */
        $response->assertHeader('WWW-Authenticate');

        $this->assertStringContainsString(
            'Basic',
            (string) $response->headers->get('WWW-Authenticate'),
        );
    }

    public function test_sandi_salah_ditolak(): void
    {
        $this->withBasicAuth(self::USER, 'sandi-salah')
            ->get('/api/documentation')
            ->assertStatus(401);
    }

    public function test_nama_pengguna_salah_ditolak(): void
    {
        $this->withBasicAuth('orang-lain', self::SANDI)
            ->get('/api/documentation')
            ->assertStatus(401);
    }

    /**
     * ========================================================================
     *  KREDENSIAL KOSONG BERARTI DITUTUP, BUKAN TERBUKA
     * ========================================================================
     *  Ini arah gagal yang paling penting di seluruh berkas ini.
     *
     *  `.env` produksi yang lupa memuat `API_DOCS_USERNAME` TIDAK BOLEH
     *  menerbitkan seluruh permukaan API. Konfigurasi yang hilang adalah
     *  kelalaian, dan kelalaian harus berakhir tertutup.
     *
     *  404, bukan 403: halaman yang tidak dikonfigurasi sebaiknya tidak mengaku
     *  ada sama sekali.
     * ========================================================================
     */
    public function test_kredensial_kosong_menutup_dokumentasi(): void
    {
        config(['antaride.docs.username' => '', 'antaride.docs.password' => '']);

        $this->get('/api/documentation')->assertStatus(404);

        $this->withBasicAuth('', '')
            ->get('/api/documentation')
            ->assertStatus(404);
    }

    public function test_kredensial_benar_diterima(): void
    {
        $this->withBasicAuth(self::USER, self::SANDI)
            ->get('/api/documentation')
            ->assertOk();
    }

    public function test_spesifikasi_juga_dijaga(): void
    {
        // Spesifikasinya yang memuat isi sebenarnya. Menjaga halaman UI-nya saja
        // tapi membiarkan JSON-nya terbuka tidak menjaga apa pun.
        $this->get('/api/documentation/openapi.json')->assertStatus(401);
    }

    // =========================================================================
    //  Halaman tidak boleh putih polos
    // =========================================================================

    /**
     * ========================================================================
     *  INI TEST YANG PALING PENTING DI BERKAS INI
     * ========================================================================
     *  Halaman harus memuat PESAN CADANGAN yang sudah tergambar di HTML —
     *  bukan ditambahkan JavaScript.
     *
     *  Swagger UI menggambar seluruh isinya dari JavaScript. Kalau skripnya
     *  gagal dimuat, yang tersisa adalah div kosong: halaman putih tanpa satu
     *  pun petunjuk. Pesan cadangan yang ada sejak awal di HTML memastikan
     *  kegagalan apa pun meninggalkan sesuatu yang bisa dibaca.
     * ========================================================================
     */
    public function test_halaman_memuat_pesan_cadangan_supaya_tidak_putih(): void
    {
        $response = $this->withBasicAuth(self::USER, self::SANDI)
            ->get('/api/documentation');

        $response->assertOk();

        $isi = $response->getContent();

        $this->assertStringContainsString(
            'antaride-gagal',
            (string) $isi,
            'Pesan cadangan tidak ada di HTML. Kalau Swagger UI gagal memuat, '
            .'yang tampil halaman putih polos tanpa satu pun petunjuk.',
        );

        $this->assertStringContainsString('id="swagger-ui"', (string) $isi);

        // Menyebut langkah perbaikannya, bukan hanya menyatakan gagal.
        $this->assertStringContainsString('scramble:export', (string) $isi);
    }

    /**
     * Aset Swagger UI dilayani SENDIRI, bukan dari CDN.
     *
     * ========================================================================
     *  CDN ADALAH PENYEBAB HALAMAN PUTIH YANG PALING SERING
     * ========================================================================
     *  Server tanpa akses internet keluar, jaringan kantor yang memblokirnya,
     *  atau CDN-nya sendiri yang sedang mati — ketiganya menghasilkan halaman
     *  yang sama: putih.
     *
     *  Test ini menjaga dua hal sekaligus: tidak ada rujukan CDN di halaman,
     *  dan berkasnya benar-benar ADA di `public/`. Yang kedua yang menangkap
     *  kesalahan deploy — `public/vendor/` yang tidak ikut ter-upload.
     * ========================================================================
     */
    public function test_aset_swagger_dilayani_sendiri_dan_benar_benar_ada(): void
    {
        $isi = (string) $this->withBasicAuth(self::USER, self::SANDI)
            ->get('/api/documentation')
            ->getContent();

        foreach (['unpkg.com', 'cdn.jsdelivr.net', 'cdnjs.cloudflare.com'] as $cdn) {
            $this->assertStringNotContainsString(
                $cdn,
                $isi,
                "Halaman memuat aset dari $cdn. Server tanpa akses internet "
                .'keluar akan menampilkan halaman putih.',
            );
        }

        foreach ([
            'swagger-ui.css',
            'swagger-ui-bundle.js',
            'swagger-ui-standalone-preset.js',
        ] as $berkas) {
            $this->assertTrue(
                File::exists(public_path('vendor/swagger-ui/'.$berkas)),
                "Aset `public/vendor/swagger-ui/$berkas` tidak ada. Halaman "
                .'dokumentasi akan tampil putih polos di server.',
            );
        }
    }

    // =========================================================================
    //  Spesifikasi
    // =========================================================================

    public function test_spesifikasi_sah_dan_memuat_endpoint(): void
    {
        $response = $this->withBasicAuth(self::USER, self::SANDI)
            ->get('/api/documentation/openapi.json');

        $response->assertOk();

        $spec = $response->json();

        $this->assertArrayHasKey('openapi', $spec);
        $this->assertArrayHasKey('paths', $spec);

        $this->assertNotEmpty(
            $spec['paths'],
            'Spesifikasi tidak memuat satu pun endpoint. Swagger UI akan '
            .'menggambar halaman kosong — yang terbaca sebagai API tanpa isi, '
            .'bukan sebagai kegagalan.',
        );

        // Beberapa endpoint inti harus ada. Kalau daftarnya kosong sebagian,
        // yang paling mungkin adalah Scramble gagal menganalisis sebuah
        // controller dan diam-diam melewatinya.
        /*
         * Path di spesifikasi dimulai `/v1/...`, BUKAN `/api/v1/...`.
         *
         * Scramble memakai `api_path => 'api'`, jadi prefix `api` dipindahkan
         * ke `servers`. URL efektifnya = servers[0].url + path.
         */
        foreach (['/v1/quotes', '/v1/orders', '/v1/driver/status'] as $jalur) {
            $this->assertArrayHasKey(
                $jalur,
                $spec['paths'],
                "Endpoint `$jalur` tidak ada di spesifikasi.",
            );
        }
    }

    /**
     * ========================================================================
     *  servers + path HARUS MENYUSUN URL YANG BENAR-BENAR ADA
     * ========================================================================
     *  Ini yang menangkap kesalahan yang mudah sekali terjadi: menuliskan
     *  `servers` sebagai `/api/v1` padahal path di spesifikasi sudah memuat
     *  `/v1`. Hasilnya `/api/v1/v1/quotes` — dan tombol "Try it out" menjawab
     *  404 tanpa menyebut penyebabnya sama sekali.
     *
     *  Yang diperiksa: URL gabungannya cocok dengan route yang benar-benar
     *  terdaftar di aplikasi.
     * ========================================================================
     */
    public function test_gabungan_servers_dan_path_menunjuk_route_yang_ada(): void
    {
        $spec = $this->withBasicAuth(self::USER, self::SANDI)
            ->get('/api/documentation/openapi.json')
            ->json();

        $base = (string) $spec['servers'][0]['url'];

        // Jalur relatif terhadap akar aplikasi, tanpa skema dan host.
        $prefix = trim((string) parse_url($base, PHP_URL_PATH), '/');

        $terdaftar = collect(Route::getRoutes())
            ->map(static fn ($r): string => '/'.trim($r->uri(), '/'))
            ->all();

        foreach (['/v1/quotes', '/v1/orders'] as $jalur) {
            $gabungan = '/'.$prefix.$jalur;

            $this->assertContains(
                $gabungan,
                $terdaftar,
                "URL gabungan `$gabungan` tidak cocok dengan satu pun route. "
                .'Tombol "Try it out" akan menembak alamat yang tidak ada.',
            );
        }
    }

    /**
     * ========================================================================
     *  `servers` DITULIS ULANG DARI REQUEST, BUKAN DARI BERKAS
     * ========================================================================
     *  Berkas spesifikasi dibuat di komputer pengembang, jadi `servers` di
     *  dalamnya memuat `127.0.0.1:8000`.
     *
     *  Kalau dibiarkan, tombol "Try it out" di server produksi mengirim request
     *  ke localhost MILIK PEMBACA — yang gagal dengan galat jaringan yang tidak
     *  menyebut server sama sekali. Orang yang mencobanya menyimpulkan API-nya
     *  rusak.
     * ========================================================================
     */
    public function test_servers_mengikuti_alamat_yang_sedang_dipakai(): void
    {
        $spec = $this->withBasicAuth(self::USER, self::SANDI)
            ->get('/api/documentation/openapi.json')
            ->json();

        $this->assertNotEmpty($spec['servers'] ?? []);

        $this->assertSame(
            url('/api'),
            $spec['servers'][0]['url'],
            'Alamat server di spesifikasi tidak mengikuti request. Tombol '
            .'"Try it out" akan menembak alamat yang salah.',
        );
    }

    /**
     * Dokumentasi tidak boleh masuk hasil pencarian.
     *
     * Spesifikasi API yang terindeks mesin pencari bisa ditemukan tanpa
     * seorang pun menebak alamatnya — dan penjagaan basic auth tidak menghalangi
     * crawler mencatat KEBERADAAN halamannya.
     */
    public function test_halaman_menolak_diindeks(): void
    {
        $isi = (string) $this->withBasicAuth(self::USER, self::SANDI)
            ->get('/api/documentation')
            ->getContent();

        $this->assertStringContainsString('noindex', $isi);
    }
}
