<?php

declare(strict_types=1);

namespace App\Infrastructure\Redis\Stores;

use App\Domain\Pricing\Contracts\QuoteStore;
use App\Domain\Pricing\DTOs\FareBreakdown;
use App\Domain\Pricing\DTOs\Quote;
use App\Domain\Pricing\DTOs\QuoteOption;
use App\Domain\Pricing\DTOs\SurgeDecision;
use App\Domain\Shared\ValueObjects\Coordinate;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Shared\ValueObjects\Polyline;
use Carbon\CarbonImmutable;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Penyimpanan quote di Redis.
 *
 * Key `quote:{id}` dengan TTL dari config. Memakai koneksi 'shared' tanpa
 * prefix supaya bentuk key-nya sama dengan yang dilihat service lain saat
 * diperiksa lewat redis-cli.
 */
class RedisQuoteStore implements QuoteStore
{
    public function put(Quote $quote): void
    {
        $ttl = max(1, $quote->secondsUntilExpiry());

        $this->connection()->setex(
            $this->key($quote->id),
            $ttl,
            json_encode($quote->toStorage(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );
    }

    public function get(string $quoteId): ?Quote
    {
        $raw = $this->connection()->get($this->key($quoteId));

        return $this->hydrate($quoteId, $raw);
    }

    public function forget(string $quoteId): void
    {
        $this->connection()->del($this->key($quoteId));
    }

    public function pull(string $quoteId): ?Quote
    {
        // GETDEL baru ada di Redis 6.2, sementara build Windows untuk
        // pengembangan masih 5.0. Script Lua bekerja di keduanya dan tetap
        // atomik, jadi tidak perlu percabangan berdasarkan versi.
        $script = <<<'LUA'
            local value = redis.call("GET", KEYS[1])
            if value then
                redis.call("DEL", KEYS[1])
            end
            return value
        LUA;

        $raw = $this->connection()->eval($script, 1, $this->key($quoteId));

        return $this->hydrate($quoteId, $raw);
    }

    // -------------------------------------------------------------------------

    private function hydrate(string $quoteId, mixed $raw): ?Quote
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // Quote yang isinya rusak diperlakukan sebagai tidak ada, bukan
            // dilempar. Aplikasi akan meminta quote baru, dan itu perilaku yang
            // benar. Tapi tetap dicatat, karena JSON rusak di Redis berarti ada
            // yang menulis dengan format lain, dan itu perlu diketahui.
            Log::warning('Quote di Redis tidak dapat dibaca', [
                'quote_id' => $quoteId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $options = [];

        foreach (($data['options'] ?? []) as $serviceCode => $option) {
            $options[$serviceCode] = $this->hydrateOption($option);
        }

        return new Quote(
            id: (string) $data['id'],
            userId: (int) $data['user_id'],
            pickup: Coordinate::of(
                (float) $data['pickup']['lat'],
                (float) $data['pickup']['lng'],
            ),
            destination: isset($data['destination']) && is_array($data['destination'])
                ? Coordinate::of(
                    (float) $data['destination']['lat'],
                    (float) $data['destination']['lng'],
                )
                : null,
            zoneId: $data['zone_id'] === null ? null : (int) $data['zone_id'],
            zoneName: $data['zone_name'] ?? null,
            distanceMeters: (int) $data['distance_m'],
            durationSeconds: (int) $data['duration_s'],
            routePolyline: Polyline::decode((string) ($data['route_polyline'] ?? '')),
            options: $options,
            eligiblePromos: $data['eligible_promos'] ?? [],
            createdAt: CarbonImmutable::parse($data['created_at']),
            expiresAt: CarbonImmutable::parse($data['expires_at']),
            stops: $data['stops'] ?? [],
        );
    }

    /**
     * @param  array<string, mixed>  $option
     */
    private function hydrateOption(array $option): QuoteOption
    {
        /** @var array<string, mixed> $fare */
        $fare = $option['fare'];

        /** @var array<string, mixed> $surge */
        $surge = $option['surge'];

        return new QuoteOption(
            serviceCode: (string) $option['service_code'],
            serviceTypeId: (int) $option['service_type_id'],
            serviceName: (string) $option['service_name'],
            fare: new FareBreakdown(
                baseFare: Money::of((int) $fare['base_fare']),
                distanceFare: Money::of((int) $fare['distance_fare']),
                timeFare: Money::of((int) $fare['time_fare']),
                surgeMultiplier: (string) $fare['surge_multiplier'],
                surgeAmount: Money::of((int) $fare['surge_amount']),
                regulatoryAdjustment: Money::of((int) $fare['regulatory_adjustment']),
                platformFee: Money::of((int) $fare['platform_fee']),
                serviceFee: Money::of((int) $fare['service_fee']),
                discount: Money::of((int) $fare['discount_amount']),
                total: Money::of((int) $fare['total_fare']),
                driverEarning: Money::of((int) $fare['driver_earning']),
                commission: Money::of((int) $fare['commission_amount']),
            ),
            surge: new SurgeDecision(
                multiplier: (string) $surge['multiplier'],
                reason: (string) $surge['reason'],
                surgeRuleId: $surge['surge_rule_id'] ?? null,
                demandRatio: $surge['demand_ratio'] ?? null,
                availableDrivers: $surge['available_drivers'] ?? null,
                pendingOrders: $surge['pending_orders'] ?? null,
            ),
            pricingRuleId: (int) $option['pricing_rule_id'],
            pickupEtaMinutes: $option['pickup_eta_minutes'] ?? null,
            availableDrivers: (int) $option['available_drivers'],
            tripDurationMinutes: (int) $option['trip_duration_minutes'],
        );
    }

    private function connection(): Connection
    {
        return Redis::connection('shared');
    }

    private function key(string $quoteId): string
    {
        return "quote:{$quoteId}";
    }
}
