<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/**
 * Akun pengguna tidak boleh masuk.
 *
 * Terjadi SETELAH OTP terverifikasi, dan itu urutan yang benar: orang yang
 * memegang nomornya berhak tahu kenapa akunnya tidak bisa dipakai. Menolak
 * sebelum verifikasi berarti siapa pun bisa menguji nomor mana yang diblokir.
 */
class UserBlockedException extends DomainException
{
    public static function becauseStatus(string $status): self
    {
        return new self(
            match ($status) {
                'suspended' => 'Akun Anda sedang ditangguhkan. Hubungi bantuan untuk penjelasan.',
                'banned' => 'Akun Anda diblokir. Hubungi bantuan kalau Anda merasa ini keliru.',
                'deleted' => 'Akun Anda sudah dihapus.',
                default => 'Akun Anda tidak bisa dipakai saat ini.',
            },
            details: ['status' => $status],
        );
    }

    public function errorCode(): string
    {
        return 'USER_BLOCKED';
    }

    public function httpStatus(): int
    {
        return 403;
    }
}
