<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Exceptions;

use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Shared\Exceptions\DomainException;

/**
 * Transisi status yang tidak diizinkan state machine.
 *
 * Blueprint bagian 12 menempatkan status order yang bebas berubah sebagai
 * kesalahan nomor delapan: "Order selesai kembali jadi mencari driver, dan
 * tidak ada yang tahu kenapa."
 *
 * Statusnya 409, bukan 422. Bedanya bermakna untuk aplikasi: 422 berarti
 * "datamu salah, perbaiki", 409 berarti "keadaan sudah berubah, muat ulang".
 * Yang kedua itu yang benar di sini, dan biasanya terjadi karena dua hal:
 * driver menekan tombol dua kali, atau penumpang membatalkan tepat saat driver
 * menekan terima.
 */
class InvalidStatusTransitionException extends DomainException
{
    public static function between(OrderStatus $from, OrderStatus $to): self
    {
        $allowed = array_map(
            static fn (OrderStatus $s) => $s->value,
            $from->allowedTransitions(),
        );

        return new self(
            $from->isFinal()
                ? "Order sudah {$from->label()} dan tidak bisa diubah lagi."
                : "Order sedang {$from->label()}, tidak bisa langsung menjadi {$to->label()}.",
            details: [
                'from' => $from->value,
                'to' => $to->value,
                'allowed' => $allowed,
            ],
        );
    }

    public function errorCode(): string
    {
        return 'INVALID_STATUS_TRANSITION';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
