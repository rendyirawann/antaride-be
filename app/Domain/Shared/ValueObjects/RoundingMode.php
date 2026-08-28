<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObjects;

/**
 * Arah pembulatan untuk operasi uang.
 *
 * Dibuat enum eksplisit, bukan mengandalkan default, karena arah pembulatan
 * pada uang adalah keputusan bisnis. Satu rupiah per transaksi kali 500 order
 * per hari adalah Rp 182.500 per tahun yang tidak bisa dijelaskan asalnya.
 */
enum RoundingMode
{
    /** Ke bawah. Dipakai untuk bagian platform, supaya sisa jatuh ke driver. */
    case Floor;

    /** Ke atas. Dipakai untuk tarif yang ditagih ke penumpang. */
    case Ceiling;

    /** Pembulatan biasa. Dipakai untuk surge dan nilai yang bukan pembagian. */
    case HalfUp;
}
