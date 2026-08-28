<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Models;

use App\Domain\Shared\Concerns\HasUuid;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Dompet: milik pengguna, driver, merchant, atau platform.
 *
 * ============================================================================
 *  SALDO DI SINI HANYA CACHE
 * ============================================================================
 *  Kebenarannya ada di `wallet_transactions` yang APPEND ONLY. Kolom `balance`
 *  diperbarui di dalam transaksi DB yang sama dengan barisnya, semata supaya
 *  tidak perlu menjumlahkan seluruh riwayat setiap kali saldo dibaca.
 *
 *  Yang TIDAK boleh dilakukan: menulis ke `balance` tanpa membuat baris
 *  transaksi pendamping. Begitu ada selisih dan tidak ada baris yang
 *  menjelaskannya, tidak ada cara merekonstruksi apa yang terjadi.
 *
 *  Karena itu class ini sengaja TIDAK punya method `addBalance()` atau
 *  sejenisnya. Perubahan saldo hanya lewat Action di App\Domain\Wallet\Actions
 *  yang selalu membuat pasangan barisnya.
 * ============================================================================
 */
class Wallet extends Model
{
    use HasFactory;
    use HasUuid;

    /**
     * Akun platform sebagai lawan transaksi ledger.
     *
     * Nilainya bagian dari kontrak data. Setelah ada baris ledger yang
     * menunjuknya, JANGAN pernah diubah.
     */
    public const PLATFORM_REVENUE = 1;

    public const PLATFORM_SETTLEMENT = 2;

    public const PLATFORM_PROMO_COST = 3;

    public const PLATFORM_INCENTIVE_COST = 4;

    public const PLATFORM_REFUND_COST = 5;

    protected $fillable = [
        'owner_type',
        'owner_id',
        'currency',
        'balance',
        'held_balance',
        'version',
        'is_frozen',
        'frozen_reason',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'integer',
            'held_balance' => 'integer',
            'version' => 'integer',
            'is_frozen' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    // -------------------------------------------------------------------------
    // Relasi
    // -------------------------------------------------------------------------

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class)->orderByDesc('created_at');
    }

    public function topups(): HasMany
    {
        return $this->hasMany(Topup::class);
    }

    public function withdrawals(): HasMany
    {
        return $this->hasMany(Withdrawal::class);
    }

    // -------------------------------------------------------------------------
    // Scope
    // -------------------------------------------------------------------------

    public function scopeOwnedBy(Builder $query, string $ownerType, int $ownerId): Builder
    {
        return $query->where('owner_type', $ownerType)->where('owner_id', $ownerId);
    }

    public function scopePlatform(Builder $query): Builder
    {
        return $query->where('owner_type', 'platform');
    }

    // -------------------------------------------------------------------------
    // Nilai
    // -------------------------------------------------------------------------

    public function balance(): Money
    {
        return Money::of($this->balance);
    }

    public function heldBalance(): Money
    {
        return Money::of($this->held_balance);
    }

    /**
     * Saldo ditambah yang sedang ditahan.
     *
     * Ini yang ditampilkan sebagai "total saldo" ke driver, karena dana yang
     * tertahan tetap miliknya, hanya belum bisa dipakai.
     */
    public function totalBalance(): Money
    {
        return $this->balance()->plus($this->heldBalance());
    }

    public function hasSufficientBalance(Money $amount): bool
    {
        return $this->balance()->isGreaterThanOrEqual($amount);
    }

    public function isFrozen(): bool
    {
        return $this->is_frozen;
    }

    /**
     * Apakah dompet ini bisa dipakai bertransaksi.
     *
     * Dompet yang dibekukan tetap bisa MENERIMA (supaya pendapatan driver yang
     * sedang diselidiki tidak hilang), tapi tidak bisa mengeluarkan.
     */
    public function canDebit(): bool
    {
        return ! $this->is_frozen;
    }

    // -------------------------------------------------------------------------
    // Pembuatan
    // -------------------------------------------------------------------------

    /**
     * Ambil dompet, buat kalau belum ada.
     *
     * Memakai firstOrCreate, bukan pemeriksaan lalu insert. Dua request yang
     * tiba bersamaan untuk pengguna yang belum punya dompet akan sama-sama lolos
     * pemeriksaan, dan yang kedua gagal karena unique constraint
     * `(owner_type, owner_id, currency)`. firstOrCreate menangani balapan itu.
     */
    public static function forOwner(string $ownerType, int $ownerId, string $currency = 'IDR'): self
    {
        return self::firstOrCreate(
            [
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'currency' => $currency,
            ],
            [
                'balance' => 0,
                'held_balance' => 0,
                'version' => 0,
                'is_frozen' => false,
            ],
        );
    }

    /**
     * Akun platform. Dibuat seeder, jadi harus sudah ada.
     */
    public static function platform(int $account): self
    {
        $wallet = self::query()
            ->where('owner_type', 'platform')
            ->where('owner_id', $account)
            ->first();

        if ($wallet === null) {
            throw new \RuntimeException(
                "Dompet platform {$account} belum ada. Jalankan: php artisan db:seed --class=SystemSeeder"
            );
        }

        return $wallet;
    }
}
