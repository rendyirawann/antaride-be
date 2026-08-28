<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\ValueObjects\Money;

/**
 * Saldo tidak cukup untuk operasi yang diminta.
 *
 * `details` memuat saldo saat ini dan kekurangannya, supaya aplikasi bisa
 * menampilkan "Saldo Anda Rp 12.000, kurang Rp 13.000" tanpa memanggil endpoint
 * lain. Blueprint bagian 8 menegaskan pesan kesalahan harus menyebutkan apa yang
 * gagal dan langkah berikutnya, bukan "Terjadi kesalahan".
 */
class InsufficientBalanceException extends DomainException
{
    public static function forWallet(int $walletId, Money $available, Money $required): self
    {
        $shortfall = $required->minus($available);

        return new self(
            "Saldo tidak cukup. Saldo Anda {$available->format()}, kurang {$shortfall->format()}.",
            details: [
                'wallet_id' => $walletId,
                'available' => $available->amount,
                'required' => $required->amount,
                'shortfall' => $shortfall->amount,
                'available_formatted' => $available->format(),
                'shortfall_formatted' => $shortfall->format(),
            ],
        );
    }

    public function errorCode(): string
    {
        return 'INSUFFICIENT_BALANCE';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
