<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use Database\Factories\ServiceTypeFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Jenis layanan: antar barang, antar motor, antar mobil, pesan makanan.
 */
#[UseFactory(ServiceTypeFactory::class)]
class ServiceType extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'icon_url',
        'vehicle_class',
        'is_active',
        'sort_order',
        'requires_merchant',
        'requires_multi_stop',
        'requires_proof_photo',
        'max_stops',
        'max_weight_gram',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'requires_merchant' => 'boolean',
            'requires_multi_stop' => 'boolean',
            'requires_proof_photo' => 'boolean',
            'sort_order' => 'integer',
            'max_stops' => 'integer',
            'max_weight_gram' => 'integer',
        ];
    }

    public function pricingRules(): HasMany
    {
        return $this->hasMany(PricingRule::class);
    }

    public function surgeRules(): HasMany
    {
        return $this->hasMany(SurgeRule::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeCode(Builder $query, string $code): Builder
    {
        return $query->where('code', $code);
    }
}
