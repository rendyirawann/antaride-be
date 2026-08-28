<?php

declare(strict_types=1);

namespace App\Domain\Merchant\Models;

use App\Domain\Catalog\Models\Zone;
use App\Domain\Identity\Models\Admin;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Concerns\HasUuid;
use App\Domain\Shared\Support\BusinessClock;
use App\Domain\Shared\ValueObjects\Coordinate;
use App\Domain\Wallet\Models\Wallet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Merchant: restoran, warung, toko.
 *
 * Dua flag yang sengaja dipisah dan sering keliru disatukan:
 *
 *   status = active   hasil verifikasi admin
 *   is_open = true    toggle manual oleh merchant, untuk tutup dadakan
 *
 * Merchant yang belum diverifikasi tidak boleh menerima order walaupun dia
 * menekan tombol buka. Sebaliknya, merchant terverifikasi yang sedang tutup
 * karena kehabisan bahan tetap terverifikasi.
 */
class Merchant extends Model
{
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected $fillable = [
        'owner_user_id',
        'category_id',
        'name',
        'slug',
        'description',
        'address',
        'lat',
        'lng',
        'zone_id',
        'phone',
        'photo_url',
        'banner_url',
        'status',
        'is_open',
        'commission_percent',
        'prep_time_minutes',
        'bank_name',
        'bank_account_number',
        'bank_account_name',
        'verified_at',
        'verified_by_admin_id',
        'rejection_note',
    ];

    protected $hidden = ['bank_account_number'];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'is_open' => 'boolean',
            'bank_account_number' => 'encrypted',
            // Persentase komisi tetap string, supaya tidak melewati float saat
            // masuk perhitungan uang lewat Money::percentage().
            'commission_percent' => 'string',
            'rating_avg' => 'string',
            'rating_count' => 'integer',
            'prep_time_minutes' => 'integer',
            'verified_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    // -------------------------------------------------------------------------
    // Relasi
    // -------------------------------------------------------------------------

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(MerchantCategory::class, 'category_id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'verified_by_admin_id');
    }

    public function staff(): HasMany
    {
        return $this->hasMany(MerchantStaff::class);
    }

    public function operatingHours(): HasMany
    {
        return $this->hasMany(MerchantOperatingHour::class)->orderBy('day_of_week');
    }

    public function menuCategories(): HasMany
    {
        return $this->hasMany(MenuCategory::class)->orderBy('sort_order');
    }

    public function menuItems(): HasMany
    {
        return $this->hasMany(MenuItem::class);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class, 'owner_id')
            ->where('owner_type', 'merchant');
    }

    // -------------------------------------------------------------------------
    // Scope
    // -------------------------------------------------------------------------

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * Merchant yang benar-benar bisa menerima order sekarang.
     *
     * Ini query yang dipanggil setiap kali penumpang membuka halaman food, jadi
     * partial index `merchants_orderable` dibuat persis untuk pola ini.
     *
     * Jam operasional TIDAK diperiksa di sini. Alasannya: pemeriksaan jam
     * membutuhkan join ke tabel jam operasional dengan perbandingan waktu, dan
     * itu membuat index-nya tidak terpakai. Yang dilakukan adalah memeriksanya
     * di sisi aplikasi setelah daftar dimuat, atau lewat method isOpenNow().
     */
    public function scopeOrderable(Builder $query): Builder
    {
        return $query
            ->where('status', 'active')
            ->where('is_open', true)
            ->whereNull('deleted_at');
    }

    public function scopeAwaitingReview(Builder $query): Builder
    {
        return $query->where('status', 'pending_review')->orderBy('created_at');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where('name', 'ILIKE', '%'.trim($term).'%');
    }

    // -------------------------------------------------------------------------
    // Perilaku
    // -------------------------------------------------------------------------

    public function coordinate(): Coordinate
    {
        return Coordinate::of($this->lat, $this->lng);
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null && $this->status === 'active';
    }

    /**
     * Apakah merchant bisa menerima order sekarang.
     *
     * Tiga syarat: terverifikasi, tombol buka dinyalakan, DAN sedang dalam jam
     * operasional. Ketiganya harus dipenuhi.
     */
    public function canReceiveOrders(?\DateTimeInterface $at = null): bool
    {
        return $this->isVerified()
            && $this->is_open
            && $this->isWithinOperatingHours($at);
    }

    /**
     * Apakah saat ini dalam jam operasional.
     *
     * Relasi jam operasional harus sudah dimuat; kalau belum, dianggap buka.
     * Ini keputusan sadar: strict mode Eloquent akan melempar exception untuk
     * lazy loading, dan menggagalkan seluruh halaman daftar merchant hanya
     * karena relasi jam belum di-eager-load adalah kegagalan yang tidak
     * sepadan.
     */
    public function isWithinOperatingHours(?\DateTimeInterface $at = null): bool
    {
        if (! $this->relationLoaded('operatingHours')) {
            return true;
        }

        // Zona bisnis, supaya dayOfWeek-nya hari WIB. Tengah malam WIB
        // adalah jam 5 sore UTC hari sebelumnya, jadi tanpa konversi ini hari
        // yang diperiksa bisa bergeser satu hari untuk order malam.
        $moment = BusinessClock::at($at);

        $today = $this->operatingHours
            ->firstWhere('day_of_week', $moment->dayOfWeek);

        return $today?->covers($moment) ?? false;
    }

    /**
     * Perkiraan menit sampai pesanan siap diambil driver.
     */
    public function estimatedReadyMinutes(): int
    {
        return max(1, $this->prep_time_minutes);
    }
}
