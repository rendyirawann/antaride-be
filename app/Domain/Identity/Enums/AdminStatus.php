<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

enum AdminStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Aktif',
            self::Suspended => 'Ditangguhkan',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Active => 'badge-light-success',
            self::Suspended => 'badge-light-danger',
        };
    }

    public function canAuthenticate(): bool
    {
        return $this === self::Active;
    }
}
