<?php

declare(strict_types=1);

namespace App\Domain\Matching\DTOs;

use App\Domain\Driver\Models\Driver;

/**
 * Seorang driver yang sedang dipertimbangkan untuk sebuah order, beserta
 * skornya.
 *
 * `scoreBreakdown` disimpan ke `order_offers.score_breakdown`. Ini yang
 * menjawab keluhan "kenapa saya tidak pernah dapat order" dengan angka alih-alih
 * dugaan, dan yang membuat tim ops bisa memeriksa apakah bobot skoringnya
 * berperilaku seperti yang diharapkan.
 */
final readonly class DriverCandidate
{
    /**
     * @param  array<string, float>  $scoreBreakdown
     */
    public function __construct(
        public Driver $driver,
        public DriverPosition $position,
        public float $score,
        public array $scoreBreakdown,
        public int $distanceToPickupM,
        public ?int $etaSeconds = null,
    ) {}

    public function driverId(): int
    {
        return (int) $this->driver->getKey();
    }

    public function etaMinutes(): ?int
    {
        return $this->etaSeconds === null ? null : max(1, (int) ceil($this->etaSeconds / 60));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'driver_id' => $this->driverId(),
            'score' => round($this->score, 3),
            'breakdown' => array_map(static fn (float $v) => round($v, 4), $this->scoreBreakdown),
            'distance_to_pickup_m' => $this->distanceToPickupM,
            'eta_seconds' => $this->etaSeconds,
        ];
    }
}
