<?php

declare(strict_types=1);

namespace App\Domain\Matching\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/**
 * Perintah GEO Redis ditolak atau balasannya tidak dikenali.
 *
 * Hampir selalu berarti satu hal: `REDIS_GEO_COMMAND` disetel `geosearch`
 * padahal servernya di bawah Redis 6.2.
 *
 * Statusnya 500 karena ini salah konfigurasi, bukan kesalahan pengguna. Yang
 * perlu terjadi adalah alert, bukan pesan sopan di aplikasi.
 */
class RedisGeoCommandException extends DomainException
{
    public function errorCode(): string
    {
        return 'GEO_INDEX_UNAVAILABLE';
    }

    public function httpStatus(): int
    {
        return 500;
    }
}
