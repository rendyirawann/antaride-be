<?php

declare(strict_types=1);

namespace App\Domain\Identity\DTOs;

use App\Domain\Identity\Models\User;

/**
 * Sesi yang baru terbentuk setelah OTP terverifikasi.
 *
 * `isNewUser` dipakai aplikasi untuk memutuskan layar berikutnya: pengguna baru
 * diarahkan ke pengisian nama, yang sudah ada langsung ke beranda. Tanpa
 * penanda ini, aplikasi harus menyimpulkannya dari nama yang masih berbentuk
 * "Pengguna 7890" — cara yang akan salah begitu ada orang yang benar-benar
 * menamai dirinya begitu.
 */
final readonly class AuthenticatedSession
{
    public function __construct(
        public User $user,
        public string $token,
        public bool $isNewUser,
    ) {}
}
