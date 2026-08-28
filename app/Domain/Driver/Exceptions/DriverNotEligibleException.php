<?php

declare(strict_types=1);

namespace App\Domain\Driver\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/**
 * Driver tidak boleh online.
 *
 * Pesannya spesifik per penyebab, dan itu bukan kemewahan. Driver yang ditolak
 * online dengan pesan "Anda tidak bisa online sekarang" akan menelepon CS, dan
 * CS harus membuka panel untuk mencari penyebabnya. Pesan yang menyebutkan
 * dokumen mana yang kadaluarsa menyelesaikannya tanpa satu telepon pun.
 *
 * `reason` di `details` yang dipakai aplikasi untuk memutuskan tombol apa yang
 * ditampilkan — "Perbarui Dokumen", "Hubungi Bantuan", atau "Aktifkan Layanan"
 * — dan itu tidak bisa disimpulkan dari teks pesannya tanpa mencocokkan string,
 * yang akan diam-diam berhenti bekerja begitu ada yang memperbaiki tata
 * bahasanya.
 */
class DriverNotEligibleException extends DomainException
{
    public string $reasonCode = 'UNKNOWN';

    public static function becauseStatus(string $status): self
    {
        return self::withReason(
            match ($status) {
                'draft' => 'Lengkapi dulu pendaftaran Anda.',
                'pending_review' => 'Pendaftaran Anda sedang diperiksa. Kami akan memberi tahu setelah selesai.',
                'rejected' => 'Pendaftaran Anda belum disetujui. Hubungi bantuan untuk penjelasan.',
                'suspended' => 'Akun Anda sedang ditangguhkan. Hubungi bantuan.',
                'banned' => 'Akun Anda diblokir permanen.',
                default => 'Akun Anda belum bisa menerima order.',
            },
            'STATUS_'.strtoupper($status),
        );
    }

    public static function notVerified(): self
    {
        return self::withReason('Akun Anda belum terverifikasi.', 'NOT_VERIFIED');
    }

    public static function expiredDocuments(int $count): self
    {
        return self::withReason(
            $count === 1
                ? 'Ada 1 dokumen Anda yang sudah kadaluarsa. Perbarui dulu sebelum bisa online.'
                : "Ada {$count} dokumen Anda yang sudah kadaluarsa. Perbarui dulu sebelum bisa online.",
            'EXPIRED_DOCUMENTS',
        );
    }

    public static function noServiceEnabled(): self
    {
        return self::withReason(
            'Belum ada layanan yang aktif untuk Anda. Aktifkan minimal satu layanan.',
            'NO_SERVICE_ENABLED',
        );
    }

    public static function outsideServiceArea(): self
    {
        return self::withReason(
            'Lokasi Anda di luar area layanan Antaride.',
            'OUTSIDE_SERVICE_AREA',
        );
    }

    public function errorCode(): string
    {
        return 'DRIVER_NOT_ELIGIBLE';
    }

    public function httpStatus(): int
    {
        return 403;
    }

    // -------------------------------------------------------------------------

    private static function withReason(string $message, string $reasonCode): self
    {
        $e = new self($message, details: ['reason' => $reasonCode]);
        $e->reasonCode = $reasonCode;

        return $e;
    }
}
