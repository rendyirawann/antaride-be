<?php

declare(strict_types=1);

namespace App\Domain\Matching\DTOs;

use Illuminate\Support\Carbon;

/**
 * Hasil satu gelombang penawaran.
 *
 * Dibuat sebagai DTO, bukan bool atau int, karena job matching perlu tahu
 * BEDANYA antara tiga keadaan yang semuanya "tidak ada yang menerima":
 *
 *   stopped   order sudah tidak mencari — berhenti, jangan lanjut gelombang
 *   empty     tidak ada kandidat di radius ini — lanjut, radius akan melebar
 *   offered   ada yang ditawari — tunggu sampai penawarannya kadaluarsa
 *
 * Kalau ketiganya diringkas jadi satu bool, job akan memperlakukan order yang
 * sudah diterima driver sama dengan order yang belum ketemu driver, dan
 * gelombang berikutnya tetap jalan — menawarkan order yang sudah punya driver.
 */
final readonly class OfferWaveResult
{
    /**
     * @param  array<int, DriverCandidate>  $candidates
     */
    private function __construct(
        public string $outcome,
        public int $wave,
        public int $radiusMeters,
        public array $candidates,
        public ?Carbon $expiresAt,
        public ?string $reason,
    ) {}

    /**
     * @param  array<int, DriverCandidate>  $candidates
     */
    public static function offered(
        int $wave,
        int $radiusMeters,
        array $candidates,
        \DateTimeInterface $expiresAt,
    ): self {
        return new self(
            outcome: 'offered',
            wave: $wave,
            radiusMeters: $radiusMeters,
            candidates: $candidates,
            expiresAt: Carbon::instance($expiresAt),
            reason: null,
        );
    }

    public static function empty(int $wave, int $radiusMeters): self
    {
        return new self('empty', $wave, $radiusMeters, [], null, null);
    }

    public static function stopped(int $wave, string $reason): self
    {
        return new self('stopped', $wave, 0, [], null, $reason);
    }

    public function shouldContinue(): bool
    {
        return $this->outcome !== 'stopped';
    }

    public function offeredCount(): int
    {
        return count($this->candidates);
    }

    /**
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return [
            'outcome' => $this->outcome,
            'wave' => $this->wave,
            'radius_m' => $this->radiusMeters,
            'offered' => $this->offeredCount(),
            'driver_ids' => array_map(
                static fn (DriverCandidate $c): int => $c->driverId(),
                $this->candidates,
            ),
            'reason' => $this->reason,
        ];
    }
}
