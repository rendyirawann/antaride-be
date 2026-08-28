<?php

declare(strict_types=1);

namespace App\Domain\Promo\Models;

use App\Domain\Identity\Models\Admin;
use App\Domain\Shared\Concerns\HasUuid;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Kode promo.
 *
 * `used_count` di sini hanya CACHE. Kebenarannya ada di tabel `promo_usages`,
 * dan reservasi kuota dilakukan dengan SELECT FOR UPDATE pada baris ini di dalam
 * transaksi pembuatan order.
 *
 * Blueprint bagian 5: tanpa lock, 100 orang bisa memakai promo berkuota 50.
 * CHECK constraint `promos_quota_check` adalah jaring terakhirnya.
 */
class Promo extends Model
{
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected $fillable = [
        'code',
        'title',
        'description',
        'banner_url',
        'terms',
        'type',
        'value',
        'max_discount',
        'min_order',
        'service_type_ids',
        'zone_ids',
        'payment_methods',
        'quota_total',
        'quota_per_user',
        'used_count',
        'new_user_only',
        'is_visible',
        'starts_at',
        'ends_at',
        'is_active',
        'cost_bearer',
        'merchant_share_percent',
        'approval_request_id',
        'created_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'max_discount' => 'integer',
            'min_order' => 'integer',
            'service_type_ids' => 'array',
            'zone_ids' => 'array',
            'payment_methods' => 'array',
            'quota_total' => 'integer',
            'quota_per_user' => 'integer',
            'used_count' => 'integer',
            'new_user_only' => 'boolean',
            'is_visible' => 'boolean',
            'is_active' => 'boolean',
            'merchant_share_percent' => 'string',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    // -------------------------------------------------------------------------

    public function usages(): HasMany
    {
        return $this->hasMany(PromoUsage::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    // -------------------------------------------------------------------------

    /**
     * Promo yang sedang bisa dipakai.
     */
    public function scopeRedeemable(Builder $query, ?\DateTimeInterface $at = null): Builder
    {
        $at ??= now();

        return $query
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where('starts_at', '<=', $at)
            ->where('ends_at', '>', $at);
    }

    /**
     * Promo yang berlaku untuk sebuah zona.
     *
     * Memakai operator containment JSONB, jadi memakai index GIN
     * `promos_zone_gin`. Menyaringnya di PHP berarti memuat semua promo aktif
     * lalu membuang mayoritasnya.
     */
    public function scopeForZone(Builder $query, int $zoneId): Builder
    {
        return $query->where(function (Builder $q) use ($zoneId): void {
            $q->whereNull('zone_ids')
                ->orWhereRaw('zone_ids @> ?::jsonb', [json_encode([$zoneId])]);
        });
    }

    public function scopeByCode(Builder $query, string $code): Builder
    {
        // Perbandingan case-insensitive lewat upper(), sejalan dengan unique
        // index `promos_code_unique` yang juga memakai upper(code). Kalau salah
        // satu memakai lower() dan yang lain upper(), index-nya tidak terpakai.
        return $query->whereRaw('upper(code) = ?', [strtoupper($code)]);
    }

    // -------------------------------------------------------------------------

    public function isRedeemable(?\DateTimeInterface $at = null): bool
    {
        $at ??= now();

        return $this->is_active
            && $this->deleted_at === null
            && $this->starts_at->isBefore($at)
            && $this->ends_at->isAfter($at);
    }

    public function hasQuotaLeft(): bool
    {
        return $this->quota_total === null || $this->used_count < $this->quota_total;
    }

    public function remainingQuota(): ?int
    {
        return $this->quota_total === null
            ? null
            : max(0, $this->quota_total - $this->used_count);
    }

    /**
     * Berapa persen kuota yang sudah terpakai.
     *
     * Dipakai panel admin untuk memantau laju pemakaian promo. Promo yang
     * kuotanya habis dalam dua jam pertama biasanya berarti nilainya terlalu
     * besar, dan itu perlu diketahui sebelum kampanye berikutnya.
     */
    public function quotaUsedPercent(): ?float
    {
        if ($this->quota_total === null || $this->quota_total === 0) {
            return null;
        }

        return round(($this->used_count / $this->quota_total) * 100, 1);
    }

    public function appliesToService(int $serviceTypeId): bool
    {
        return $this->service_type_ids === null
            || in_array($serviceTypeId, $this->service_type_ids, true);
    }

    public function appliesToPaymentMethod(string $method): bool
    {
        return $this->payment_methods === null
            || in_array($method, $this->payment_methods, true);
    }

    public function maxDiscount(): ?Money
    {
        return $this->max_discount === null ? null : Money::of($this->max_discount);
    }

    public function minOrder(): Money
    {
        return Money::of($this->min_order);
    }

    /**
     * Apakah biayanya ditanggung merchant, seluruhnya atau sebagian.
     *
     * Ini yang menentukan apakah promo mengurangi pendapatan platform atau
     * pendapatan merchant, dan hampir selalu terlupakan sampai merchant
     * mengeluh laporan penjualannya tidak cocok.
     */
    public function involvesMerchantCost(): bool
    {
        return in_array($this->cost_bearer, ['merchant', 'shared'], true);
    }
}
