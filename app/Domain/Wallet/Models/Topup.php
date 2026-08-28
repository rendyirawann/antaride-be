<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Models;

use App\Domain\Shared\Concerns\HasUuid;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pengisian saldo lewat payment gateway.
 *
 * `raw_callback` menyimpan payload asli provider apa adanya. Saat ada sengketa,
 * itu satu-satunya bukti apa yang benar-benar dikirim, dan tidak boleh
 * dinormalkan atau dibersihkan lebih dulu.
 */
class Topup extends Model
{
    use HasFactory;
    use HasUuid;

    protected $fillable = [
        'wallet_id',
        'amount',
        'fee',
        'channel',
        'provider',
        'provider_ref',
        'va_number',
        'qr_string',
        'status',
        'expires_at',
        'paid_at',
        'raw_callback',
        'idempotency_key',
    ];

    protected $hidden = ['idempotency_key', 'raw_callback'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'fee' => 'integer',
            'raw_callback' => 'array',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    // -------------------------------------------------------------------------

    /**
     * Top up yang menunggu bayar dan belum kadaluarsa.
     *
     * Dipakai job polling pembanding. Blueprint bagian 12 menempatkan
     * "percaya pada webhook saja tanpa polling pembanding" sebagai kesalahan
     * nomor lima: webhook hilang itu normal, bukan kasus langka.
     */
    public function scopeAwaitingPayment(Builder $query): Builder
    {
        return $query
            ->where('status', 'pending')
            ->where('expires_at', '>', now());
    }

    /**
     * Top up yang sudah lewat batas waktu tapi statusnya masih pending.
     *
     * Dipakai job penutup. Yang penting: JANGAN langsung ditandai expired tanpa
     * memeriksa ke provider. Pengguna bisa saja sudah membayar dan webhook-nya
     * hilang, dan menandainya expired berarti uangnya masuk tapi saldonya tidak.
     */
    public function scopeStale(Builder $query): Builder
    {
        return $query
            ->where('status', 'pending')
            ->where('expires_at', '<=', now());
    }

    // -------------------------------------------------------------------------

    public function amount(): Money
    {
        return Money::of($this->amount);
    }

    /**
     * Yang benar-benar masuk ke saldo, setelah biaya dipotong.
     */
    public function netAmount(): Money
    {
        return $this->amount()->minus(Money::of($this->fee));
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Instruksi pembayaran untuk ditampilkan di aplikasi.
     *
     * @return array<string, mixed>
     */
    public function paymentInstruction(): array
    {
        return array_filter([
            'channel' => $this->channel,
            'va_number' => $this->va_number,
            'qr_string' => $this->qr_string,
            'amount' => $this->amount,
            'expires_at' => $this->expires_at?->toIso8601String(),
        ], static fn ($value) => $value !== null);
    }
}
