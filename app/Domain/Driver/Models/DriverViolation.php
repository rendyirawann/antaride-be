<?php

declare(strict_types=1);

namespace App\Domain\Driver\Models;

use App\Domain\Identity\Models\Admin;
use App\Domain\Ordering\Models\Order;
use App\Domain\Shared\Concerns\HasUuid;
use App\Domain\Shared\Support\BusinessClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catatan pelanggaran driver: GPS palsu, cancel berlebihan, rating rendah.
 *
 * Dipisah dari `audit_logs` karena ini bagian dari berkas driver yang dibaca tim
 * ops saat memutuskan suspend, bukan jejak tindakan admin. Yang satu menjawab
 * "apa yang dilakukan driver ini", yang lain "apa yang dilakukan staf ini".
 */
class DriverViolation extends Model
{
    use HasFactory;
    use HasUuid;

    protected $fillable = [
        'driver_id',
        'type',
        'severity',
        'description',
        'evidence',
        'order_id',
        'recorded_by_admin_id',
        'action_taken',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'resolved_at' => 'datetime',
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

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'recorded_by_admin_id');
    }

    // -------------------------------------------------------------------------

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Pelanggaran GPS palsu hari ini.
     *
     * Ambangnya lima per hari untuk auto suspend (blueprint bagian 5). Dihitung
     * per hari, bukan kumulatif, karena driver yang pernah punya masalah GPS
     * enam bulan lalu tidak sedang memalsukan lokasi sekarang.
     */
    public function scopeMockGpsToday(Builder $query): Builder
    {
        return $query
            ->where('type', 'mock_gps')
            // Tengah malam ZONA BISNIS, bukan tengah malam UTC. now()->startOfDay()
            // memberi 00:00 UTC yang berarti jam 7 pagi WIB, sehingga pelanggaran
            // antara tengah malam dan jam 7 pagi tidak terhitung sebagai hari ini.
            ->where('created_at', '>=', BusinessClock::startOfToday());
    }

    public function isCritical(): bool
    {
        return $this->severity === 'critical';
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    public function label(): string
    {
        return match ($this->type) {
            'mock_gps' => 'Lokasi palsu',
            'excessive_cancel' => 'Terlalu sering membatalkan',
            'low_rating' => 'Rating rendah',
            'speeding' => 'Melebihi batas kecepatan',
            'route_deviation' => 'Menyimpang dari rute',
            'fare_dispute' => 'Sengketa ongkos',
            'customer_complaint' => 'Keluhan pelanggan',
            'document_expired' => 'Dokumen kadaluarsa',
            'rooted_device' => 'Perangkat di-root',
            default => 'Lainnya',
        };
    }
}
