<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/**
 * Tidak ada tarif yang berlaku untuk layanan, zona, dan saat yang diminta.
 *
 * Ini biasanya berarti salah satu dari dua hal, dan keduanya masalah data,
 * bukan masalah pengguna:
 *
 *   1. Tarif default untuk layanan itu belum pernah dibuat.
 *   2. Tarif lama sudah berakhir masa berlakunya dan penggantinya belum
 *      disetujui, sehingga ada celah waktu tanpa tarif.
 *
 * Kasus kedua yang paling berbahaya, karena terjadi tepat pada saat tarif
 * berganti dan gejalanya adalah order berhenti bisa dibuat. Ada perintah
 * pemeriksaan `antaride:health` yang perlu diperluas untuk menangkap celah
 * seperti ini sebelum terjadi.
 *
 * Statusnya 503, bukan 500, supaya aplikasi menampilkan "coba beberapa saat
 * lagi" alih-alih pesan kesalahan sistem, dan supaya monitoring
 * membedakannya dari bug.
 */
class PricingRuleNotFoundException extends DomainException
{
    public function errorCode(): string
    {
        return 'PRICING_UNAVAILABLE';
    }

    public function httpStatus(): int
    {
        return 503;
    }
}
