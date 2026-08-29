<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Driver\Models\Driver;
use App\Domain\Identity\Enums\Gender;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Merchant\Models\Merchant;
use App\Domain\Ordering\Models\Order;
use App\Domain\Shared\Concerns\HasUuid;
use App\Domain\Wallet\Models\Wallet;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Aktor eksternal: customer, driver, dan merchant owner semuanya punya baris
 * di sini. Perannya TIDAK ditentukan oleh kolom di tabel ini, tapi oleh
 * keberadaan baris di `drivers` / `merchants`.
 *
 * Admin TIDAK PERNAH punya baris di tabel ini. Guard, tabel, dan session-nya
 * terpisah total (lihat App\Domain\Identity\Models\Admin). Itu yang menutup
 * kemungkinan seorang customer memanjat jadi admin lewat celah di alur
 * registrasi atau reset password.
 */
#[UseFactory(UserFactory::class)]
class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use HasUuid;
    use Notifiable;

    protected $fillable = [
        'phone',
        'email',
        'name',
        'password',
        'photo_url',
        'gender',
        'birth_date',
        'status',
        'phone_verified_at',
        'referral_code',
        'referred_by_user_id',

        // Akun demo. Hanya diisi seeder — tidak ada jalur di API yang
        // menjadikan akun sungguhan sebagai akun demo, dan itu disengaja.
        'demo_role',
        'demo_order',
        'demo_note',
    ];

    /**
     * `password` bisa null karena login utama memakai OTP nomor HP. Yang punya
     * password hanya user yang mendaftar lewat jalur lain.
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'status' => UserStatus::class,
            'gender' => Gender::class,
            'birth_date' => 'date',
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    // -------------------------------------------------------------------------
    // Relasi
    // -------------------------------------------------------------------------

    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class);
    }

    public function driver(): HasOne
    {
        return $this->hasOne(Driver::class);
    }

    public function ownedMerchants(): HasMany
    {
        return $this->hasMany(Merchant::class, 'owner_user_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(UserAddress::class);
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'referred_by_user_id');
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(self::class, 'referred_by_user_id');
    }

    /**
     * Wallet bersifat polimorfik karena user, driver, merchant, dan platform
     * memakai satu tabel wallet yang sama.
     */
    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class, 'owner_id')
            ->where('owner_type', 'user');
    }

    // -------------------------------------------------------------------------
    // Scope
    // -------------------------------------------------------------------------

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', UserStatus::Active);
    }

    /**
     * Pencarian nama atau nomor HP memakai GIN trigram index (pg_trgm), jadi
     * ILIKE '%kata%' tetap terindeks. Tanpa index itu, pencarian di tabel user
     * berjuta baris akan memindai seluruh tabel setiap kali CS menerima
     * telepon.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $term = trim($term);

        return $query->where(function (Builder $q) use ($term): void {
            $q->where('name', 'ILIKE', "%{$term}%")
                ->orWhere('phone', 'ILIKE', "%{$term}%")
                ->orWhere('email', 'ILIKE', "%{$term}%");
        });
    }

    // -------------------------------------------------------------------------
    // Perilaku
    // -------------------------------------------------------------------------

    public function isDriver(): bool
    {
        return $this->driver()->exists();
    }

    public function hasVerifiedPhone(): bool
    {
        return $this->phone_verified_at !== null;
    }

    public function canAuthenticate(): bool
    {
        return $this->status->canAuthenticate();
    }
}
