<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Banned = 'banned';
    case Deleted = 'deleted';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Aktif',
            self::Suspended => 'Ditangguhkan',
            self::Banned => 'Diblokir',
            self::Deleted => 'Dihapus',
        };
    }

    /**
     * Warna dipakai sebagai data, bukan hiasan: satu warna untuk berjalan
     * normal, satu untuk perlu tindakan, satu untuk berhenti.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Active => 'badge-light-success',
            self::Suspended => 'badge-light-warning',
            self::Banned => 'badge-light-danger',
            self::Deleted => 'badge-light-dark',
        };
    }

    /**
     * Hanya user aktif yang boleh membuat order atau login.
     */
    public function canAuthenticate(): bool
    {
        return $this === self::Active;
    }

    public static function selectable(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            array_filter(self::cases(), fn (self $case) => $case !== self::Deleted),
        );
    }
}
