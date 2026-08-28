<?php

declare(strict_types=1);

namespace App\Domain\Approval\DTOs;

/**
 * Berapa penyetuju yang dibutuhkan untuk satu nominal, dan dari role apa.
 */
final readonly class ApprovalThreshold
{
    public function __construct(
        public int $requiredApprovers,
        public ?string $requiredRole,
        public int $minAmount,
        public ?int $maxAmount,
        public bool $fromDatabase,
    ) {}

    /**
     * Nol penyetuju berarti boleh jalan otomatis.
     */
    public function isAutomatic(): bool
    {
        return $this->requiredApprovers === 0;
    }
}
