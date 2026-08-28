<?php

declare(strict_types=1);

namespace App\Domain\Driver\Enums;

/**
 * Status onboarding dan operasional driver.
 *
 * Urutan normalnya: draft → pending_review → active. Cabangnya: rejected (bisa
 * kembali ke pending_review setelah dokumen diperbaiki), suspended (sementara),
 * banned (permanen).
 */
enum DriverStatus: string
{
    /** Sedang mengisi data dan mengunggah dokumen. */
    case Draft = 'draft';

    /** Menunggu verifikator memeriksa dokumen. */
    case PendingReview = 'pending_review';

    /** Ditolak dengan alasan. Boleh memperbaiki lalu mengajukan lagi. */
    case Rejected = 'rejected';

    /** Boleh online dan menerima order. */
    case Active = 'active';

    /** Ditangguhkan sementara, biasanya karena pelanggaran. */
    case Suspended = 'suspended';

    /** Diblokir permanen. */
    case Banned = 'banned';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::PendingReview => 'Menunggu verifikasi',
            self::Rejected => 'Ditolak',
            self::Active => 'Aktif',
            self::Suspended => 'Ditangguhkan',
            self::Banned => 'Diblokir',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'badge-light-secondary',
            self::PendingReview => 'badge-light-warning',
            self::Rejected => 'badge-light-danger',
            self::Active => 'badge-light-success',
            self::Suspended => 'badge-light-warning',
            self::Banned => 'badge-light-dark',
        };
    }

    /**
     * Apakah driver dengan status ini boleh online dan menerima order.
     *
     * Hanya Active. Ini diperiksa di filter matching DAN saat driver menekan
     * tombol online, karena driver yang sudah online lalu disuspend harus
     * berhenti menerima order tanpa perlu menunggu dia offline sendiri.
     */
    public function canOperate(): bool
    {
        return $this === self::Active;
    }

    /**
     * Apakah status ini masih bisa berubah menjadi aktif.
     *
     * Banned tidak bisa, kecuali lewat approval dua tahap (driver_unban).
     */
    public function isRecoverable(): bool
    {
        return $this !== self::Banned;
    }

    /**
     * @return array<int, self>
     */
    public static function operational(): array
    {
        return [self::Active, self::Suspended];
    }
}
