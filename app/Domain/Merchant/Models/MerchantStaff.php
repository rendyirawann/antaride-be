<?php

declare(strict_types=1);

namespace App\Domain\Merchant\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Staf merchant.
 *
 * Pemisahan wewenangnya penting: pemilik warung biasanya memberi akses ke
 * karyawan, dan akses itu TIDAK boleh membawa kemampuan memindahkan uang.
 * Staf bisa menerima order dan mengubah ketersediaan menu; hanya owner yang
 * bisa melihat keuangan dan menarik saldo.
 */
class MerchantStaff extends Model
{
    use HasFactory;

    protected $table = 'merchant_staff';

    protected $fillable = ['merchant_id', 'user_id', 'role', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    /**
     * Apakah staf ini boleh melihat data keuangan merchant.
     *
     * Hanya owner dan manager. Staf biasa tidak, karena dia sering karyawan
     * harian yang tidak seharusnya tahu omzet.
     */
    public function canViewFinance(): bool
    {
        return in_array($this->role, ['owner', 'manager'], true);
    }

    /**
     * Apakah staf ini boleh menarik saldo merchant.
     *
     * HANYA owner. Manager pun tidak, karena penarikan adalah pemindahan uang
     * keluar dan itu wewenang pemilik.
     */
    public function canWithdraw(): bool
    {
        return $this->isOwner();
    }
}
