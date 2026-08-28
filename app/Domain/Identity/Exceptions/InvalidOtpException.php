<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/**
 * Kode OTP salah, kadaluarsa, atau percobaannya sudah habis.
 *
 * ============================================================================
 *  SISA PERCOBAAN DIBERITAHUKAN, DAN ITU BUKAN KEBOCORAN
 * ============================================================================
 *  Memberi tahu "sisa 3 percobaan" tidak membantu penyerang: dia bisa
 *  menghitungnya sendiri, dan batasnya bukan rahasia. Yang dibantu adalah
 *  pengguna sungguhan yang salah ketik satu digit dan perlu tahu apakah masih
 *  bisa mencoba atau harus meminta kode baru.
 *
 *  Yang TIDAK diberitahukan: apakah nomornya terdaftar. Itu tetap dijaga di
 *  RequestOtp.
 * ============================================================================
 */
class InvalidOtpException extends DomainException
{
    public static function wrongCode(int $remainingAttempts): self
    {
        return new self(
            $remainingAttempts > 0
                ? "Kode yang Anda masukkan salah. Sisa {$remainingAttempts} percobaan."
                : 'Kode yang Anda masukkan salah. Percobaan Anda sudah habis, minta kode baru.',
            details: ['remaining_attempts' => $remainingAttempts],
        );
    }

    public static function expired(): self
    {
        return new self(
            'Kode sudah kadaluarsa. Minta kode baru.',
            details: ['reason' => 'EXPIRED'],
        );
    }

    public static function tooManyAttempts(): self
    {
        return new self(
            'Percobaan Anda sudah habis. Minta kode baru.',
            details: ['reason' => 'TOO_MANY_ATTEMPTS', 'remaining_attempts' => 0],
        );
    }

    /**
     * Tidak ada OTP yang bisa diverifikasi untuk nomor ini.
     *
     * Pesannya sengaja tidak berbunyi "nomor ini tidak pernah meminta kode",
     * karena itu memberi tahu penyerang bahwa nomor yang dia coba memang belum
     * dipakai siapa pun.
     */
    public static function notRequested(): self
    {
        return new self(
            'Kode sudah tidak berlaku. Minta kode baru.',
            details: ['reason' => 'NOT_FOUND'],
        );
    }

    public function errorCode(): string
    {
        return 'INVALID_OTP';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
