<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Shared\Concerns\HasUuid;
use App\Domain\Shared\Support\BusinessClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aturan surge untuk satu kombinasi zona dan layanan.
 *
 * Tiga jenis pemicu, dengan sifat yang berbeda:
 *
 *   schedule      jadwal tetap, misal jam pulang kerja Senin-Jumat
 *   demand_ratio  otomatis, saat rasio order:driver melewati ambang
 *   manual        tombol darurat tim ops, berbatas waktu
 */
class SurgeRule extends Model
{
    use HasFactory;
    use HasUuid;

    protected $fillable = [
        'zone_id',
        'service_type_id',
        'trigger_type',
        'day_of_week',
        'start_time',
        'end_time',
        'multiplier',
        'demand_threshold',
        'manual_until',
        'is_active',
        'created_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            // Pengali dan ambang TETAP string, bukan float.
            //
            // Pengali masuk ke perhitungan uang lewat Money::scaledBy() yang
            // bekerja dengan bcmath pada string. Cast ke float akan mengubah
            // 1.3 jadi 1.2999999999999998 sebelum sampai ke sana, dan satu
            // rupiah hilang di setiap order jam sibuk.
            'multiplier' => 'string',
            'demand_threshold' => 'string',
            'manual_until' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    // -------------------------------------------------------------------------

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    // -------------------------------------------------------------------------

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForZoneAndService(Builder $query, int $zoneId, int $serviceTypeId): Builder
    {
        return $query
            ->where('zone_id', $zoneId)
            ->where('service_type_id', $serviceTypeId);
    }

    // -------------------------------------------------------------------------

    public function isManual(): bool
    {
        return $this->trigger_type === 'manual';
    }

    public function isSchedule(): bool
    {
        return $this->trigger_type === 'schedule';
    }

    public function isDemandRatio(): bool
    {
        return $this->trigger_type === 'demand_ratio';
    }

    /**
     * Apakah surge manual ini masih berlaku.
     *
     * Surge manual WAJIB punya batas waktu. Tanpa itu, tombol darurat yang
     * dinyalakan saat banjir akan tetap menyala tiga hari kemudian, dan yang
     * terjadi adalah penumpang membayar 1,5 kali lipat tanpa sebab sementara
     * tidak ada satu pun orang yang ingat menyalakannya.
     */
    public function isManualStillValid(?\DateTimeInterface $at = null): bool
    {
        if (! $this->isManual()) {
            return false;
        }

        if ($this->manual_until === null) {
            return false;
        }

        return $this->manual_until->isAfter($at ?? now());
    }

    /**
     * Apakah jadwal ini mencakup satu saat tertentu.
     *
     * `day_of_week` NULL berarti berlaku setiap hari.
     *
     * Rentang jam yang melewati tengah malam ditangani: jadwal 22:00 sampai
     * 02:00 mencakup jam 23:00 dan jam 01:00. Tanpa penanganan ini, surge
     * tengah malam tidak akan pernah aktif dan tidak ada yang menyadarinya
     * karena tidak ada error apa pun.
     *
     * ========================================================================
     *  JAM DIBANDINGKAN DALAM ZONA BISNIS, BUKAN UTC
     * ========================================================================
     *  Kolom start_time dan end_time menyimpan jam bisnis apa adanya: 17:00
     *  berarti jam 5 sore WIB. Aplikasi berjalan di UTC, jadi waktu yang masuk
     *  ke method ini WAJIB dikonversi lebih dulu.
     *
     *  Versi pertama method ini membandingkan langsung pada waktu UTC.
     *  Akibatnya sudah diuji dan terbukti: pada 17:30 WIB, yang merupakan jam
     *  pulang kerja sungguhan, method ini mengembalikan false karena UTC-nya
     *  10:30. Surge jam sibuk tidak akan pernah menyala, dan yang menyala
     *  justru sekitar tengah malam.
     *
     *  Tidak ada satu pun error yang muncul untuk kesalahan itu.
     * ========================================================================
     */
    public function scheduleCovers(\DateTimeInterface $at): bool
    {
        if (! $this->isSchedule()) {
            return false;
        }

        if ($this->start_time === null || $this->end_time === null) {
            return false;
        }

        // Konversi ke zona bisnis SEBELUM apa pun dibandingkan.
        $moment = BusinessClock::at($at);

        $start = substr((string) $this->start_time, 0, 8);
        $end = substr((string) $this->end_time, 0, 8);
        $now = $moment->format('H:i:s');

        $crossesMidnight = $start > $end;

        if ($this->day_of_week !== null) {
            // Untuk jadwal yang melewati tengah malam, hari yang diperiksa
            // adalah hari saat jadwal MULAI. Jadwal Jumat 22:00-02:00 berlaku
            // sampai Sabtu jam 2 pagi, dan pemeriksaannya harus mengizinkan itu.
            $dayMatches = $crossesMidnight
                ? ($moment->dayOfWeek === $this->day_of_week && $now >= $start)
                    || ($moment->copy()->subDay()->dayOfWeek === $this->day_of_week && $now < $end)
                : $moment->dayOfWeek === $this->day_of_week;

            if (! $dayMatches) {
                return false;
            }
        }

        return $crossesMidnight
            ? ($now >= $start || $now < $end)
            : ($now >= $start && $now < $end);
    }

    /**
     * Apakah rasio permintaan-pasokan sudah melewati ambang aturan ini.
     */
    public function demandThresholdReached(string $ratio): bool
    {
        if (! $this->isDemandRatio() || $this->demand_threshold === null) {
            return false;
        }

        return bccomp($ratio, (string) $this->demand_threshold, 4) >= 0;
    }
}
