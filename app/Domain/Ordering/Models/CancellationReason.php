<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Alasan pembatalan yang bisa dipilih.
 *
 * Dua flag yang menentukan konsekuensinya:
 *
 *   charges_fee            apakah pembatalan dengan alasan ini dikenai biaya
 *   affects_driver_score   apakah ikut menurunkan skor driver
 *
 * Pemisahan itu penting. Alasan yang di luar kendali driver (penumpang tidak ada
 * di lokasi, alamat tidak terjangkau) TIDAK boleh menurunkan skornya. Kalau
 * dihukum, driver akan berhenti melaporkan masalah nyata dan memilih membiarkan
 * order kadaluarsa, dan penumpang menunggu lebih lama tanpa tahu kenapa.
 */
class CancellationReason extends Model
{
    use HasFactory;

    protected $fillable = [
        'actor_type',
        'code',
        'text',
        'charges_fee',
        'affects_driver_score',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'charges_fee' => 'boolean',
            'affects_driver_score' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }

    /**
     * Alasan yang boleh dipilih aktor tertentu.
     *
     * Penumpang tidak boleh memilih alasan driver, dan sebaliknya. Tanpa
     * penyaringan ini, aplikasi bisa mengirim `cancellation_reason_id` milik
     * pihak lain dan konsekuensi biayanya jadi salah.
     */
    public function scopeForActor(Builder $query, string $actorType): Builder
    {
        return $query
            ->where('actor_type', $actorType)
            ->where('is_active', true)
            ->orderBy('sort_order');
    }

    public function scopeSystem(Builder $query): Builder
    {
        return $query->where('actor_type', 'system');
    }

    /**
     * Cari alasan sistem berdasarkan kode.
     *
     * Dipakai pembatalan otomatis: tidak ada driver, pembayaran gagal, merchant
     * tidak merespons, kill switch.
     */
    public static function systemReason(string $code): ?self
    {
        return self::query()
            ->where('actor_type', 'system')
            ->where('code', $code)
            ->first();
    }
}
