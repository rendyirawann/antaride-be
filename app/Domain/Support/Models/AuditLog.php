<?php

declare(strict_types=1);

namespace App\Domain\Support\Models;

use App\Domain\Identity\Models\Admin;
use App\Domain\Shared\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jejak audit tindakan admin. APPEND ONLY.
 *
 * Tidak ada UPDATE dan tidak ada DELETE, selamanya. Jejak audit yang bisa
 * diubah bukan jejak audit; dia hanya catatan yang kebetulan ada.
 */
class AuditLog extends Model
{
    use HasUuid;

    public const UPDATED_AT = null;

    protected $fillable = [
        'admin_id',
        'action',
        'auditable_type',
        'auditable_id',
        'old_values',
        'new_values',
        'status_code',
        'ip_address',
        'user_agent',
        'impersonated_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    // -------------------------------------------------------------------------
    // Relasi
    // -------------------------------------------------------------------------

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function impersonatedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'impersonated_by_admin_id');
    }

    /**
     * Record yang disentuh, apa pun jenisnya.
     *
     * Memakai morph map, jadi `auditable_type` menyimpan 'driver' bukan nama
     * class lengkap. Itu yang membuat jejak audit tetap terbaca setelah
     * namespace di-refactor.
     */
    public function auditable()
    {
        return $this->morphTo();
    }

    // -------------------------------------------------------------------------
    // Scope
    // -------------------------------------------------------------------------

    public function scopeForRecord(Builder $query, string $type, int $id): Builder
    {
        return $query
            ->where('auditable_type', $type)
            ->where('auditable_id', $id)
            ->orderByDesc('created_at');
    }

    public function scopeByAdmin(Builder $query, int $adminId): Builder
    {
        return $query->where('admin_id', $adminId)->orderByDesc('created_at');
    }

    /**
     * Tindakan yang dilakukan lewat sesi impersonasi.
     *
     * Ini yang pertama dilihat kalau ada dugaan penyalahgunaan akses CS.
     */
    public function scopeImpersonated(Builder $query): Builder
    {
        return $query->whereNotNull('impersonated_by_admin_id');
    }

    // -------------------------------------------------------------------------
    // Penulisan
    // -------------------------------------------------------------------------

    /**
     * Catat perubahan pada sebuah record.
     *
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    public static function record(
        string $action,
        ?Model $auditable = null,
        array $oldValues = [],
        array $newValues = [],
        ?int $adminId = null,
    ): self {
        $request = request();

        /*
         * `uuid` TIDAK dikirim di sini.
         *
         * Model ini memakai HasUuid, yang mengisinya di event `creating`.
         * Mengirimnya lewat `create()` berarti mass assignment ke kolom yang
         * tidak ada di `$fillable` — dan dengan Eloquent strict mode aktif, itu
         * melempar MassAssignmentException.
         *
         * Akibatnya paling terasa di jalur yang justru paling penting: setiap
         * pencatatan audit gagal, termasuk pencatatan upaya masuk yang gagal.
         * Dan karena pencatatannya gagal, tidak ada jejak yang menjelaskan
         * kenapa.
         */
        return self::create([
            'admin_id' => $adminId ?? auth('admin')->id(),
            'action' => $action,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'old_values' => $oldValues === [] ? null : $oldValues,
            'new_values' => $newValues === [] ? null : $newValues,
            'ip_address' => $request?->ip(),
            'user_agent' => $request === null ? null : substr((string) $request->userAgent(), 0, 500),
            'impersonated_by_admin_id' => session('impersonation.admin_id'),
        ]);
    }

    /**
     * Catat pembukaan data pribadi yang sensitif.
     *
     * Dipanggil dari lapisan cast, bukan dari controller. Alasannya ada di
     * blueprint admin bagian 3: siapa pun nanti yang menambah halaman baru yang
     * menampilkan NIK, pencatatannya ikut otomatis. Kalau diletakkan di
     * controller, halaman ke-12 pasti lupa.
     */
    public static function recordSensitiveAccess(string $field, Model $model): void
    {
        // Gagal mencatat TIDAK boleh menggagalkan pembacaan datanya, karena
        // pembacaan itu terjadi saat merender halaman yang sedang dipakai staf.
        // Yang hilang satu baris log; yang dipertahankan adalah halaman yang
        // tetap bisa dibuka.
        try {
            self::record(
                action: "sensitive.view.{$field}",
                auditable: $model,
                newValues: ['field' => $field],
            );
        } catch (\Throwable) {
            // Sengaja ditelan. Lihat alasan di atas.
        }
    }
}
