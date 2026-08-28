<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Perangkat yang dipakai seorang pengguna.
 *
 * Dipakai tiga hal: pengiriman push notification, deteksi login dari device
 * baru, dan pencabutan akses per perangkat.
 */
class UserDevice extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'device_id',
        'platform',
        'fcm_token',
        'app_version',
        'os_version',
        'device_model',
        'is_rooted',
        'last_active_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'is_rooted' => 'boolean',
            'last_active_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    /**
     * Perangkat yang punya token push dan belum dicabut.
     *
     * Dipakai saat mengirim notifikasi. Perangkat tanpa token tidak bisa
     * dikirimi apa pun, jadi tidak perlu ikut dimuat.
     */
    public function scopePushable(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->whereNotNull('fcm_token');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Perangkat root masuk daftar pantau, bukan langsung ditolak.
     *
     * Banyak pengguna sah memakai HP root. Yang menjadi sinyal kuat adalah
     * kombinasi root DAN ping lokasi palsu, dan itu diputuskan di lapisan
     * pelanggaran driver, bukan di sini.
     */
    public function needsMonitoring(): bool
    {
        return $this->is_rooted;
    }
}
