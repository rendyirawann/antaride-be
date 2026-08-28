<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\AdminStatus;
use App\Domain\Support\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * Staf internal: ops, finance, CS, verifikator dokumen, superadmin.
 *
 * Tabel dan guard terpisah total dari `users`. Ini keputusan keamanan, bukan
 * kerapian: panel admin adalah target bernilai tinggi, karena satu akun ops
 * yang bobol bisa mengubah tarif seluruh kota atau menyetujui penarikan
 * fiktif. Dengan tabel terpisah, tidak ada satu pun jalur di alur registrasi
 * atau reset password customer yang bisa berujung pada baris di tabel ini.
 */
class Admin extends Authenticatable
{
    use HasFactory;
    use HasRoles;
    use Notifiable;

    /**
     * Spatie memakai guard default dari config('auth.defaults.guard'), yang di
     * proyek ini bernilai 'api' karena beban utamanya mobile. Tanpa properti
     * ini, role admin akan dibuat dengan guard yang salah dan pemeriksaan
     * permission diam-diam selalu gagal.
     */
    protected string $guard_name = 'admin';

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'status',
        'last_login_at',
        'last_login_ip',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'status' => AdminStatus::class,
            'password' => 'hashed',
            'last_login_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            // Secret TOTP dan recovery code dienkripsi at rest. Kalau dump
            // database bocor, 2FA tidak ikut bocor bersamanya.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
        ];
    }

    // -------------------------------------------------------------------------
    // Relasi
    // -------------------------------------------------------------------------

    public function ipAllowlist(): HasMany
    {
        return $this->hasMany(AdminIpAllowlist::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    // -------------------------------------------------------------------------
    // Scope
    // -------------------------------------------------------------------------

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', AdminStatus::Active);
    }

    // -------------------------------------------------------------------------
    // Two Factor
    // -------------------------------------------------------------------------

    /**
     * Blueprint bagian 3: admin tanpa 2FA aktif tidak boleh mengakses apa pun
     * selain halaman setup 2FA. Untuk role finance dan superadmin tidak ada
     * pengecualian sama sekali.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null
            && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Role yang wajib 2FA tanpa kecuali. Sisanya mengikuti flag global
     * ADMIN_2FA_REQUIRED.
     */
    public function requiresTwoFactor(): bool
    {
        if ($this->hasAnyRole(['super-admin', 'finance'])) {
            return true;
        }

        return (bool) config('antaride.security.two_factor_required', true);
    }

    public function canAuthenticate(): bool
    {
        return $this->status->canAuthenticate();
    }
}
