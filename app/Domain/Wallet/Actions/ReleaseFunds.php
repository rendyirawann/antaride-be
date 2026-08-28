<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Actions;

use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Wallet\DTOs\LedgerEntry;
use App\Domain\Wallet\Models\Wallet;

/**
 * Melepas dana yang sedang ditahan, kembali menjadi saldo yang bisa dipakai.
 *
 * Dipanggil saat order dibatalkan sebelum berjalan, dan sebagai langkah pertama
 * settlement (dana dilepas dulu, lalu dibayarkan). Alasan pola dua langkah itu
 * ada di SettleOrder.
 */
class ReleaseFunds
{
    public function __construct(
        private readonly PostLedgerEntries $postEntries,
    ) {}

    /**
     * @return string group_uuid peristiwanya
     */
    public function handle(
        Wallet $wallet,
        Money $amount,
        string $referenceType,
        int $referenceId,
        ?string $description = null,
    ): string {
        $result = $this->postEntries->handle([
            LedgerEntry::credit(
                walletId: (int) $wallet->getKey(),
                type: 'release',
                amount: $amount,
                referenceType: $referenceType,
                referenceId: $referenceId,
                description: $description ?? "Dana dilepas dari {$referenceType} #{$referenceId}",
            ),
        ]);

        return $result['group_uuid'];
    }
}
