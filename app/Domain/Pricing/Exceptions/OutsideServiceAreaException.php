<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/**
 * Titik jemput di luar seluruh zona operasional aktif.
 *
 * Bukan kegagalan sistem, jadi statusnya 422 dan pesannya layak ditampilkan
 * apa adanya. Kode errornya dipisahkan dari kegagalan validasi biasa supaya
 * aplikasi bisa bereaksi berbeda: yang perlu ditampilkan bukan "input salah",
 * tapi peta dengan batas area layanan supaya penumpang bisa memindahkan pin.
 */
class OutsideServiceAreaException extends DomainException
{
    public function errorCode(): string
    {
        return 'OUTSIDE_SERVICE_AREA';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
