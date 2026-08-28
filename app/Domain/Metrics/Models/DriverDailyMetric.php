<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Models;

use App\Domain\Driver\Models\Driver;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Performa harian per driver.
 *
 * Dipisah dari `metrics_daily` karena pemakaiannya berbeda: yang ini dibaca satu
 * baris pada satu waktu (halaman profil driver, perhitungan insentif), bukan
 * diagregasi untuk grafik.
 *
 * Diisi job agregasi dengan pola upsert, supaya bisa dijalankan ulang tanpa
 * duplikasi. Job agregasi akan gagal di tengah jalan suatu hari, dan yang harus
 * bisa dilakukan adalah menjalankannya lagi.
 */
class DriverDailyMetric extends Model
{
    use HasFactory;

    protected $table = 'driver_daily_metrics';

    protected $fillable = [
        'date',
        'driver_id',
        'online_seconds',
        'offers_received',
        'offers_accepted',
        'orders_completed',
        'orders_cancelled',
        'gross_earning',
        'commission_paid',
        'incentive_earned',
        'net_earning',
        'distance_m',
        'rating_avg',
        'rating_count',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'online_seconds' => 'integer',
            'offers_received' => 'integer',
            'offers_accepted' => 'integer',
            'orders_completed' => 'integer',
            'orders_cancelled' => 'integer',
            'gross_earning' => 'integer',
            'commission_paid' => 'integer',
            'incentive_earned' => 'integer',
            'net_earning' => 'integer',
            'distance_m' => 'integer',
            'rating_avg' => 'string',
            'rating_count' => 'integer',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function scopeForPeriod(Builder $query, \DateTimeInterface $from, \DateTimeInterface $to): Builder
    {
        return $query->whereBetween('date', [$from, $to])->orderBy('date');
    }

    // -------------------------------------------------------------------------

    public function grossEarning(): Money
    {
        return Money::of($this->gross_earning);
    }

    public function netEarning(): Money
    {
        return Money::of($this->net_earning);
    }

    public function onlineHours(): float
    {
        return round($this->online_seconds / 3600, 2);
    }

    /**
     * Acceptance rate hari itu, sebagai persentase.
     *
     * Nol penawaran menghasilkan null, BUKAN nol persen. Driver yang tidak
     * menerima satu pun penawaran sepanjang hari tidak punya acceptance rate;
     * melaporkannya sebagai 0% akan menghukumnya karena sistem tidak pernah
     * menawarinya order.
     */
    public function acceptanceRate(): ?float
    {
        if ($this->offers_received === 0) {
            return null;
        }

        return round(($this->offers_accepted / $this->offers_received) * 100, 2);
    }

    /**
     * Pendapatan per jam online.
     *
     * Ini angka yang paling dilihat driver saat memutuskan apakah layak terus
     * bekerja di platform ini.
     */
    public function earningPerHour(): ?Money
    {
        if ($this->online_seconds < 60) {
            return null;
        }

        $hours = $this->online_seconds / 3600;

        return Money::of((int) floor($this->net_earning / $hours));
    }
}
