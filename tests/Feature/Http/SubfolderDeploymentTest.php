<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * ============================================================================
 *  APLIKASI HARUS JALAN DI DUA TEMPAT SEKALIGUS
 * ============================================================================
 *  Lokal    `http://127.0.0.1:8000/` — di akar, tanpa proxy.
 *  Server   `https://domain.com/antaride/` — di SUBFOLDER, di belakang Nginx.
 *
 *  Yang membuat keduanya sulit hidup bersama: Octane mendengarkan di
 *  `127.0.0.1:8000` tanpa tahu apa pun soal subfolder. Nginx-lah yang
 *  memotongnya sebelum meneruskan — jadi Laravel melihat `/admin/login`, bukan
 *  `/antaride/admin/login`.
 *
 *  Akibatnya kalau tidak ditangani: SETIAP `route()`, `url()`, dan `asset()`
 *  menghasilkan tautan tanpa subfolder. Halaman pertamanya terbuka — pengguna
 *  mengetik URL-nya sendiri — lalu setiap link di dalamnya 404. Termasuk form
 *  login, yang action-nya menunjuk ke luar subfolder.
 *
 *  Yang menyelesaikannya `X-Forwarded-Prefix`, dan itu HARUS didaftarkan
 *  eksplisit: Laravel tidak memasukkannya ke daftar header proxy bawaannya.
 * ============================================================================
 *
 * ============================================================================
 *  KENAPA DIUJI, DAN BUKAN CUKUP "SUDAH DISETEL DI bootstrap/app.php"
 * ============================================================================
 *  Kegagalannya tidak terlihat di lokal SAMA SEKALI. Di lokal tidak ada proxy,
 *  tidak ada subfolder, dan seluruh test lain lulus. Yang pertama menemukannya
 *  adalah orang yang membuka panel admin di server — dan yang dia lihat 404,
 *  bukan pesan yang menyebut header apa pun.
 *
 *  Satu baris `headers:` yang terhapus saat merge sudah cukup untuk
 *  mengembalikannya, dan tidak ada satu pun test lain yang akan berubah warna.
 * ============================================================================
 */
class SubfolderDeploymentTest extends TestCase
{
    private const PREFIX = '/antaride';

    private const PROXY = '127.0.0.1';

    // =========================================================================
    //  Subfolder
    // =========================================================================

    /**
     * ========================================================================
     *  INI TEST YANG PALING PENTING DI BERKAS INI
     * ========================================================================
     *  `X-Forwarded-Prefix` dari proxy tepercaya harus masuk ke akar URL yang
     *  dihasilkan aplikasi.
     * ========================================================================
     */
    public function test_prefix_dari_proxy_masuk_ke_url_yang_dihasilkan(): void
    {
        $this->requestLewatProxy(['X-Forwarded-Prefix' => self::PREFIX]);

        $this->assertSame(
            'http://localhost'.self::PREFIX,
            url('/'),
            'Subfolder hilang dari URL yang dihasilkan. Setiap link, redirect, '
            .'dan asset() akan menunjuk ke luar subfolder — dan yang ditemukan '
            .'pengguna adalah 404 dari web server, bukan galat dari aplikasi.',
        );
    }

    public function test_route_admin_membawa_subfolder(): void
    {
        $this->requestLewatProxy(['X-Forwarded-Prefix' => self::PREFIX]);

        $login = route('admin.login');

        $this->assertStringContainsString(
            self::PREFIX.'/',
            $login,
            'route() kehilangan subfolder. Form login akan mengirim POST ke '
            .'luar subfolder, dan tidak ada seorang pun yang bisa masuk panel.',
        );
    }

    /**
     * `asset()` juga, dan ini yang paling terlihat.
     *
     * Aset Metronic diambil lewat `asset()`. Tanpa subfolder, seluruh CSS dan JS
     * gagal dimuat — panelnya terbuka sebagai HTML tanpa gaya sama sekali, dan
     * gejalanya terbaca sebagai "temanya rusak", bukan sebagai masalah URL.
     */
    public function test_asset_membawa_subfolder(): void
    {
        $this->requestLewatProxy(['X-Forwarded-Prefix' => self::PREFIX]);

        $this->assertStringStartsWith(
            'http://localhost'.self::PREFIX.'/',
            asset('assets/css/style.bundle.css'),
            'asset() kehilangan subfolder. Seluruh CSS dan JS Metronic gagal '
            .'dimuat, dan panelnya tampil tanpa gaya.',
        );
    }

    /**
     * Prefix yang berakhiran garis miring TIDAK menghasilkan garis miring ganda.
     *
     * Nginx bisa dikonfigurasi mengirim `/antaride/` maupun `/antaride`.
     * Keduanya harus menghasilkan URL yang sama — `//` di tengah path
     * menghasilkan 404 di sebagian konfigurasi web server, dan yang lain
     * mengarahkannya ulang sehingga POST berubah jadi GET.
     */
    public function test_prefix_dengan_garis_miring_tidak_menghasilkan_slash_ganda(): void
    {
        $this->requestLewatProxy(['X-Forwarded-Prefix' => self::PREFIX.'/']);

        $this->assertStringNotContainsString(
            self::PREFIX.'//',
            url('/foo'),
            'URL memuat garis miring ganda.',
        );
    }

    /**
     * Tanpa header prefix, aplikasi tetap jalan di AKAR.
     *
     * Ini yang menjaga pengembangan lokal: di sana tidak ada proxy dan tidak ada
     * subfolder. Konfigurasi yang menuntut header itu ada akan membuat aplikasi
     * hanya bisa dijalankan di server.
     */
    public function test_tanpa_prefix_aplikasi_tetap_di_akar(): void
    {
        // Request lewat proxy, tapi TANPA header prefix — persis seperti
        // konfigurasi Nginx yang melayani aplikasi di akar domain.
        $this->requestLewatProxy([]);

        $this->assertSame(
            'http://localhost',
            url('/'),
            'Ada subfolder yang muncul padahal tidak ada header prefix. '
            .'Aplikasi tidak akan bisa dijalankan di akar domain.',
        );

        $this->assertStringNotContainsString('antaride', route('admin.login'));
    }

    // =========================================================================
    //  Header proxy yang lain
    // =========================================================================

    /**
     * HTTPS dari `X-Forwarded-Proto`.
     *
     * Nginx yang memegang TLS; ke Octane dia bicara HTTP biasa. Tanpa header ini
     * dipercaya, `url()` menghasilkan `http://` di server yang HTTPS — dan
     * browser memblokir sebagian isinya sebagai mixed content.
     */
    public function test_https_terbaca_dari_header_proxy(): void
    {
        $this->requestLewatProxy(['X-Forwarded-Proto' => 'https']);

        $this->assertStringStartsWith(
            'https://',
            url('/'),
            'Skema HTTPS tidak terbaca. Aplikasi akan menghasilkan tautan '
            .'http:// di server yang HTTPS.',
        );
    }

    /**
     * ========================================================================
     *  IP ASLI PENGGUNA, BUKAN IP NGINX
     * ========================================================================
     *  Rate limit OTP, allowlist IP admin, dan `admin_login_attempts` semuanya
     *  membaca `$request->ip()`.
     *
     *  Tanpa `X-Forwarded-For`, semuanya melihat IP Nginx — 127.0.0.1 untuk
     *  SEMUA pengguna. Akibatnya rate limit OTP menjadi rate limit GLOBAL: satu
     *  orang yang meminta OTP berulang memblokir seluruh pengguna, dan tidak ada
     *  apa pun di log yang memperlihatkan penyebabnya.
     * ========================================================================
     */
    public function test_ip_asli_pengguna_terbaca_dari_header_proxy(): void
    {
        $request = $this->requestLewatProxy([
            'X-Forwarded-For' => '203.0.113.45',
        ]);

        $this->assertSame(
            '203.0.113.45',
            $request->ip(),
            'IP pengguna terbaca sebagai IP Nginx. Rate limit OTP menjadi rate '
            .'limit global — satu orang memblokir seluruh pengguna.',
        );
    }

    // =========================================================================
    //  Keamanan
    // =========================================================================

    /**
     * ========================================================================
     *  HEADER DARI IP YANG BUKAN PROXY TEPERCAYA HARUS DIABAIKAN
     * ========================================================================
     *  Seluruh header di atas BISA DIPALSUKAN client. Kalau aplikasi
     *  mempercayainya dari sumber mana pun, penyerang bisa:
     *
     *    * mengirim `X-Forwarded-For` palsu untuk melewati rate limit OTP dan
     *      allowlist IP admin,
     *    * mengirim `X-Forwarded-Host` palsu sehingga tautan reset password yang
     *      dikirim ke email KORBAN menunjuk ke domain penyerang.
     *
     *  Yang kedua adalah pengambilalihan akun, dan tidak menuntut apa pun selain
     *  satu header HTTP.
     *
     *  Test ini yang menjaga `TRUSTED_PROXIES` tidak diubah menjadi `*` dengan
     *  alasan "supaya jalan di belakang Cloudflare".
     * ========================================================================
     */
    public function test_header_dari_ip_tak_tepercaya_diabaikan(): void
    {
        $request = Request::create('http://localhost/apa-saja', 'GET');

        // IP publik sembarang — bukan proxy kita.
        $request->server->set('REMOTE_ADDR', '198.51.100.7');

        $request->headers->set('X-Forwarded-Prefix', '/dicuri');
        $request->headers->set('X-Forwarded-For', '203.0.113.45');
        $request->headers->set('X-Forwarded-Host', 'penyerang.example');

        $this->pakaiRequest($request);

        $this->assertStringNotContainsString(
            'dicuri',
            url('/'),
            'Prefix dari IP tak tepercaya diterima. Siapa pun bisa mengubah '
            .'seluruh URL yang dihasilkan aplikasi lewat satu header.',
        );

        $this->assertStringNotContainsString(
            'penyerang.example',
            url('/'),
            'Host dari IP tak tepercaya diterima. Tautan reset password yang '
            .'dikirim ke email korban akan menunjuk ke domain penyerang.',
        );

        $this->assertSame(
            '198.51.100.7',
            $request->ip(),
            'IP dari header tak tepercaya dipakai. Rate limit dan allowlist IP '
            .'admin bisa dilewati dengan satu header.',
        );
    }

    /**
     * `X-Forwarded-Prefix` ada di daftar header yang dipercaya.
     *
     * ========================================================================
     *  DIPERIKSA LANGSUNG, KARENA INILAH YANG PALING MUDAH TERHAPUS
     * ========================================================================
     *  Laravel TIDAK memasukkannya ke bawaan `trustProxies`. Jadi baris itu
     *  adalah tambahan manual — dan tambahan manual yang tidak diuji akan hilang
     *  pada merge berikutnya, atau saat ada yang menyalin ulang
     *  `bootstrap/app.php` dari proyek Laravel baru.
     *
     *  Test di atas sudah menangkapnya secara tidak langsung. Yang ini
     *  menyatakannya langsung, supaya pesan kegagalannya menyebut penyebabnya
     *  alih-alih gejalanya.
     * ========================================================================
     */
    public function test_header_prefix_terdaftar_sebagai_tepercaya(): void
    {
        $this->requestLewatProxy(['X-Forwarded-Prefix' => self::PREFIX]);

        $this->assertTrue(
            (bool) (Request::getTrustedHeaderSet() & Request::HEADER_X_FORWARDED_PREFIX),
            'HEADER_X_FORWARDED_PREFIX tidak ada di daftar header tepercaya. '
            .'Laravel tidak memasukkannya ke bawaan — baris `headers:` di '
            .'`bootstrap/app.php` hilang atau dikembalikan ke bawaan Laravel.',
        );
    }

    // =========================================================================

    /**
     * Jalankan satu request seolah datang lewat Nginx di localhost.
     *
     * @param  array<string, string>  $headers
     */
    private function requestLewatProxy(array $headers): Request
    {
        $request = Request::create('http://localhost/apa-saja', 'GET');

        $request->server->set('REMOTE_ADDR', self::PROXY);

        foreach ($headers as $nama => $nilai) {
            $request->headers->set($nama, $nilai);
        }

        $this->pakaiRequest($request);

        return $request;
    }

    /**
     * Pasang request ini sebagai request yang sedang berjalan.
     *
     * ========================================================================
     *  `UrlGenerator` HARUS DIBERI TAHU, TIDAK CUKUP MENGGANTI REQUEST
     * ========================================================================
     *  `UrlGenerator` menyimpan referensi ke request yang dia terima saat
     *  dibuat. Mengganti `app('request')` saja tidak mengubah apa pun yang
     *  dihasilkan `url()` — dan test-nya akan LULUS untuk alasan yang salah,
     *  karena nilai yang diperiksa datang dari request lama.
     *
     *  Middleware `TrustProxies` yang dijalankan di sini juga bukan hiasan:
     *  dialah yang memanggil `Request::setTrustedProxies`, dan tanpa itu Symfony
     *  tidak akan melihat satu pun header di atas.
     * ========================================================================
     */
    private function pakaiRequest(Request $request): void
    {
        $middleware = $this->app->make(
            TrustProxies::class,
        );

        $middleware->handle($request, static fn (Request $r) => response('ok'));

        $this->app->instance('request', $request);

        URL::setRequest($request);
    }
}
