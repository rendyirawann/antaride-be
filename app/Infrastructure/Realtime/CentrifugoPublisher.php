<?php

declare(strict_types=1);

namespace App\Infrastructure\Realtime;

use App\Domain\Shared\Contracts\RealtimePublisher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Penerbit peristiwa lewat Centrifugo.
 *
 * Dua tanggung jawab yang berbeda sifatnya:
 *
 *   publish   HTTP ke API Centrifugo. Boleh gagal tanpa menjatuhkan apa pun.
 *   token     JWT HS256 yang ditandatangani di sini. Tidak boleh gagal diam.
 *
 * Perbedaan itu tercermin di penanganan errornya: publish mencatat lalu
 * mengembalikan false, sementara penerbitan token melempar. Alasannya, pesan
 * realtime yang gagal terkirim hanya membuat penumpang perlu menarik layar
 * untuk menyegarkan; token yang salah membuat dia tidak bisa terhubung sama
 * sekali, dan itu harus terlihat segera.
 *
 * JWT ditulis sendiri, bukan memakai paket. Yang dibutuhkan hanya HS256 dengan
 * tiga klaim, dan itu sekitar dua puluh baris. Menambah dependensi untuk itu
 * berarti menambah satu paket lagi yang harus dipantau keamanannya.
 */
class CentrifugoPublisher implements RealtimePublisher
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function publish(string $channel, array $payload): bool
    {
        return $this->send('publish', [
            'channel' => $channel,
            'data' => $payload,
        ]);
    }

    /**
     * @param  array<int, string>  $channels
     * @param  array<string, mixed>  $payload
     */
    public function broadcast(array $channels, array $payload): bool
    {
        if ($channels === []) {
            return true;
        }

        return $this->send('broadcast', [
            'channels' => array_values($channels),
            'data' => $payload,
        ]);
    }

    public function connectionToken(string $subject, ?int $ttlSeconds = null): string
    {
        $ttl = $ttlSeconds ?? (int) config('services.centrifugo.token_ttl', 3600);

        return $this->signJwt([
            'sub' => $subject,
            'exp' => now()->addSeconds($ttl)->getTimestamp(),
            'iat' => now()->getTimestamp(),
        ]);
    }

    public function subscriptionToken(
        string $subject,
        string $channel,
        ?int $ttlSeconds = null,
    ): string {
        $ttl = $ttlSeconds ?? (int) config('services.centrifugo.token_ttl', 3600);

        return $this->signJwt([
            'sub' => $subject,
            // Klaim channel inilah yang membuat token hanya berlaku untuk satu
            // channel. Tanpa dia, satu token bisa dipakai mendengarkan channel
            // order siapa pun.
            'channel' => $channel,
            'exp' => now()->addSeconds($ttl)->getTimestamp(),
            'iat' => now()->getTimestamp(),
        ]);
    }

    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $params
     */
    private function send(string $method, array $params): bool
    {
        $url = rtrim((string) config('services.centrifugo.url'), '/');
        $apiKey = (string) config('services.centrifugo.api_key');

        if ($url === '' || $apiKey === '') {
            Log::warning('Centrifugo belum dikonfigurasi, peristiwa realtime dilewati', [
                'method' => $method,
            ]);

            return false;
        }

        try {
            $response = Http::withHeaders([
                'X-API-Key' => $apiKey,
            ])
                ->timeout((float) config('services.centrifugo.timeout', 2))
                ->acceptJson()
                ->post("{$url}/api/{$method}", $params);

            if (! $response->successful()) {
                Log::warning('Centrifugo menolak permintaan', [
                    'method' => $method,
                    'status' => $response->status(),
                ]);

                return false;
            }

            // Centrifugo membalas 200 bahkan untuk kesalahan aplikasi, dengan
            // detailnya di kunci "error". Memeriksa status HTTP saja tidak
            // cukup: channel yang salah nama akan menghasilkan 200 dengan error
            // di dalamnya, dan tanpa pemeriksaan ini kegagalannya tidak
            // terlihat sama sekali.
            $error = $response->json('error');

            if (is_array($error)) {
                Log::warning('Centrifugo mengembalikan error', [
                    'method' => $method,
                    'error' => $error,
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            // Gateway realtime yang mati TIDAK boleh menjatuhkan order yang
            // sudah ter-commit. Yang hilang hanya pembaruan langsung di layar.
            Log::warning('Centrifugo tidak dapat dihubungi', [
                'method' => $method,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Tanda tangan JWT HS256.
     *
     * @param  array<string, mixed>  $claims
     */
    private function signJwt(array $claims): string
    {
        $secret = (string) config('services.centrifugo.token_hmac_secret');

        if ($secret === '') {
            // Melempar, tidak mengembalikan string kosong. Token kosong akan
            // ditolak Centrifugo dengan pesan yang tidak menjelaskan apa pun,
            // dan yang terlihat di sisi aplikasi hanya "gagal terhubung".
            throw new RuntimeException(
                'CENTRIFUGO_TOKEN_HMAC_SECRET belum diisi. Token realtime tidak dapat diterbitkan.'
            );
        }

        $header = $this->base64UrlEncode(json_encode(
            ['typ' => 'JWT', 'alg' => 'HS256'],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));

        $payload = $this->base64UrlEncode(json_encode(
            $claims,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));

        $signature = $this->base64UrlEncode(
            hash_hmac('sha256', "{$header}.{$payload}", $secret, binary: true),
        );

        return "{$header}.{$payload}.{$signature}";
    }

    /**
     * Base64 varian URL-safe tanpa padding, sesuai RFC 7515.
     */
    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
