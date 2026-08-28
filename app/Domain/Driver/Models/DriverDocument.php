<?php

declare(strict_types=1);

namespace App\Domain\Driver\Models;

use App\Domain\Identity\Models\Admin;
use App\Domain\Shared\Concerns\HasUuid;
use App\Domain\Shared\Support\BusinessClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Dokumen KYC driver: KTP, SIM, STNK, SKCK, selfie, buku rekening.
 *
 * File TIDAK disimpan di disk publik. Yang disimpan di kolom adalah path di
 * disk privat, dan aksesnya hanya lewat signed URL berumur pendek yang
 * diterbitkan setelah pemeriksaan permission.
 */
class DriverDocument extends Model
{
    use HasFactory;
    use HasUuid;

    protected $fillable = [
        'driver_id',
        'type',
        'file_path',
        'file_hash',
        'number',
        'expires_at',
        'status',
        'reject_reason',
        'reviewed_by_admin_id',
        'reviewed_at',
    ];

    protected $hidden = ['file_path', 'number'];

    protected function casts(): array
    {
        return [
            'number' => 'encrypted',
            'expires_at' => 'date',
            'reviewed_at' => 'datetime',
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

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewed_by_admin_id');
    }

    // -------------------------------------------------------------------------

    public function scopeAwaitingReview(Builder $query): Builder
    {
        return $query->where('status', 'pending')->orderBy('created_at');
    }

    /**
     * Dokumen yang akan kadaluarsa dalam sekian hari.
     *
     * SIM atau STNK yang habis masa berlakunya membuat driver tidak sah
     * beroperasi, dan menemukannya setelah ada tilang sudah terlambat.
     */
    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        return $query
            ->where('status', 'approved')
            ->whereNotNull('expires_at')
            // Tanggal ZONA BISNIS. Kolom expires_at bertipe DATE dan berisi
            // tanggal kadaluarsa dokumen apa adanya, jadi pembandingnya juga
            // harus tanggal WIB. Dengan tanggal UTC, antara tengah malam dan
            // jam 7 pagi WIB batasnya bergeser satu hari.
            ->whereBetween('expires_at', [
                BusinessClock::date(),
                BusinessClock::at()->addDays($days)->format('Y-m-d'),
            ])
            ->orderBy('expires_at');
    }

    // -------------------------------------------------------------------------

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * URL bertanda tangan untuk melihat dokumen, berumur pendek.
     *
     * Lima menit cukup untuk membukanya di tab baru, dan terlalu pendek untuk
     * dibagikan lewat WhatsApp lalu masih bisa dibuka besok.
     */
    public function temporaryUrl(int $minutes = 5): ?string
    {
        if ($this->file_path === null) {
            return null;
        }

        return Storage::disk(config('antaride.storage.kyc_disk', 'kyc'))
            ->temporaryUrl($this->file_path, now()->addMinutes($minutes));
    }

    public function label(): string
    {
        return match ($this->type) {
            'ktp' => 'KTP',
            'sim' => 'SIM',
            'stnk' => 'STNK',
            'skck' => 'SKCK',
            'selfie' => 'Foto selfie',
            'bank_book' => 'Buku rekening',
            'vaccine' => 'Sertifikat vaksin',
            default => ucfirst((string) $this->type),
        };
    }
}
