<?php

declare(strict_types=1);

namespace App\Domain\Merchant\Models;

use App\Domain\Shared\Concerns\HasUuid;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Item menu.
 *
 * Harga di sini adalah harga SEKARANG. Order menyimpan snapshot harganya
 * sendiri, karena merchant boleh mengubah harga kapan saja dan struk order
 * bulan lalu tidak boleh berubah sendiri.
 */
class MenuItem extends Model
{
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected $table = 'menu_items';

    protected $fillable = [
        'merchant_id',
        'category_id',
        'name',
        'description',
        'photo_url',
        'price',
        'discount_price',
        'is_available',
        'stock',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'discount_price' => 'integer',
            'stock' => 'integer',
            'sort_order' => 'integer',
            'is_available' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    // -------------------------------------------------------------------------

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(MenuCategory::class, 'category_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(MenuItemOption::class, 'menu_item_id')->orderBy('sort_order');
    }

    // -------------------------------------------------------------------------

    /**
     * Item yang bisa dipesan sekarang.
     *
     * Stok NULL berarti tidak dilacak, dan itu berbeda dari stok nol. Warung
     * yang tidak menghitung stok nasi goreng tetap bisa menerima pesanan;
     * yang stoknya nol berarti benar-benar habis.
     */
    public function scopeOrderable(Builder $query): Builder
    {
        return $query
            ->where('is_available', true)
            ->whereNull('deleted_at')
            ->where(function (Builder $q): void {
                $q->whereNull('stock')->orWhere('stock', '>', 0);
            });
    }

    // -------------------------------------------------------------------------

    public function price(): Money
    {
        return Money::of($this->price);
    }

    /**
     * Harga yang benar-benar ditagih: harga diskon kalau ada, kalau tidak harga
     * normal.
     */
    public function effectivePrice(): Money
    {
        return Money::of($this->discount_price ?? $this->price);
    }

    public function hasDiscount(): bool
    {
        return $this->discount_price !== null && $this->discount_price < $this->price;
    }

    public function discountPercent(): ?int
    {
        if (! $this->hasDiscount() || $this->price === 0) {
            return null;
        }

        return (int) round((($this->price - $this->discount_price) / $this->price) * 100);
    }

    public function isOrderable(): bool
    {
        return $this->is_available
            && $this->deleted_at === null
            && ($this->stock === null || $this->stock > 0);
    }

    /**
     * Apakah stok cukup untuk jumlah yang diminta.
     *
     * Stok NULL selalu cukup, karena tidak dilacak.
     */
    public function hasStockFor(int $quantity): bool
    {
        return $this->stock === null || $this->stock >= $quantity;
    }
}
