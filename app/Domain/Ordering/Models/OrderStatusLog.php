<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Models;

use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Shared\ValueObjects\Coordinate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riwayat transisi status. APPEND ONLY.
 *
 * Tidak ada UPDATE dan tidak ada DELETE. Ini yang membangun timeline di halaman
 * detail order, dan yang menjawab "kapan sebenarnya driver tiba" saat ada
 * sengketa.
 *
 * Kolom lat/lng terisi pada transisi yang dilakukan driver. Itu yang menjawab
 * pertanyaan yang lebih tajam: apakah driver benar-benar ada di titik jemput
 * saat menekan tombol sampai, atau masih tiga kilometer jauhnya.
 */
class OrderStatusLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'order_id',
        'from_status',
        'to_status',
        'actor_type',
        'actor_id',
        'lat',
        'lng',
        'note',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'from_status' => OrderStatus::class,
            'to_status' => OrderStatus::class,
            'lat' => 'float',
            'lng' => 'float',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function coordinate(): ?Coordinate
    {
        if ($this->lat === null || $this->lng === null) {
            return null;
        }

        return Coordinate::of($this->lat, $this->lng);
    }

    public function isSystemAction(): bool
    {
        return $this->actor_type === 'system';
    }

    /**
     * Deskripsi untuk timeline di panel admin.
     */
    public function description(): string
    {
        $actor = match ($this->actor_type) {
            'user' => 'Penumpang',
            'driver' => 'Driver',
            'admin' => 'Admin',
            'system' => 'Sistem',
            default => 'Tidak diketahui',
        };

        return "{$actor}: {$this->to_status->label()}";
    }
}
