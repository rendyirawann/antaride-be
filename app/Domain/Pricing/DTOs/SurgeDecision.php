<?php

declare(strict_types=1);

namespace App\Domain\Pricing\DTOs;

/**
 * Keputusan surge, beserta alasannya.
 *
 * Alasan disimpan, bukan hanya pengalinya. Ini yang menjawab pertanyaan
 * "kenapa ongkos saya naik" tanpa CS perlu menebak, dan yang membuat tim ops
 * bisa memeriksa apakah surge otomatis berperilaku seperti yang diharapkan.
 *
 * Tanpa kolom alasan, satu-satunya cara mengetahui kenapa pengalinya 1,5 adalah
 * menjalankan ulang seluruh logika dengan keadaan yang sudah berubah.
 */
final readonly class SurgeDecision
{
    public function __construct(
        /** Pengali sebagai string, misal "1.30". Selalu dua desimal. */
        public string $multiplier,
        /** schedule, demand_ratio, manual, atau none. */
        public string $reason,
        /** Aturan surge yang menentukan, kalau ada. */
        public ?int $surgeRuleId = null,
        /** Rasio order:driver saat keputusan diambil, kalau relevan. */
        public ?string $demandRatio = null,
        /** Jumlah driver tersedia saat keputusan diambil. */
        public ?int $availableDrivers = null,
        /** Jumlah order yang sedang mencari driver. */
        public ?int $pendingOrders = null,
    ) {}

    public static function none(): self
    {
        return new self(multiplier: '1.00', reason: 'none');
    }

    public function isActive(): bool
    {
        return bccomp($this->multiplier, '1.00', 2) > 0;
    }

    /**
     * Penjelasan untuk penumpang.
     *
     * Sengaja tidak menyebut angka rasio atau nama aturan. Penumpang butuh tahu
     * kenapa ongkosnya naik, bukan detail mesin penentunya.
     */
    public function customerExplanation(): ?string
    {
        if (! $this->isActive()) {
            return null;
        }

        return match ($this->reason) {
            'schedule' => 'Sedang jam sibuk',
            'demand_ratio' => 'Permintaan sedang tinggi di area ini',
            'manual' => 'Ada penyesuaian tarif sementara di area ini',
            default => 'Sedang ada penyesuaian tarif',
        };
    }

    /**
     * Rincian lengkap untuk audit dan panel admin.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'multiplier' => $this->multiplier,
            'reason' => $this->reason,
            'surge_rule_id' => $this->surgeRuleId,
            'demand_ratio' => $this->demandRatio,
            'available_drivers' => $this->availableDrivers,
            'pending_orders' => $this->pendingOrders,
        ];
    }
}
