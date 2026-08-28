<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/**
 * Nomor HP tidak berbentuk nomor seluler Indonesia.
 *
 * Nomor yang ditolak TIDAK diulang di pesan errornya. Nomor HP adalah data
 * pribadi, dan pesan error masuk ke log aplikasi, log gateway, dan laporan
 * kesalahan pihak ketiga — tiga tempat yang tidak seharusnya menyimpan nomor HP
 * siapa pun.
 */
class InvalidPhoneNumberException extends DomainException
{
    public static function make(string $raw): self
    {
        return new self('Nomor HP tidak valid. Contoh: 081234567890.');
    }

    public static function notMobile(string $raw): self
    {
        return new self(
            'Masukkan nomor HP, bukan nomor telepon rumah.',
            details: ['reason' => 'NOT_MOBILE'],
        );
    }

    public function errorCode(): string
    {
        return 'INVALID_PHONE_NUMBER';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
