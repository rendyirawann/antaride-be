<?php

declare(strict_types=1);

namespace App\Domain\Ordering\StateMachine;

use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Shared\ValueObjects\Coordinate;

/**
 * Satu permintaan perubahan status order, beserta konteksnya.
 *
 * Dibuat value object supaya seluruh informasi yang perlu dicatat ikut terbawa
 * sampai ke `order_status_logs`. Tanpa ini, pemanggil harus mengingat untuk
 * mengirim aktor, posisi, dan catatan sebagai parameter terpisah, dan yang
 * paling sering terlupakan adalah posisi — padahal itu yang menjawab "apakah
 * driver benar-benar ada di titik jemput saat menekan tombol sampai".
 */
final readonly class OrderTransition
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    private function __construct(
        public OrderStatus $to,
        public string $actorType,
        public ?int $actorId,
        public ?Coordinate $coordinate,
        public ?string $note,
        public array $metadata,
    ) {}

    public static function byDriver(
        OrderStatus $to,
        int $driverId,
        ?Coordinate $coordinate = null,
        ?string $note = null,
        array $metadata = [],
    ): self {
        return new self($to, 'driver', $driverId, $coordinate, $note, $metadata);
    }

    public static function byUser(
        OrderStatus $to,
        int $userId,
        ?string $note = null,
        array $metadata = [],
    ): self {
        return new self($to, 'user', $userId, null, $note, $metadata);
    }

    /**
     * Transisi oleh admin.
     *
     * Catatan WAJIB. Intervensi manual pada order orang lain harus punya alasan
     * tertulis, dan alasan itu yang dibaca saat ada sengketa. Admin yang
     * membatalkan order tanpa menjelaskan kenapa membuat CS tidak bisa menjawab
     * penumpang yang menelepon.
     */
    public static function byAdmin(
        OrderStatus $to,
        int $adminId,
        string $note,
        array $metadata = [],
    ): self {
        return new self($to, 'admin', $adminId, null, $note, $metadata);
    }

    public static function bySystem(
        OrderStatus $to,
        ?string $note = null,
        array $metadata = [],
    ): self {
        return new self($to, 'system', null, null, $note, $metadata);
    }

    public function isBySystem(): bool
    {
        return $this->actorType === 'system';
    }

    public function isByAdmin(): bool
    {
        return $this->actorType === 'admin';
    }
}
