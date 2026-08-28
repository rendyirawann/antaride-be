<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/**
 * Dompet dibekukan dan tidak boleh mengeluarkan uang.
 *
 * Pembekuan bersifat satu arah: dompet yang dibekukan tetap bisa MENERIMA.
 * Pendapatan driver yang sedang diselidiki tidak boleh hilang; yang ditahan
 * adalah kemampuannya menarik atau membelanjakan.
 */
class WalletFrozenException extends DomainException
{
    public static function forWallet(int $walletId, ?string $reason): self
    {
        return new self(
            $reason === null
                ? 'Dompet sedang dibekukan. Hubungi layanan pelanggan.'
                : "Dompet sedang dibekukan: {$reason}",
            details: ['wallet_id' => $walletId, 'reason' => $reason],
        );
    }

    public function errorCode(): string
    {
        return 'WALLET_FROZEN';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
