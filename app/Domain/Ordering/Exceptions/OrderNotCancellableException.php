<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Exceptions;

use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Shared\Exceptions\DomainException;

/**
 * Order tidak bisa dibatalkan dalam keadaan sekarang.
 *
 * Pesannya dibedakan per status karena tindak lanjutnya berbeda, dan pesan
 * generik "tidak bisa dibatalkan" adalah yang paling sering membuat penumpang
 * menelepon CS:
 *
 *   sedang berjalan  -> minta driver menepi, atau selesaikan lalu ajukan tiket
 *   sudah selesai    -> ajukan tiket kalau ada masalah
 *   sudah dibatalkan -> tidak ada yang perlu dilakukan
 */
class OrderNotCancellableException extends DomainException
{
    public ?OrderStatus $currentStatus = null;

    public static function becauseStatus(OrderStatus $status): self
    {
        $e = new self(
            match ($status) {
                OrderStatus::InProgress => 'Perjalanan sudah dimulai dan tidak bisa dibatalkan. '
                    .'Minta driver menepi, atau ajukan bantuan setelah order selesai.',
                OrderStatus::Completed => 'Order sudah selesai. Ajukan bantuan kalau ada masalah.',
                OrderStatus::Cancelled => 'Order ini sudah dibatalkan.',
                OrderStatus::NoDriver, OrderStatus::Expired => 'Order ini sudah berakhir.',
                default => 'Order tidak bisa dibatalkan dalam keadaan sekarang.',
            },
            details: ['order_status' => $status->value],
        );

        $e->currentStatus = $status;

        return $e;
    }

    public static function orderGone(): self
    {
        return new self('Order sudah tidak ada.');
    }

    public function errorCode(): string
    {
        return 'ORDER_NOT_CANCELLABLE';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
