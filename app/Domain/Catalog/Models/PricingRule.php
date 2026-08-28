<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Shared\Concerns\HasUuid;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tarif untuk satu kombinasi layanan dan zona, berlaku pada satu rentang waktu.
 *
 * Tabel ini APPEND-MOSTLY. Tarif lama tidak pernah dihapus atau ditimpa, cukup
 * diberi `effective_until`. Kalau ada sengketa ongkos tiga bulan lalu, angka
 * yang keluar saat itu harus bisa dijelaskan, dan tarif yang ditimpa membuat
 * pertanyaan itu tidak terjawab selamanya.
 *
 * Database menegakkan bahwa tidak ada dua tarif aktif dengan periode bertumpang
 * tindih untuk pasangan (layanan, zona) yang sama, lewat exclusion constraint
 * `pricing_rules_no_overlap`. Jadi `activeAt()` di bawah selalu punya tepat satu
 * jawaban, dan itu jaminan dari database, bukan harapan.
 */
class PricingRule extends Model
{
    use HasFactory;
    use HasUuid;

    protected $fillable = [
        'service_type_id',
        'zone_id',
        'base_fare',
        'per_km',
        'per_minute',
        'minimum_fare',
        'free_distance_m',
        'platform_fee',
        'commission_percent',
        'min_fare_regulated',
        'max_fare_regulated',
        'packaging_fee',
        'insurance_fee',
        'effective_from',
        'effective_until',
        'is_active',
        'approval_request_id',
        'created_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'base_fare' => 'integer',
            'per_km' => 'integer',
            'per_minute' => 'integer',
            'minimum_fare' => 'integer',
            'free_distance_m' => 'integer',
            'platform_fee' => 'integer',
            'packaging_fee' => 'integer',
            'insurance_fee' => 'integer',
            'min_fare_regulated' => 'integer',
            'max_fare_regulated' => 'integer',
            // Persentase komisi TETAP string, bukan float.
            //
            // Ini bukan kelalaian. Nilai ini masuk ke perhitungan uang lewat
            // Money::percentage() yang bekerja dengan bcmath pada string, dan
            // cast ke float akan mengubah 17.5 jadi 17.499999999999996 sebelum
            // sampai ke sana.
            'commission_percent' => 'string',
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    // -------------------------------------------------------------------------
    // Relasi
    // -------------------------------------------------------------------------

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    // -------------------------------------------------------------------------
    // Scope
    // -------------------------------------------------------------------------

    /**
     * Tarif yang berlaku pada satu saat tertentu.
     *
     * Parameter waktunya eksplisit, bukan selalu now(). Alasannya: panel admin
     * perlu bisa menjawab "berapa tarif pada 14 Mei jam 3 pagi" saat menangani
     * keluhan, dan job rekonsiliasi perlu menghitung ulang order lama dengan
     * tarif yang berlaku saat itu.
     */
    public function scopeActiveAt(Builder $query, ?\DateTimeInterface $at = null): Builder
    {
        $at ??= now();

        return $query
            ->where('is_active', true)
            ->where('effective_from', '<=', $at)
            ->where(function (Builder $q) use ($at): void {
                $q->whereNull('effective_until')
                    ->orWhere('effective_until', '>', $at);
            });
    }

    /**
     * Tarif untuk sebuah zona, dengan fallback ke tarif default.
     *
     * Urutannya penting: tarif khusus zona harus menang atas tarif default.
     * `orderByRaw` dengan zone_id NULL di belakang yang memastikannya, dan
     * itu tidak bisa diganti `orderByDesc('zone_id')` karena PostgreSQL
     * menempatkan NULL di awal pada urutan menurun secara default.
     */
    public function scopeForZone(Builder $query, ?int $zoneId): Builder
    {
        return $query
            ->where(function (Builder $q) use ($zoneId): void {
                $q->whereNull('zone_id');

                if ($zoneId !== null) {
                    $q->orWhere('zone_id', $zoneId);
                }
            })
            ->orderByRaw('zone_id IS NULL')
            ->orderByDesc('effective_from');
    }

    // -------------------------------------------------------------------------
    // Nilai uang
    // -------------------------------------------------------------------------

    public function baseFare(): Money
    {
        return Money::of($this->base_fare);
    }

    public function perKm(): Money
    {
        return Money::of($this->per_km);
    }

    public function perMinute(): Money
    {
        return Money::of($this->per_minute);
    }

    public function minimumFare(): Money
    {
        return Money::of($this->minimum_fare);
    }

    public function platformFee(): Money
    {
        return Money::of($this->platform_fee);
    }

    public function minFareRegulated(): ?Money
    {
        return $this->min_fare_regulated === null ? null : Money::of($this->min_fare_regulated);
    }

    public function maxFareRegulated(): ?Money
    {
        return $this->max_fare_regulated === null ? null : Money::of($this->max_fare_regulated);
    }

    public function isDefault(): bool
    {
        return $this->zone_id === null;
    }
}
