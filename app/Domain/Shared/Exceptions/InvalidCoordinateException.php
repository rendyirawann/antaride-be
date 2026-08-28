<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exceptions;

/**
 * Koordinat yang tidak sah.
 *
 * Berbeda dari InvalidMoneyException, ini BISA berasal dari input pengguna
 * (ping GPS dari app driver, titik jemput yang dipilih user), jadi statusnya
 * 422 dan pesannya layak ditampilkan.
 */
class InvalidCoordinateException extends DomainException
{
    public function errorCode(): string
    {
        return 'INVALID_COORDINATE';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
