<?php

declare(strict_types=1);

namespace App\Domain\Driver\Models;

use App\Domain\Shared\Concerns\HasUuid;
use Database\Factories\VehicleFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kendaraan driver.
 *
 * Plat dinormalkan sebelum disimpan, supaya "BK 1234 AB" dan "bk1234ab" tidak
 * jadi dua kendaraan berbeda. Normalisasinya di mutator, bukan di controller,
 * supaya jalur mana pun yang menulis tetap konsisten.
 */
#[UseFactory(VehicleFactory::class)]
class Vehicle extends Model
{
    use HasFactory;
    use HasUuid;

    protected $fillable = [
        'driver_id',
        'type',
        'brand',
        'model',
        'year',
        'color',
        'plate_number',
        'stnk_number',
        'stnk_expires_at',
        'capacity',
        'is_active',
    ];

    protected $hidden = ['stnk_number'];

    protected function casts(): array
    {
        return [
            'stnk_number' => 'encrypted',
            'stnk_expires_at' => 'date',
            'year' => 'integer',
            'capacity' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Plat dinormalkan: huruf besar, tanpa spasi dan tanda hubung.
     */
    public function setPlateNumberAttribute(?string $value): void
    {
        $this->attributes['plate_number'] = $value === null
            ? null
            : strtoupper(preg_replace('/[\s\-]+/', '', $value) ?? '');
    }

    /**
     * Plat untuk ditampilkan, dengan spasi yang mudah dibaca.
     *
     * BK1234AB menjadi "BK 1234 AB". Ini yang dilihat penumpang saat mencari
     * kendaraannya di antara puluhan motor di pinggir jalan, jadi keterbacaannya
     * penting.
     */
    public function plateFormatted(): string
    {
        $plate = (string) $this->plate_number;

        if (preg_match('/^([A-Z]{1,2})(\d{1,4})([A-Z]{0,3})$/', $plate, $m) === 1) {
            return trim("{$m[1]} {$m[2]} {$m[3]}");
        }

        return $plate;
    }

    public function description(): string
    {
        return trim(implode(' ', array_filter([
            $this->brand,
            $this->model,
            $this->color === null ? null : "({$this->color})",
        ])));
    }

    public function isStnkExpired(): bool
    {
        return $this->stnk_expires_at !== null && $this->stnk_expires_at->isPast();
    }
}
