<?php

declare(strict_types=1);

namespace App\Domain\Driver\Models;

use App\Domain\Driver\Enums\DriverStatus;
use App\Domain\Identity\Models\Admin;
use App\Domain\Identity\Models\User;
use App\Domain\Metrics\Models\DriverDailyMetric;
use App\Domain\Ordering\Models\Order;
use App\Domain\Shared\Concerns\HasUuid;
use App\Domain\Support\Models\AuditLog;
use App\Domain\Wallet\Models\Wallet;
use Database\Factories\DriverFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Profil driver.
 *
 * Selalu punya pasangan baris di `users`. Pemisahan ini penting karena satu
 * orang bisa jadi penumpang dan driver sekaligus, dan riwayat order-nya sebagai
 * penumpang tidak boleh tercampur dengan riwayatnya sebagai driver.
 *
 * NIK dan nomor dokumen dienkripsi. Yang bisa melihat penuh hanya role dengan
 * permission `kyc.view_full`, dan setiap pembukaannya dicatat di audit log.
 */
#[UseFactory(DriverFactory::class)]
class Driver extends Model
{
    use HasFactory;
    use HasUuid;

    protected $fillable = [
        'user_id',
        'nik',
        'nik_hash',
        'full_name',
        'address',
        'city',
        'emergency_contact_name',
        'emergency_contact_phone',
        'status',
        'rejection_note',
        'verified_at',
        'verified_by_admin_id',
        'joined_at',
    ];

    /**
     * NIK tidak pernah ikut serialisasi otomatis.
     *
     * Kalau ikut, satu `return response()->json($driver)` di endpoint mana pun
     * akan membocorkan NIK seluruh driver. Yang butuh menampilkannya harus
     * memintanya secara eksplisit lewat accessor yang memeriksa permission.
     */
    protected $hidden = ['nik', 'nik_hash'];

    protected function casts(): array
    {
        return [
            'status' => DriverStatus::class,
            'nik' => 'encrypted',
            'verified_at' => 'datetime',
            'joined_at' => 'datetime',
            // Metrik performa. Disimpan sebagai kolom, bukan dihitung saat
            // dibutuhkan, karena matching memanggilnya untuk setiap kandidat
            // pada setiap order.
            'rating_avg' => 'string',
            'acceptance_rate' => 'string',
            'cancellation_rate' => 'string',
            'rating_count' => 'integer',
            'completed_orders' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    // -------------------------------------------------------------------------
    // Relasi
    // -------------------------------------------------------------------------

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'verified_by_admin_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(DriverDocument::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    /**
     * Kendaraan yang sedang aktif.
     *
     * Satu plat hanya boleh terdaftar pada satu kendaraan aktif, ditegakkan
     * partial unique index `vehicles_plate_active_unique`.
     */
    public function activeVehicle(): HasOne
    {
        return $this->hasOne(Vehicle::class)->where('is_active', true);
    }

    public function serviceEligibility(): HasMany
    {
        return $this->hasMany(DriverServiceEligibility::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(DriverSession::class);
    }

    /**
     * Sesi online yang sedang berjalan.
     *
     * Satu driver hanya boleh punya satu sesi terbuka, ditegakkan partial
     * unique index `driver_sessions_one_open`.
     */
    public function openSession(): HasOne
    {
        return $this->hasOne(DriverSession::class)->whereNull('ended_at');
    }

    public function violations(): HasMany
    {
        return $this->hasMany(DriverViolation::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class, 'owner_id')
            ->where('owner_type', 'driver');
    }

    public function dailyMetrics(): HasMany
    {
        return $this->hasMany(DriverDailyMetric::class);
    }

    // -------------------------------------------------------------------------
    // Scope
    // -------------------------------------------------------------------------

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', DriverStatus::Active);
    }

    /**
     * Antrean verifikasi dokumen, terlama dulu.
     *
     * Terlama dulu, bukan terbaru. Driver yang sudah menunggu tiga hari harus
     * diperiksa sebelum yang mendaftar sepuluh menit lalu, kalau tidak yang
     * menunggu paling lama akan menunggu selamanya.
     */
    public function scopeAwaitingReview(Builder $query): Builder
    {
        return $query
            ->where('status', DriverStatus::PendingReview)
            ->orderBy('created_at');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $term = trim($term);

        return $query->where(function (Builder $q) use ($term): void {
            $q->where('full_name', 'ILIKE', "%{$term}%")
                ->orWhereHas('user', fn (Builder $u) => $u->where('phone', 'ILIKE', "%{$term}%"));
        });
    }

    // -------------------------------------------------------------------------
    // Perilaku
    // -------------------------------------------------------------------------

    public function canOperate(): bool
    {
        return $this->status->canOperate();
    }

    /**
     * Apakah driver ini boleh menerima order tunai.
     *
     * Driver yang menerima order tunai memegang uang platform, jadi wajib punya
     * saldo deposit minimum. Di bawah ambang, dia hanya boleh menerima order
     * non-tunai.
     *
     * Diperiksa di filter matching, bukan saat driver menekan terima. Kalau
     * diperiksa saat terima, driver akan melihat penawaran lalu ditolak
     * sistemnya sendiri, dan itu terasa seperti aplikasi yang rusak.
     */
    public function canAcceptCashOrders(): bool
    {
        $minimum = (int) config('antaride.wallet.driver_cash_deposit_minimum', 20000);
        $balance = $this->wallet?->balance ?? 0;

        return $balance >= $minimum;
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * NIK tersamarkan: empat digit awal, empat digit akhir.
     *
     * Ini yang dilihat sebagian besar role. Yang butuh penuh harus lewat
     * `nikFull()` yang memeriksa permission dan mencatat pembukaannya.
     */
    public function nikMasked(): ?string
    {
        if ($this->nik === null) {
            return null;
        }

        $config = config('antaride.masking.nik');
        $nik = (string) $this->nik;
        $length = strlen($nik);
        $prefix = (int) $config['prefix'];
        $suffix = (int) $config['suffix'];

        if ($length <= $prefix + $suffix) {
            return str_repeat('*', $length);
        }

        return substr($nik, 0, $prefix)
            .str_repeat('*', $length - $prefix - $suffix)
            .substr($nik, -$suffix);
    }

    /**
     * NIK penuh, hanya untuk yang punya permission, dan pembukaannya dicatat.
     */
    public function nikFull(): ?string
    {
        if ($this->nik === null) {
            return null;
        }

        if (auth('admin')->user()?->can('kyc.view_full') !== true) {
            return $this->nikMasked();
        }

        AuditLog::recordSensitiveAccess('nik', $this);

        return (string) $this->nik;
    }

    /**
     * Hash NIK untuk mendeteksi pendaftaran ganda tanpa mendekripsi.
     *
     * Tanpa ini, memeriksa "apakah NIK ini sudah terdaftar" berarti mendekripsi
     * setiap baris di tabel drivers.
     */
    public static function hashNik(string $nik): string
    {
        return hash_hmac('sha256', preg_replace('/\D+/', '', $nik) ?? '', (string) config('app.key'));
    }
}
