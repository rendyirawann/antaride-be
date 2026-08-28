<?php

declare(strict_types=1);

namespace App\Domain\Promo\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Ordering\Models\Order;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catatan pemakaian promo. Ini SUMBER KEBENARAN kuota, bukan kolom
 * `promos.used_count` yang hanya cache.
 *
 * `platform_cost` dan `merchant_cost` dibekukan saat pemakaian, dan jumlahnya
 * wajib sama dengan `discount_amount` (ditegakkan CHECK constraint
 * `promo_usages_amount_check`).
 *
 * Dibekukan, bukan dihitung ulang, karena `cost_bearer` dan
 * `merchant_share_percent` di tabel promo bisa berubah setelah kampanye
 * berjalan. Menghitung ulang berarti laporan biaya promo bulan lalu berubah
 * sendiri saat ada yang menyesuaikan pembagian biayanya hari ini.
 */
class PromoUsage extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'promo_usages';

    protected $fillable = [
        'promo_id',
        'user_id',
        'order_id',
        'discount_amount',
        'platform_cost',
        'merchant_cost',
    ];

    protected function casts(): array
    {
        return [
            'discount_amount' => 'integer',
            'platform_cost' => 'integer',
            'merchant_cost' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function promo(): BelongsTo
    {
        return $this->belongsTo(Promo::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    // -------------------------------------------------------------------------

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Hitung pemakaian promo oleh seorang pengguna.
     *
     * Ini yang menegakkan `quota_per_user`, dan dihitung dari tabel ini, bukan
     * dari kolom cache.
     */
    public static function countFor(int $promoId, int $userId): int
    {
        return self::query()
            ->where('promo_id', $promoId)
            ->where('user_id', $userId)
            ->count();
    }

    // -------------------------------------------------------------------------

    public function discount(): Money
    {
        return Money::of($this->discount_amount);
    }

    public function platformCost(): Money
    {
        return Money::of($this->platform_cost);
    }

    public function merchantCost(): Money
    {
        return Money::of($this->merchant_cost);
    }

    public function isSharedCost(): bool
    {
        return $this->merchant_cost > 0 && $this->platform_cost > 0;
    }
}
