<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Models;

use App\Domain\Identity\Models\Admin;
use App\Domain\Shared\Concerns\HasUuid;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Buku besar. APPEND ONLY.
 *
 * Tidak ada UPDATE, tidak ada DELETE, selamanya. Koreksi dilakukan dengan
 * membuat baris `reversal` yang membalik, bukan dengan mengubah baris lama.
 *
 * Database menegakkan dua hal yang tidak bisa dilanggar kode:
 *
 *   1. Aritmetika: balance_after harus konsisten dengan direction dan amount
 *      (CHECK `wallet_transactions_arithmetic_check`).
 *   2. Double-entry: setiap `group_uuid` harus berjumlah nol, diperiksa saat
 *      COMMIT oleh constraint trigger `wallet_transactions_balanced`.
 *
 * Yang kedua itu yang membuat selisih ledger tidak bisa pernah ada, bukan
 * ditemukan belakangan oleh job rekonsiliasi.
 */
class WalletTransaction extends Model
{
    use HasUuid;

    public const UPDATED_AT = null;

    /**
     * Jenis yang bersifat reklasifikasi dalam satu dompet, bukan perpindahan
     * nilai antar pihak.
     *
     * Dikecualikan dari pemeriksaan double-entry, karena tidak ada lawan
     * transaksi untuk diseimbangkan. Daftar ini HARUS sama dengan yang ada di
     * function `assert_ledger_group_balanced()` di database.
     */
    public const INTRA_WALLET_TYPES = ['hold', 'release'];

    protected $fillable = [
        'wallet_id',
        'type',
        'direction',
        'amount',
        'balance_before',
        'balance_after',
        'reference_type',
        'reference_id',
        'group_uuid',
        'description',
        'metadata',
        'created_by_admin_id',
        'approval_request_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'balance_before' => 'integer',
            'balance_after' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    // -------------------------------------------------------------------------
    // Relasi
    // -------------------------------------------------------------------------

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    // -------------------------------------------------------------------------
    // Scope
    // -------------------------------------------------------------------------

    /**
     * Seluruh baris satu peristiwa double-entry.
     *
     * Dipakai halaman detail order untuk menampilkan mutasi ledger terkait, dan
     * job rekonsiliasi untuk memeriksa keseimbangan.
     */
    public function scopeInGroup(Builder $query, string $groupUuid): Builder
    {
        return $query->where('group_uuid', $groupUuid)->orderBy('id');
    }

    public function scopeForReference(Builder $query, string $type, int $id): Builder
    {
        return $query->where('reference_type', $type)->where('reference_id', $id);
    }

    /**
     * Penyesuaian manual oleh admin. Yang paling sering diaudit.
     */
    public function scopeManualAdjustments(Builder $query): Builder
    {
        return $query->whereIn('type', ['adjustment', 'reversal'])->orderByDesc('created_at');
    }

    // -------------------------------------------------------------------------
    // Nilai
    // -------------------------------------------------------------------------

    public function amount(): Money
    {
        return Money::of($this->amount);
    }

    /**
     * Nominal bertanda: positif untuk kredit, negatif untuk debit.
     *
     * Kolom `amount` selalu positif; arahnya di kolom `direction`. Method ini
     * untuk penyajian dan penjumlahan, BUKAN untuk disimpan. Mencampur tanda ke
     * dalam kolom amount adalah cara pasti menghasilkan pembukuan yang tidak
     * bisa dijumlahkan.
     */
    public function signedAmount(): Money
    {
        return $this->isCredit() ? $this->amount() : $this->amount()->negated();
    }

    public function isCredit(): bool
    {
        return $this->direction === 'credit';
    }

    public function isIntraWallet(): bool
    {
        return in_array($this->type, self::INTRA_WALLET_TYPES, true);
    }

    public function label(): string
    {
        return match ($this->type) {
            'topup' => 'Top up saldo',
            'ride_payment' => 'Pembayaran perjalanan',
            'ride_earning' => 'Pendapatan perjalanan',
            'commission' => 'Komisi platform',
            'hold' => 'Dana ditahan',
            'release' => 'Dana dilepas',
            'refund' => 'Pengembalian dana',
            'withdrawal' => 'Penarikan saldo',
            'bonus' => 'Bonus',
            'incentive' => 'Insentif',
            'penalty' => 'Denda',
            'adjustment' => 'Penyesuaian manual',
            'referral' => 'Bonus referral',
            'settlement' => 'Settlement',
            'reversal' => 'Pembalikan transaksi',
            'cancellation_fee' => 'Biaya pembatalan',
            default => ucfirst((string) $this->type),
        };
    }
}
