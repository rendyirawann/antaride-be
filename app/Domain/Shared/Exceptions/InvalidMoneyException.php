<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exceptions;

/**
 * Nilai uang yang tidak sah.
 *
 * Ini bukan kegagalan aturan bisnis yang perlu ditampilkan ke pengguna, tapi
 * bug di kode. Karena itu status HTTP-nya 500, bukan 422: yang perlu terjadi
 * adalah alert ke Sentry, bukan pesan sopan di aplikasi.
 */
class InvalidMoneyException extends DomainException
{
    public function errorCode(): string
    {
        return 'INVALID_MONEY_VALUE';
    }

    public function httpStatus(): int
    {
        return 500;
    }
}
