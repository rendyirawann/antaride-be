<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/**
 * Kode baru diminta terlalu cepat setelah yang sebelumnya.
 *
 * Jeda ini berlaku per NOMOR, bukan per IP. Rate limit di lapisan HTTP juga
 * ada, tapi dia bisa dihindari dengan berganti alamat; yang ini tidak.
 *
 * Yang dilindungi adalah biaya SMS. Satu tombol "kirim ulang" yang ditekan
 * berulang kali sudah cukup mahal; kalau ada yang mengotomatiskannya, biayanya
 * bisa mencapai jutaan rupiah dalam satu malam sebelum ada yang menyadarinya.
 *
 * 429, bukan 422: aplikasi harus memperlakukannya sebagai "tunggu lalu coba
 * lagi", bukan "perbaiki isian". `retry_after_seconds` yang menghidupkan
 * hitungan mundur di tombolnya.
 */
class OtpCooldownException extends DomainException
{
    public static function retryAfter(int $seconds): self
    {
        return new self(
            "Tunggu {$seconds} detik sebelum meminta kode baru.",
            details: ['retry_after_seconds' => $seconds],
        );
    }

    public function errorCode(): string
    {
        return 'OTP_COOLDOWN';
    }

    public function httpStatus(): int
    {
        return 429;
    }
}
