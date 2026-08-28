<?php

declare(strict_types=1);

namespace App\Domain\Merchant\Models;

use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Varian item menu: level pedas, ukuran, topping.
 *
 * Dikelompokkan lewat `group_name`. Semua opsi dengan group_name yang sama
 * membentuk satu kelompok pilihan, dan `is_required` serta `max_select`
 * berlaku untuk kelompoknya, bukan untuk satu opsi.
 */
class MenuItemOption extends Model
{
    use HasFactory;

    protected $table = 'menu_item_options';

    protected $fillable = [
        'menu_item_id',
        'group_name',
        'name',
        'extra_price',
        'is_required',
        'max_select',
        'is_available',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'extra_price' => 'integer',
            'is_required' => 'boolean',
            'is_available' => 'boolean',
            'max_select' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'menu_item_id');
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_available', true)->orderBy('sort_order');
    }

    public function extraPrice(): Money
    {
        return Money::of($this->extra_price);
    }

    public function isFree(): bool
    {
        return $this->extra_price === 0;
    }
}
