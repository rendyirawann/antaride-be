<?php

declare(strict_types=1);

namespace Tests\Feature\Realtime;

use App\Domain\Shared\Contracts\RealtimePublisher;
use App\Domain\Shared\ValueObjects\RealtimeChannel;
use App\Infrastructure\Realtime\CentrifugoPublisher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class CentrifugoPublisherTest extends TestCase
{
    private CentrifugoPublisher $publisher;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.centrifugo.url' => 'http://127.0.0.1:8100',
            'services.centrifugo.api_key' => 'kunci-api-uji',
            'services.centrifugo.token_hmac_secret' => 'rahasia-hmac-uji-yang-cukup-panjang',
        ]);

        $this->publisher = new CentrifugoPublisher;
    }

    // -------------------------------------------------------------------------
    // Publish
    // -------------------------------------------------------------------------

    public function test_mengirim_ke_endpoint_publish_dengan_api_key(): void
    {
        Http::fake(['*' => Http::response(['result' => []], 200)]);

        $sent = $this->publisher->publish('$order:abc-123', ['status' => 'accepted']);

        $this->assertTrue($sent);

        Http::assertSent(function ($request) {
            $this->assertStringEndsWith('/api/publish', $request->url());
            $this->assertSame('kunci-api-uji', $request->header('X-API-Key')[0] ?? null);
            $this->assertSame('$order:abc-123', $request['channel']);
            $this->assertSame(['status' => 'accepted'], $request['data']);

            return true;
        });
    }

    public function test_broadcast_mengirim_beberapa_channel_dalam_satu_panggilan(): void
    {
        Http::fake(['*' => Http::response(['result' => []], 200)]);

        $sent = $this->publisher->broadcast(
            ['$order:abc', '$driver:5', '$admin:live'],
            ['event' => 'order.accepted'],
        );

        $this->assertTrue($sent);
        Http::assertSentCount(1);

        Http::assertSent(function ($request) {
            $this->assertStringEndsWith('/api/broadcast', $request->url());
            $this->assertSame(['$order:abc', '$driver:5', '$admin:live'], $request['channels']);

            return true;
        });
    }

    public function test_broadcast_tanpa_channel_tidak_mengirim_apa_pun(): void
    {
        Http::fake();

        $this->assertTrue($this->publisher->broadcast([], ['a' => 1]));
        Http::assertNothingSent();
    }

    /**
     * Gateway realtime yang mati TIDAK boleh menjatuhkan apa pun.
     *
     * Perubahan status order sudah ter-commit di database saat pesan realtime
     * dikirim. Kalau kegagalan pengiriman melempar, transaksi yang sudah selesai
     * akan terlihat gagal, dan yang paling mungkin dilakukan pemanggil adalah
     * mengulang seluruh operasi.
     */
    public function test_gateway_mati_mengembalikan_false_bukan_melempar(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $this->assertFalse($this->publisher->publish('$order:abc', ['a' => 1]));
    }

    public function test_koneksi_gagal_mengembalikan_false_bukan_melempar(): void
    {
        Http::fake(fn () => throw new ConnectionException('tidak terhubung'));

        $this->assertFalse($this->publisher->publish('$order:abc', ['a' => 1]));
    }

    /**
     * Centrifugo membalas 200 bahkan untuk kesalahan aplikasi, dengan detailnya
     * di kunci "error".
     *
     * Memeriksa status HTTP saja tidak cukup. Tanpa pemeriksaan kunci error,
     * channel yang salah nama akan tampak berhasil terkirim, dan pesan itu
     * hilang tanpa jejak.
     */
    public function test_error_di_dalam_balasan_dua_ratus_terdeteksi(): void
    {
        Http::fake(['*' => Http::response([
            'error' => ['code' => 102, 'message' => 'unknown channel'],
        ], 200)]);

        $this->assertFalse(
            $this->publisher->publish('$order:abc', ['a' => 1]),
            'Error di dalam balasan 200 tidak terdeteksi; pesan hilang tanpa jejak.',
        );
    }

    public function test_konfigurasi_kosong_tidak_mengirim_dan_mengembalikan_false(): void
    {
        config(['services.centrifugo.api_key' => '']);
        Http::fake();

        $this->assertFalse((new CentrifugoPublisher)->publish('$order:abc', ['a' => 1]));
        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // Token
    // -------------------------------------------------------------------------

    public function test_token_koneksi_berbentuk_jwt_hs256_yang_sah(): void
    {
        $token = $this->publisher->connectionToken('user:42');

        [$header, $payload, $signature] = explode('.', $token);

        $decodedHeader = json_decode($this->base64UrlDecode($header), true);
        $decodedPayload = json_decode($this->base64UrlDecode($payload), true);

        $this->assertSame(['typ' => 'JWT', 'alg' => 'HS256'], $decodedHeader);
        $this->assertSame('user:42', $decodedPayload['sub']);
        $this->assertGreaterThan(now()->getTimestamp(), $decodedPayload['exp']);

        // Token koneksi TIDAK boleh membawa klaim channel. Kalau membawa,
        // dia akan diperlakukan Centrifugo sebagai token langganan.
        $this->assertArrayNotHasKey('channel', $decodedPayload);
    }

    /**
     * Tanda tangan harus benar-benar bisa diverifikasi dengan secret yang sama.
     *
     * Ini membuktikan JWT-nya sah, bukan hanya berbentuk tiga bagian dipisah
     * titik.
     */
    public function test_tanda_tangan_token_dapat_diverifikasi(): void
    {
        $secret = 'rahasia-hmac-uji-yang-cukup-panjang';
        $token = $this->publisher->connectionToken('user:42');

        [$header, $payload, $signature] = explode('.', $token);

        $expected = rtrim(strtr(base64_encode(
            hash_hmac('sha256', "{$header}.{$payload}", $secret, true)
        ), '+/', '-_'), '=');

        $this->assertSame($expected, $signature);
    }

    /**
     * Token langganan WAJIB membawa klaim channel.
     *
     * Inilah yang menegakkan otorisasi. Tanpa klaim channel, siapa pun yang
     * punya token bisa mendengarkan posisi driver dan isi percakapan order
     * orang lain, cukup dengan menebak format nama channel.
     */
    public function test_token_langganan_membawa_klaim_channel(): void
    {
        $token = $this->publisher->subscriptionToken('user:42', '$order:abc-123');

        [, $payload] = explode('.', $token);
        $decoded = json_decode($this->base64UrlDecode($payload), true);

        $this->assertSame('user:42', $decoded['sub']);
        $this->assertSame('$order:abc-123', $decoded['channel']);
    }

    public function test_token_menghormati_ttl_yang_diminta(): void
    {
        $token = $this->publisher->connectionToken('user:42', ttlSeconds: 60);

        [, $payload] = explode('.', $token);
        $decoded = json_decode($this->base64UrlDecode($payload), true);

        $this->assertLessThanOrEqual(now()->addSeconds(61)->getTimestamp(), $decoded['exp']);
        $this->assertGreaterThan(now()->addSeconds(50)->getTimestamp(), $decoded['exp']);
    }

    /**
     * Secret kosong harus MELEMPAR, bukan menghasilkan token kosong.
     *
     * Token kosong akan ditolak Centrifugo dengan pesan yang tidak menjelaskan
     * apa pun, dan yang terlihat di aplikasi hanya "gagal terhubung" tanpa
     * petunjuk bahwa penyebabnya satu variabel env yang belum diisi.
     */
    public function test_secret_kosong_melempar_exception_yang_jelas(): void
    {
        config(['services.centrifugo.token_hmac_secret' => '']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/CENTRIFUGO_TOKEN_HMAC_SECRET/');

        (new CentrifugoPublisher)->connectionToken('user:42');
    }

    /**
     * Base64 URL-safe tanpa padding, sesuai RFC 7515.
     */
    public function test_token_tidak_memuat_karakter_yang_tidak_aman_untuk_url(): void
    {
        // Subject panjang supaya kemungkinan muncul karakter + / = lebih besar.
        $token = $this->publisher->connectionToken(str_repeat('user:9876543210', 8));

        $this->assertStringNotContainsString('+', $token);
        $this->assertStringNotContainsString('/', $token);
        $this->assertStringNotContainsString('=', $token);
    }

    // -------------------------------------------------------------------------
    // Nama channel
    // -------------------------------------------------------------------------

    /**
     * Semua channel harus privat (berawalan dolar).
     *
     * Tanpa awalan itu, Centrifugo mengizinkan siapa pun berlangganan tanpa
     * token, dan seluruh lapisan otorisasi channel jadi tidak berarti.
     */
    public function test_semua_channel_bersifat_privat(): void
    {
        $channels = [
            RealtimeChannel::order('abc-123'),
            RealtimeChannel::driver(5),
            RealtimeChannel::user(42),
            RealtimeChannel::merchant(7),
            RealtimeChannel::adminLive(),
            RealtimeChannel::adminGeo('qqguv'),
        ];

        foreach ($channels as $channel) {
            $this->assertStringStartsWith(
                '$',
                (string) $channel,
                "Channel {$channel} tidak privat; siapa pun bisa berlangganan tanpa token.",
            );
        }
    }

    /**
     * Channel order memakai UUID, bukan id auto-increment.
     *
     * Dengan id, siapa pun yang punya token untuk satu order bisa menebak nama
     * channel order lain dengan menambah satu.
     */
    public function test_channel_order_memakai_uuid(): void
    {
        $uuid = '0199c1f0-1234-7abc-8def-0123456789ab';

        $this->assertSame(
            "\$order:{$uuid}",
            (string) RealtimeChannel::order($uuid),
        );
    }

    public function test_container_menyerahkan_implementasi_centrifugo(): void
    {
        $this->assertInstanceOf(
            CentrifugoPublisher::class,
            $this->app->make(RealtimePublisher::class),
        );
    }

    // -------------------------------------------------------------------------

    private function base64UrlDecode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4));
    }
}
