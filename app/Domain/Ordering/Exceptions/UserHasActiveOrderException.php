<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Exceptions;

use App\Domain\Ordering\Models\Order;
use App\Domain\Shared\Exceptions\DomainException;

/**
 * Pengguna sudah punya order yang belum selesai.
 *
 * Aturan produk, bukan batasan teknis: penumpang yang memesan dua ojek
 * sekaligus membuat satu driver datang untuk penumpang yang sudah berangkat,
 * dan driver itu tidak mendapat apa pun.
 *
 * UUID order yang menghalangi ikut dibawa di `details`, supaya aplikasi bisa
 * langsung membuka halaman order itu alih-alih hanya menampilkan pesan. Tanpa
 * itu, penumpang yang lupa punya order berjalan tidak punya cara menemukannya
 * selain menelusuri riwayat.
 */
class UserHasActiveOrderException extends DomainException
{
    public ?string $activeOrderUuid = null;

    public ?string $activeOrderNumber = null;

    public static function make(Order $active): self
    {
        $e = new self(
            'Anda masih punya order yang berjalan. Selesaikan atau batalkan dulu.',
            details: [
                'active_order_uuid' => (string) $active->uuid,
                'active_order_number' => (string) $active->order_number,
                'active_order_status' => $active->status->value,
            ],
        );

        $e->activeOrderUuid = (string) $active->uuid;
        $e->activeOrderNumber = (string) $active->order_number;

        return $e;
    }

    public function errorCode(): string
    {
        return 'USER_HAS_ACTIVE_ORDER';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
