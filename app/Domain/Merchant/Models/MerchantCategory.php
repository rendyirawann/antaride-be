<?php

declare(strict_types=1);

namespace App\Domain\Merchant\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kategori merchant: makanan, minuman, apotek, dan seterusnya.
 */
class MerchantCategory extends Model
{
    use HasFactory;

    protected $table = 'merchant_categories';

    protected $fillable = ['name', 'slug', 'icon_url', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function merchants(): HasMany
    {
        return $this->hasMany(Merchant::class, 'category_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }
}
