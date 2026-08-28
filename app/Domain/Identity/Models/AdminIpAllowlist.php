<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Daftar IP atau CIDR yang diizinkan untuk seorang admin.
 *
 * Berlaku untuk role finance dan superadmin (blueprint admin bagian 3). Ini
 * merepotkan, dan itu memang tujuannya: kredensial yang bocor lewat phishing
 * tidak akan bisa dipakai dari luar jaringan yang dikenal.
 */
class AdminIpAllowlist extends Model
{
    use HasFactory;

    protected $table = 'admin_ip_allowlist';

    protected $fillable = [
        'admin_id',
        'cidr',
        'label',
        'is_active',
        'created_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isCidrRange(): bool
    {
        return str_contains($this->cidr, '/');
    }
}
