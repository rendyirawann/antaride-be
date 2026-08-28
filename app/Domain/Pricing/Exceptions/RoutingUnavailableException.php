<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/**
 * Mesin routing tidak bisa dihubungi atau tidak menemukan rute.
 *
 * Statusnya 503, bukan 500, karena ini biasanya sementara dan aplikasi boleh
 * mencoba lagi. Yang penting: quote TIDAK PERNAH dibuat dengan jarak yang
 * ditebak. Lebih baik pengguna melihat "coba lagi" daripada dikenai ongkos
 * yang dihitung dari jarak garis lurus, yang bisa setengah dari jarak
 * sebenarnya di kota dengan jalan satu arah dan sungai.
 */
class RoutingUnavailableException extends DomainException
{
    public function errorCode(): string
    {
        return 'ROUTING_UNAVAILABLE';
    }

    public function httpStatus(): int
    {
        return 503;
    }
}
