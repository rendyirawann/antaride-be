<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Models;

use App\Domain\Approval\Actions\ResolveApprovalThreshold;
use App\Domain\Identity\Models\Admin;
use App\Domain\Shared\Concerns\HasUuid;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Support\Models\AuditLog;
use App\Domain\Support\Models\FeatureFlag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penarikan saldo ke rekening bank.
 *
 * Ini jalur yang paling mungkin dipakai untuk fraud internal, jadi lapisannya
 * paling banyak:
 *
 *   1. Ambang approval berdasarkan nominal (`approval_thresholds`)
 *   2. Pengaju tidak boleh jadi penyetuju (CHECK constraint)
 *   3. Re-autentikasi password sebelum menyetujui
 *   4. Allowlist IP untuk role finance
 *   5. Setiap tindakan masuk audit log
 *
 * Nomor rekening dienkripsi dan di-masking secara default. Yang bisa melihat
 * penuh hanya role finance, dan pembukaannya dicatat.
 */
class Withdrawal extends Model
{
    use HasFactory;
    use HasUuid;

    protected $fillable = [
        'wallet_id',
        'amount',
        'fee',
        'net_amount',
        'bank_name',
        'bank_account_number',
        'bank_account_name',
        'status',
        'provider',
        'provider_ref',
        'approved_by_admin_id',
        'approved_at',
        'approval_request_id',
        'completed_at',
        'failure_reason',
        'raw_callback',
        'idempotency_key',
    ];

    protected $hidden = ['bank_account_number', 'idempotency_key', 'raw_callback'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'fee' => 'integer',
            'net_amount' => 'integer',
            'bank_account_number' => 'encrypted',
            'raw_callback' => 'array',
            'approved_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    // -------------------------------------------------------------------------

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'approved_by_admin_id');
    }

    // -------------------------------------------------------------------------

    /**
     * Antrean kerja tim finance, terlama dulu.
     *
     * Terlama dulu, bukan nominal terbesar dulu. Driver yang menunggu
     * penarikannya tiga hari punya urusan yang lebih mendesak daripada yang
     * mengajukan sepuluh menit lalu, sebesar apa pun nominalnya.
     */
    public function scopeAwaitingApproval(Builder $query): Builder
    {
        return $query
            ->whereIn('status', ['requested', 'reviewing'])
            ->orderBy('created_at');
    }

    /**
     * Penarikan yang sudah disetujui tapi belum selesai dikirim.
     *
     * Dipakai job polling ke provider disbursement. Penarikan yang menggantung
     * di status ini adalah uang yang sudah dipotong dari saldo driver tapi belum
     * sampai ke rekeningnya, dan itu yang paling cepat memicu keluhan.
     */
    public function scopeInFlight(Builder $query): Builder
    {
        return $query->whereIn('status', ['approved', 'processing']);
    }

    // -------------------------------------------------------------------------

    public function amount(): Money
    {
        return Money::of($this->amount);
    }

    public function netAmount(): Money
    {
        return Money::of($this->net_amount);
    }

    public function isApproved(): bool
    {
        return in_array($this->status, ['approved', 'processing', 'completed'], true);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Apakah penarikan ini boleh disetujui otomatis.
     *
     * Dua syarat, dan keduanya harus terpenuhi:
     *
     *   1. Kill switch `withdrawal.auto_approve` menyala.
     *   2. Nominalnya jatuh di ambang yang tidak butuh penyetuju.
     *
     * Syarat pertama sempat cuma ada di dokumentasi. Docblock method ini
     * menjanjikannya, deskripsi flag-nya di seeder menjanjikannya
     * ("Dimatikan berarti semua lewat review manual"), dan tidak ada satu baris
     * kode pun yang membacanya. Tim finance mematikan flag itu saat menduga ada
     * fraud, melihat statusnya berubah di panel, dan penarikan tetap cair
     * otomatis. Kill switch yang tidak berefek lebih berbahaya daripada tidak
     * punya kill switch, karena orang berhenti mencari jalan lain.
     */
    public function qualifiesForAutoApproval(): bool
    {
        if (! self::autoApprovalEnabled()) {
            return false;
        }

        return app(ResolveApprovalThreshold::class)
            ->handle('withdrawal', (int) $this->amount)
            ->isAutomatic();
    }

    /**
     * Kill switch persetujuan otomatis penarikan.
     *
     * Dibaca dari `feature_flags`, bukan config, supaya bisa dimatikan tanpa
     * deploy. Di-cache 30 detik dengan pola yang sama seperti
     * ResolveSurge::surgeEnabled() dan FindEligiblePromos::promoEnabled():
     * cukup singkat untuk terasa langsung saat diubah, cukup lama untuk tidak
     * menjadi satu query per penarikan.
     *
     * Default-nya `false` kalau flag-nya tidak ada sama sekali. Untuk surge dan
     * promo default-nya `true` karena fitur yang hilang hanya mengurangi
     * pendapatan; di sini yang hilang adalah kontrol atas uang keluar.
     */
    public static function autoApprovalEnabled(): bool
    {
        return FeatureFlag::isEnabled('withdrawal.auto_approve', default: false);
    }

    /**
     * Nomor rekening tersamarkan: empat digit terakhir saja.
     */
    public function bankAccountMasked(): ?string
    {
        if ($this->bank_account_number === null) {
            return null;
        }

        $number = (string) $this->bank_account_number;
        $suffix = (int) config('antaride.masking.bank_account.suffix', 4);
        $length = strlen($number);

        if ($length <= $suffix) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - $suffix).substr($number, -$suffix);
    }

    /**
     * Nomor rekening penuh, hanya untuk yang punya permission, dan dicatat.
     */
    public function bankAccountFull(): ?string
    {
        if ($this->bank_account_number === null) {
            return null;
        }

        if (auth('admin')->user()?->can('kyc.view_full') !== true) {
            return $this->bankAccountMasked();
        }

        AuditLog::recordSensitiveAccess('bank_account_number', $this);

        return (string) $this->bank_account_number;
    }

    /**
     * Ringkasan untuk dialog konfirmasi penyetujuan.
     *
     * Blueprint admin bagian 12: konfirmasi menyebutkan dampak dengan angka,
     * bukan "Anda yakin?". Ini yang mencegah staf finance menyetujui baris yang
     * salah saat memproses dua puluh penarikan berurutan.
     */
    public function approvalSummary(): string
    {
        return sprintf(
            'Setujui penarikan %s ke rekening %s %s milik %s?',
            $this->netAmount()->format(),
            $this->bank_name,
            $this->bankAccountMasked(),
            $this->bank_account_name,
        );
    }
}
