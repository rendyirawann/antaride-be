<?php

declare(strict_types=1);

namespace App\Domain\Identity\DTOs;

use App\Domain\Identity\Support\PhoneNumber;
use Illuminate\Support\Carbon;
use JsonSerializable;

/**
 * Hasil permintaan OTP.
 *
 * `debugCode` HANYA terisi di luar produksi, dan itulah satu-satunya alasan DTO
 * ini punya field itu: pengembangan dan test end-to-end harus bisa jalan tanpa
 * gateway SMS. Di produksi nilainya selalu null, dan `jsonSerialize()` bahkan
 * tidak menyertakan kuncinya — bukan mengirim null — supaya bentuk response di
 * produksi tidak menyisakan petunjuk bahwa field itu pernah ada.
 */
final readonly class OtpChallenge implements JsonSerializable
{
    public function __construct(
        public string $phone,
        public string $purpose,
        public Carbon $expiresAt,
        public int $resendAfterSeconds,
        public ?string $debugCode = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $payload = [
            // Nomor dikembalikan dalam bentuk tersamarkan, bukan penuh.
            // Aplikasi sudah tahu nomor yang dia kirim; yang ditampilkan di
            // layar "masukkan kode" adalah konfirmasi, dan nomor penuh di sana
            // hanya menambah data yang bisa dibaca orang di sekitar.
            'phone_masked' => PhoneNumber::masked($this->phone),
            'purpose' => $this->purpose,
            'expires_at' => $this->expiresAt->toIso8601String(),
            'expires_in_seconds' => max(0, (int) floor(now()->diffInSeconds($this->expiresAt, absolute: false))),
            'resend_after_seconds' => $this->resendAfterSeconds,
        ];

        if ($this->debugCode !== null) {
            $payload['debug_code'] = $this->debugCode;
        }

        return $payload;
    }
}
