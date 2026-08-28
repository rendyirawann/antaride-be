<?php

declare(strict_types=1);

namespace App\Domain\Driver\Support;

use Illuminate\Support\Facades\Config;

/**
 * Tiket bertanda tangan untuk layanan lokasi Go.
 *
 * ============================================================================
 *  KENAPA TIKET SENDIRI, BUKAN TOKEN SANCTUM
 * ============================================================================
 *  Layanan lokasi Go menerima ping GPS setiap beberapa detik dari setiap driver
 *  yang online. Untuk seribu driver dengan ping 4 detik, itu 250 permintaan per
 *  detik.
 *
 *  Memvalidasi token Sanctum di sana menuntut query ke Postgres pada SETIAP
 *  ping — 250 query per detik yang isinya hanya "apakah token ini sah". Itu
 *  justru beban yang membuat layanan ini dipisahkan dari Laravel.
 *
 *  Tiket ini menggantikannya: Laravel yang sudah memverifikasi driver saat dia
 *  menekan "Mulai bekerja", lalu menerbitkan tiket bertanda tangan HMAC. Go
 *  memverifikasi tanda tangannya dengan rahasia yang sama — tanpa database,
 *  tanpa jaringan, dalam hitungan mikrodetik.
 * ============================================================================
 *
 * ============================================================================
 *  ISINYA MINIMAL, DAN ITU BUKAN KEBETULAN
 * ============================================================================
 *  Tiket hanya memuat: id driver, kode layanan yang dia aktifkan, dan waktu
 *  kadaluarsa. Tidak ada nama, nomor HP, atau apa pun yang bersifat pribadi.
 *
 *  Alasannya: tiket ini ada di dalam aplikasi driver, dan payload-nya hanya
 *  base64 — bisa dibaca siapa pun yang membongkar lalu lintasnya. Tanda tangan
 *  mencegah PEMALSUAN, bukan PEMBACAAN.
 *
 *  Jadi yang tidak boleh dibaca orang lain, tidak boleh masuk ke sini.
 * ============================================================================
 *
 * ============================================================================
 *  KADALUARSA 12 JAM
 * ============================================================================
 *  Cukup untuk satu shift kerja penuh tanpa driver harus online ulang, dan
 *  cukup pendek untuk membatasi kerugian kalau tiketnya bocor: yang bisa
 *  dilakukan pemegangnya hanya memalsukan POSISI driver itu, dan hanya sampai
 *  tiketnya habis.
 *
 *  Memalsukan posisi memang merugikan — driver palsu bisa mengaku dekat dengan
 *  penumpang — tapi itu berhenti di situ: tiket ini tidak bisa dipakai
 *  menerima order, membaca data penumpang, atau menyentuh uang. Semua itu tetap
 *  lewat token Sanctum di Laravel.
 * ============================================================================
 */
final readonly class LocationTicket
{
    private const TTL_SECONDS = 43_200; // 12 jam

    /**
     * Terbitkan tiket untuk satu driver.
     *
     * @param  list<string>  $serviceCodes  Layanan yang dia aktifkan saat online.
     */
    public static function issue(int $driverId, array $serviceCodes): string
    {
        $payload = [
            'driver_id' => $driverId,
            'services' => array_values($serviceCodes),
            'exp' => now()->getTimestamp() + self::TTL_SECONDS,
        ];

        $encoded = self::base64UrlEncode(
            json_encode($payload, JSON_THROW_ON_ERROR),
        );

        return $encoded.'.'.self::sign($encoded);
    }

    /**
     * Verifikasi tiket. Mengembalikan payload-nya, atau null kalau tidak sah.
     *
     * Dipakai test dan — kalau nanti dibutuhkan — jalur cadangan di Laravel.
     * Yang memverifikasi di jalur normal adalah Go.
     *
     * @return array{driver_id: int, services: list<string>, exp: int}|null
     */
    public static function verify(string $ticket): ?array
    {
        $parts = explode('.', $ticket);

        if (count($parts) !== 2) {
            return null;
        }

        [$encoded, $signature] = $parts;

        /*
         * `hash_equals`, bukan `===`.
         *
         * Perbandingan string biasa berhenti di byte pertama yang berbeda, dan
         * lamanya perbandingan itu bisa diukur dari luar. Dengan cukup banyak
         * percobaan, penyerang bisa menebak tanda tangan byte demi byte.
         *
         * `hash_equals` membandingkan dalam waktu yang tidak bergantung isinya.
         */
        if (! hash_equals(self::sign($encoded), $signature)) {
            return null;
        }

        try {
            $payload = json_decode(
                self::base64UrlDecode($encoded),
                associative: true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($payload)
            || ! isset($payload['driver_id'], $payload['exp'])) {
            return null;
        }

        if ((int) $payload['exp'] < now()->getTimestamp()) {
            return null;
        }

        return [
            'driver_id' => (int) $payload['driver_id'],
            'services' => array_values((array) ($payload['services'] ?? [])),
            'exp' => (int) $payload['exp'],
        ];
    }

    /**
     * Rahasia bersama antara Laravel dan layanan lokasi Go.
     *
     * ========================================================================
     *  RAHASIA TERSENDIRI, BUKAN APP_KEY
     * ========================================================================
     *  Memakai `APP_KEY` akan bekerja, dan itu masalahnya: `APP_KEY` juga
     *  mengenkripsi session, cookie, dan kolom terenkripsi di database.
     *
     *  Membagikannya ke layanan Go berarti satu layanan yang bocor membuka
     *  SEMUANYA — dan layanan lokasi adalah yang paling terekspos, karena dia
     *  menerima permintaan dari setiap aplikasi driver.
     *
     *  Rahasia terpisah berarti kompromi di sana berhenti di sana, dan bisa
     *  dirotasi tanpa mengeluarkan seluruh pengguna dari sesinya.
     * ========================================================================
     */
    private static function secret(): string
    {
        $secret = (string) Config::get('antaride.location_service.shared_secret', '');

        if ($secret === '') {
            /*
             * Fallback yang DITURUNKAN dari APP_KEY, bukan APP_KEY-nya.
             *
             * Ada supaya lingkungan pengembangan jalan tanpa konfigurasi
             * tambahan. Turunannya satu arah, jadi layanan Go tidak pernah
             * memegang APP_KEY itu sendiri.
             *
             * Untuk produksi, set `LOCATION_SERVICE_SECRET` — dan
             * `antaride:health` menandainya kalau masih memakai fallback.
             */
            $secret = hash_hmac(
                'sha256',
                'antaride-location-service',
                (string) Config::get('app.key'),
            );
        }

        return $secret;
    }

    private static function sign(string $encoded): string
    {
        return self::base64UrlEncode(
            hash_hmac('sha256', $encoded, self::secret(), binary: true),
        );
    }

    private static function base64UrlEncode(string $value): string
    {
        // Base64 URL-safe tanpa padding: tiketnya dikirim di header HTTP, dan
        // `+`, `/`, serta `=` menuntut escaping di sebagian klien.
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        $padded = str_pad(
            strtr($value, '-_', '+/'),
            (int) (ceil(strlen($value) / 4) * 4),
            '=',
        );

        return (string) base64_decode($padded, strict: true);
    }
}
