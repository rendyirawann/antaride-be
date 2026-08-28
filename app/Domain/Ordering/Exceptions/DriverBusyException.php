<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/**
 * Driver sudah memegang order lain yang belum selesai.
 *
 * Dilempar dari dua tempat, dan itu disengaja:
 *
 *   1. Pemeriksaan eksplisit sebelum transisi, yang menghasilkan pesan ini.
 *   2. Penerjemahan pelanggaran partial unique index
 *      `orders_one_active_per_driver`, sebagai jaring terakhir.
 *
 * Yang kedua ada karena pemeriksaan pertama bisa dilewati balapan: dua request
 * accept dari driver YANG SAMA untuk dua order berbeda, tiba pada saat yang
 * sama, keduanya melihat "belum ada order aktif". Hanya database yang bisa
 * memutuskan itu, dan pesannya harus tetap sama supaya driver tidak melihat
 * dua bentuk kesalahan untuk keadaan yang identik.
 */
class DriverBusyException extends DomainException
{
    public static function alreadyHasActiveOrder(): self
    {
        return new self('Selesaikan dulu order yang sedang berjalan.');
    }

    public function errorCode(): string
    {
        return 'DRIVER_BUSY';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
