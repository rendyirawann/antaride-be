<?php

declare(strict_types=1);

namespace App\Domain\Wallet\DTOs;

use App\Domain\Shared\ValueObjects\Money;

/**
 * Satu sisi dari sebuah peristiwa double-entry.
 *
 * Beberapa entry dengan `group_uuid` yang sama membentuk satu peristiwa, dan
 * jumlahnya WAJIB nol. Itu ditegakkan constraint trigger di database saat
 * COMMIT, bukan oleh kode ini.
 *
 * `amount` selalu POSITIF. Arahnya di `direction`. Mencampur tanda ke dalam
 * nominal adalah cara pasti menghasilkan pembukuan yang tidak bisa dijumlahkan,
 * karena setengah kode akan menganggap negatif berarti debit dan setengahnya
 * menganggap sudah termasuk arah.
 */
final readonly class LedgerEntry
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    private function __construct(
        public int $walletId,
        public string $type,
        public string $direction,
        public Money $amount,
        public ?string $referenceType,
        public ?int $referenceId,
        public ?string $description,
        public array $metadata,
        public ?int $createdByAdminId,
        public ?int $approvalRequestId,
    ) {}

    /**
     * Uang MASUK ke dompet ini.
     */
    public static function credit(
        int $walletId,
        string $type,
        Money $amount,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $description = null,
        array $metadata = [],
        ?int $createdByAdminId = null,
        ?int $approvalRequestId = null,
    ): self {
        return new self(
            $walletId, $type, 'credit', $amount,
            $referenceType, $referenceId, $description, $metadata,
            $createdByAdminId, $approvalRequestId,
        );
    }

    /**
     * Uang KELUAR dari dompet ini.
     */
    public static function debit(
        int $walletId,
        string $type,
        Money $amount,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $description = null,
        array $metadata = [],
        ?int $createdByAdminId = null,
        ?int $approvalRequestId = null,
    ): self {
        return new self(
            $walletId, $type, 'debit', $amount,
            $referenceType, $referenceId, $description, $metadata,
            $createdByAdminId, $approvalRequestId,
        );
    }

    public function isCredit(): bool
    {
        return $this->direction === 'credit';
    }

    /**
     * Nominal bertanda, untuk menjumlahkan keseimbangan sebelum menulis.
     *
     * Pemeriksaan keseimbangan yang sebenarnya dilakukan database; yang ini
     * untuk memberi pesan error yang bisa dibaca saat pengembangan, sebelum
     * transaksi dijalankan.
     */
    public function signedAmount(): int
    {
        return $this->isCredit() ? $this->amount->amount : -$this->amount->amount;
    }

    /**
     * Apakah entry ini reklasifikasi dalam satu dompet, bukan perpindahan nilai.
     *
     * Daftar jenisnya sama dengan yang dikecualikan trigger
     * `assert_ledger_group_balanced()` di database.
     */
    public function isIntraWallet(): bool
    {
        return in_array($this->type, ['hold', 'release'], true);
    }
}
