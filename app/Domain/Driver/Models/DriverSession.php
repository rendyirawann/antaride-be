<?php

declare(strict_types=1);

namespace App\Domain\Driver\Models;

use App\Domain\Shared\ValueObjects\Coordinate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu periode online driver.
 *
 * Dipakai menghitung jam kerja dan insentif. Satu driver hanya boleh punya satu
 * sesi terbuka, ditegakkan partial unique index `driver_sessions_one_open`.
 */
class DriverSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'driver_id',
        'vehicle_id',
        'started_at',
        'ended_at',
        'online_seconds',
        'orders_taken',
        'orders_completed',
        'start_lat',
        'start_lng',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'online_seconds' => 'integer',
            'orders_taken' => 'integer',
            'orders_completed' => 'integer',
            'start_lat' => 'float',
            'start_lng' => 'float',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * Durasi sesi dalam detik.
     *
     * Untuk sesi yang masih terbuka, dihitung sampai sekarang. Untuk sesi yang
     * sudah ditutup, memakai kolom `online_seconds` yang dibekukan saat penutupan.
     *
     * Kolom itu ada karena menghitung ulang dari selisih cap waktu akan salah
     * kalau sesinya pernah terputus dan disambung: driver yang kehilangan
     * sinyal 40 menit tidak sedang bekerja selama itu, dan menghitungnya sebagai
     * jam kerja membuat perhitungan insentif bisa dieksploitasi.
     */
    public function durationSeconds(): int
    {
        if ($this->ended_at !== null) {
            return $this->online_seconds;
        }

        return max(0, (int) floor($this->started_at->diffInSeconds(now(), absolute: true)));
    }

    public function startCoordinate(): ?Coordinate
    {
        if ($this->start_lat === null || $this->start_lng === null) {
            return null;
        }

        return Coordinate::of($this->start_lat, $this->start_lng);
    }
}
