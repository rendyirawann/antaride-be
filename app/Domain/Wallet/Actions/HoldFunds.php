<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Actions;

use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Wallet\DTOs\LedgerEntry;
use App\Domain\Wallet\Models\Wallet;

/**
 * Menahan dana untuk order yang sedang berjalan.
 *
 * Dana keluar dari `balance` dan masuk ke `held_balance` dalam dompet yang sama.
 * Nilainya tetap milik pengguna; yang berubah hanya kemampuannya memakainya.
 *
 * Kenapa dana ditahan dan bukan langsung dibayarkan: order bisa dibatalkan
 * sebelum driver datang, dan mengembalikan uang yang sudah dibayarkan ke driver
 * jauh lebih rumit daripada melepas dana yang belum berpindah. Blueprint bagian
 * 4.5 memakai pola ini untuk seluruh pembayaran wallet.
 */
class HoldFunds
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
            LedgerEntry::debit(
                walletId: (int) $wallet->getKey(),
                type: 'hold',
                amount: $amount,
                referenceType: $referenceType,
                referenceId: $referenceId,
                description: $description ?? "Dana ditahan untuk {$referenceType} #{$referenceId}",
            ),
        ]);

        return $result['group_uuid'];
    }
}
