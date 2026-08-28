<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Exceptions;

use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Shared\Exceptions\DomainException;

/**
 * Order sudah diambil driver lain.
 *
 * ============================================================================
 *  KENAPA 409, DAN KENAPA PESANNYA TIDAK MENYEBUT SIAPA
 * ============================================================================
 *  409 Conflict, bukan 403 atau 422. Driver tidak melakukan kesalahan dan
 *  permintaannya tidak salah bentuk — dia hanya kalah beberapa milidetik.
 *  Perbedaannya penting untuk aplikasi driver: 409 berarti "tutup dialog
 *  penawaran, tampilkan yang berikutnya", sementara 422 akan tampil sebagai
 *  kesalahan pengisian dan 403 sebagai masalah izin.
 *
 *  Pesannya TIDAK menyebut driver mana yang menang, dan `details` juga tidak.
 *  Empat driver yang kalah tidak perlu tahu siapa yang mengambil order, dan
 *  memberitahukannya membuka jalan pemetaan siapa bekerja di area mana —
 *  informasi yang bisa dipakai untuk menekan driver lain di lapangan.
 *
 *  Id pemenangnya tetap dibawa sebagai properti untuk log dan panel admin,
 *  TAPI tidak pernah masuk `details()` yang dikirim ke aplikasi.
 * ============================================================================
 */
class OrderAlreadyTakenException extends DomainException
{
    public ?int $heldByDriverId = null;

    public ?OrderStatus $currentStatus = null;

    public static function heldByAnother(?int $driverId): self
    {
        $e = new self('Order ini sedang diproses driver lain.');
        $e->heldByDriverId = $driverId;

        return $e;
    }

    public static function statusChanged(OrderStatus $status): self
    {
        $e = new self(match ($status) {
            OrderStatus::Cancelled => 'Order sudah dibatalkan penumpang.',
            OrderStatus::NoDriver, OrderStatus::Expired => 'Order sudah kadaluarsa.',
            default => 'Order sudah diambil driver lain.',
        });

        $e->currentStatus = $status;

        return $e;
    }

    public static function orderGone(): self
    {
        return new self('Order sudah tidak ada.');
    }

    public function errorCode(): string
    {
        return 'ORDER_ALREADY_TAKEN';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    /**
     * Status order saja yang dibagikan, supaya aplikasi driver bisa memilih
     * antara "tampilkan penawaran berikutnya" dan "order ini sudah berakhir".
     *
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return array_filter([
            'order_status' => $this->currentStatus?->value,
        ], static fn ($v): bool => $v !== null);
    }
}
