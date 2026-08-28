<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/**
 * Peristiwa ledger yang tidak seimbang.
 *
 * Ini BUG di kode, bukan kesalahan pengguna, jadi statusnya 500. Yang perlu
 * terjadi adalah alert ke Sentry.
 *
 * Database juga menolaknya lewat constraint trigger `wallet_transactions_balanced`,
 * dan itu jaring yang tidak bisa dilewati. Pemeriksaan di sisi PHP ada supaya
 * pesannya bisa dibaca saat pengembangan: pesan dari PostgreSQL menyebut
 * group_uuid dan selisihnya, tapi tidak menyebut entry mana yang salah.
 */
class UnbalancedLedgerException extends DomainException
{
    /**
     * @param  array<int, array<string, mixed>>  $entries
     */
    public static function withNet(int $net, array $entries): self
    {
        return new self(
            "Peristiwa ledger tidak seimbang: selisih {$net} (kredit dikurangi debit). "
            .'Setiap peristiwa double-entry harus berjumlah nol.',
            details: ['net' => $net, 'entries' => $entries],
        );
    }

    public function errorCode(): string
    {
        return 'LEDGER_UNBALANCED';
    }

    public function httpStatus(): int
    {
        return 500;
    }
}
