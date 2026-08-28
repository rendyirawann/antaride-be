<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Models;

use App\Domain\Driver\Models\Driver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riwayat penawaran order ke driver.
 *
 * Ini tabel yang jarang dibangun orang, dan justru yang paling menjawab
 * pertanyaan "kenapa penumpang saya menunggu 8 menit". Tanpa dia, tidak ada
 * cara mengetahui siapa yang ditawari, siapa menolak, dan siapa yang membiarkan
 * penawaran kadaluarsa.
 *
 * `score_breakdown` menyimpan rincian skornya. Saat driver mengeluh "kenapa
 * saya tidak pernah dapat order", ini yang menjawabnya dengan angka alih-alih
 * dugaan.
 */
class OrderOffer extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'driver_id',
        'wave',
        'distance_to_pickup_m',
        'score',
        'score_breakdown',
        'offered_at',
        'expires_at',
        'response',
        'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'wave' => 'integer',
            'distance_to_pickup_m' => 'integer',
            'score' => 'string',
            'score_breakdown' => 'array',
            'offered_at' => 'datetime',
            'expires_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    // -------------------------------------------------------------------------

    public function scopePending(Builder $query): Builder
    {
        return $query->where('response', 'pending');
    }

    /**
     * Penawaran yang sudah lewat batas waktu tapi belum ditandai timeout.
     *
     * Dipakai job pembersih. Penawaran yang menggantung membuat perhitungan
     * acceptance_rate driver salah: dia tidak menolak, tapi juga tidak menerima.
     */
    public function scopeExpiredUnresolved(Builder $query): Builder
    {
        return $query
            ->where('response', 'pending')
            ->where('expires_at', '<=', now());
    }

    public function scopeAccepted(Builder $query): Builder
    {
        return $query->where('response', 'accepted');
    }

    // -------------------------------------------------------------------------

    public function isPending(): bool
    {
        return $this->response === 'pending';
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Apakah penawaran ini masih bisa diterima.
     *
     * Diperiksa sebelum lock Redis diambil, sebagai penolakan murah untuk
     * penawaran yang jelas sudah kadaluarsa.
     */
    public function isAcceptable(): bool
    {
        return $this->isPending() && ! $this->isExpired();
    }

    /**
     * Berapa lama driver merespons, dalam detik.
     *
     * Metrik ini berguna untuk mengukur apakah TTL penawaran 15 detik sudah
     * tepat: kalau mayoritas driver merespons di detik ke-14, TTL-nya terlalu
     * pendek dan banyak penawaran hilang sia-sia.
     */
    public function responseSeconds(): ?int
    {
        if ($this->responded_at === null) {
            return null;
        }

        return max(0, (int) floor($this->offered_at->diffInSeconds($this->responded_at, absolute: true)));
    }

    public function label(): string
    {
        return match ($this->response) {
            'pending' => 'Menunggu jawaban',
            'accepted' => 'Diterima',
            'rejected' => 'Ditolak',
            'timeout' => 'Tidak dijawab',
            'cancelled' => 'Dibatalkan sistem',
            default => 'Tidak diketahui',
        };
    }
}
