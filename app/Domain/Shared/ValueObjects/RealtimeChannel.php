<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObjects;

use Stringable;

/**
 * Nama channel realtime.
 *
 * Dibuat value object supaya nama channel tidak pernah disusun dengan
 * penggabungan string yang bertebaran di puluhan tempat. Nama channel adalah
 * kontrak dengan tiga aplikasi Flutter: satu salah ketik di satu tempat berarti
 * pesan terkirim ke channel yang tidak didengarkan siapa pun, dan tidak ada
 * error yang muncul.
 *
 * Semua channel di sini BERSIFAT PRIVAT dan butuh token langganan. Awalan
 * dolar adalah konvensi Centrifugo untuk channel yang wajib berotorisasi;
 * tanpa awalan itu, Centrifugo mengizinkan siapa pun berlangganan.
 */
final readonly class RealtimeChannel implements Stringable
{
    private function __construct(
        public string $name,
    ) {}

    /**
     * Channel satu order: posisi driver, perubahan status, percakapan.
     *
     * Memakai UUID order, bukan id auto-increment. Kalau memakai id, siapa pun
     * yang punya token untuk satu order bisa menebak nama channel order lain
     * dengan menambah satu.
     */
    public static function order(string $orderUuid): self
    {
        return new self("\$order:{$orderUuid}");
    }

    /**
     * Channel seorang driver: penawaran order masuk, pengumuman.
     */
    public static function driver(int $driverId): self
    {
        return new self("\$driver:{$driverId}");
    }

    /**
     * Channel seorang pengguna: notifikasi umum.
     */
    public static function user(int $userId): self
    {
        return new self("\$user:{$userId}");
    }

    /**
     * Channel merchant: order baru masuk.
     */
    public static function merchant(int $merchantId): self
    {
        return new self("\$merchant:{$merchantId}");
    }

    /**
     * Channel dashboard ops: seluruh peristiwa untuk panel admin.
     */
    public static function adminLive(): self
    {
        return new self('$admin:live');
    }

    /**
     * Channel live map per petak geohash.
     *
     * Bukan per driver, dan itu penting. Admin yang berlangganan channel berisi
     * semua ping driver akan menerima 250 pesan per detik untuk 1.000 driver;
     * browsernya hang dan bandwidth habis. Dengan geohash presisi 5, satu petak
     * kira-kira 5 km, dan panel hanya berlangganan petak yang sedang terlihat
     * di layar.
     */
    public static function adminGeo(string $geohash): self
    {
        return new self("\$admin:geo:{$geohash}");
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
