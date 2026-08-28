<?php

declare(strict_types=1);

namespace App\Domain\Driver\Models;

use App\Domain\Catalog\Models\ServiceType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Layanan apa saja yang boleh diambil seorang driver.
 *
 * Dua flag yang sengaja dipisah, karena pemilik keputusannya berbeda:
 *
 *   is_enabled        kelayakan yang ditetapkan admin
 *   enabled_by_driver pilihan driver sendiri
 *
 * Driver boleh mematikan layanan tertentu (misal tidak mau terima order
 * makanan) tanpa admin ikut campur. Sebaliknya, admin bisa mencabut kelayakan
 * dan driver tidak bisa menyalakannya kembali. Kalau keduanya digabung jadi
 * satu kolom, salah satu pihak akan menimpa keputusan pihak lain tanpa jejak.
 */
class DriverServiceEligibility extends Model
{
    use HasFactory;

    protected $table = 'driver_service_eligibility';

    protected $fillable = [
        'driver_id',
        'service_type_id',
        'is_enabled',
        'enabled_by_driver',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'enabled_by_driver' => 'boolean',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    /**
     * Kombinasi yang benar-benar bisa menerima order.
     *
     * Keduanya harus true. Ini yang dipakai filter matching.
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('is_enabled', true)->where('enabled_by_driver', true);
    }

    public function isUsable(): bool
    {
        return $this->is_enabled && $this->enabled_by_driver;
    }
}
