<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Actions;

use App\Domain\Catalog\Contracts\ZoneResolver;
use App\Domain\Catalog\Models\ServiceType;
use App\Domain\Matching\Contracts\DriverLocationIndex;
use App\Domain\Pricing\Calculator\FareCalculator;
use App\Domain\Pricing\Contracts\QuoteStore;
use App\Domain\Pricing\Contracts\RoutingService;
use App\Domain\Pricing\DTOs\Quote;
use App\Domain\Pricing\DTOs\QuoteOption;
use App\Domain\Pricing\Exceptions\OutsideServiceAreaException;
use App\Domain\Pricing\Exceptions\PricingRuleNotFoundException;
use App\Domain\Shared\ValueObjects\Coordinate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Membuat estimasi harga dan membekukannya.
 *
 * Urutannya mengikuti blueprint bagian 4.2:
 *
 *   1. Tentukan zona dari titik jemput (PostGIS)
 *   2. Panggil OSRM: jarak, durasi, polyline
 *   3. Ambil tarif aktif untuk setiap layanan di zona itu
 *   4. Hitung surge per layanan
 *   5. Hitung ongkos, klem ke batas regulasi
 *   6. Cari promo yang eligible untuk pengguna ini
 *   7. Periksa ketersediaan driver dalam radius
 *   8. Simpan ke Redis dengan TTL, kembalikan quote_id
 *
 * ============================================================================
 *  YANG PALING PENTING DARI SELURUH CLASS INI
 * ============================================================================
 *  Harga dihitung DI SINI dan disimpan di Redis. Aplikasi hanya menerima
 *  quote_id. Saat membuat order, yang dikirim adalah quote_id itu, dan backend
 *  membaca harganya dari Redis.
 *
 *  Tidak ada satu pun jalur di mana harga datang dari aplikasi.
 * ============================================================================
 *
 * Zona ditentukan dari titik JEMPUT, bukan titik tujuan. Ini keputusan yang
 * perlu disadari: perjalanan dari zona bertarif murah ke zona bertarif mahal
 * memakai tarif zona jemput. Alasannya, penumpang perlu tahu ongkosnya sebelum
 * memilih tujuan, dan tarif yang berubah saat dia menggeser pin tujuan akan
 * terasa seperti sistem yang mempermainkan harga.
 */
class CreateQuote
{
    public function __construct(
        private readonly ZoneResolver $zoneResolver,
        private readonly RoutingService $routing,
        private readonly ResolvePricingRule $resolvePricingRule,
        private readonly ResolveSurge $resolveSurge,
        private readonly FareCalculator $fareCalculator,
        private readonly FindEligiblePromos $findEligiblePromos,
        private readonly DriverLocationIndex $driverIndex,
        private readonly QuoteStore $quoteStore,
    ) {}

    /**
     * @param  array<int, Coordinate>  $stops  perhentian tambahan setelah tujuan
     * @param  array<int, string>|null  $serviceCodes  null berarti semua layanan aktif
     */
    public function handle(
        int $userId,
        Coordinate $pickup,
        Coordinate $destination,
        array $stops = [],
        ?array $serviceCodes = null,
    ): Quote {
        // --- 1. Zona ---

        $zone = $this->zoneResolver->resolve($pickup);

        if ($zone === null) {
            throw new OutsideServiceAreaException(
                'Titik jemput berada di luar area layanan kami saat ini.',
                details: ['pickup' => $pickup->jsonSerialize()],
            );
        }

        // Tujuan di luar area TIDAK menggagalkan quote. Mengantar seseorang ke
        // luar zona operasional adalah hal yang wajar; yang tidak dilayani
        // adalah MENJEMPUT dari luar zona, karena di sana tidak ada driver.
        //
        // Kalau ini nanti perlu dibatasi (misalnya karena driver tidak mau
        // menempuh perjalanan sekali jalan yang jauh), pembatasnya adalah aturan
        // bisnis di sini, bukan resolusi zona.

        // --- 2. Rute ---

        $waypoints = [$pickup, $destination, ...$stops];
        $route = $this->routing->routeVia($waypoints);

        // --- 3-7. Per layanan ---

        $services = $this->services($serviceCodes);

        if ($services === []) {
            throw new PricingRuleNotFoundException(
                'Tidak ada layanan yang aktif saat ini.',
            );
        }

        $rules = $this->resolvePricingRule->handleMany(
            $services->pluck('id')->all(),
            $zone->id,
        );

        $options = [];

        foreach ($services as $service) {
            $rule = $rules[$service->id] ?? null;

            if ($rule === null) {
                // Satu layanan tanpa tarif TIDAK menggagalkan seluruh quote.
                // Yang terjadi hanya layanan itu tidak muncul sebagai pilihan.
                //
                // Ini disengaja: kalau tarif ride_car belum disetujui, penumpang
                // tetap harus bisa memesan ride_bike. Menggagalkan semuanya
                // berarti satu celah tarif mematikan seluruh layanan.
                Log::warning('Layanan dilewati di quote karena tarif tidak ditemukan', [
                    'service_code' => $service->code,
                    'zone_id' => $zone->id,
                ]);

                continue;
            }

            $surge = $this->resolveSurge->handle(
                zoneId: $zone->id,
                serviceTypeId: $service->id,
                serviceCode: $service->code,
            );

            $fare = $this->fareCalculator->calculate(
                route: $route,
                rule: $rule,
                surgeMultiplier: $surge->multiplier,
                // Diskon TIDAK diterapkan di sini. Quote menyimpan ongkos penuh
                // plus daftar promo yang eligible; penumpang memilih promo saat
                // membuat order, dan nominalnya diambil dari daftar itu.
                //
                // Kalau diskon dibekukan ke dalam ongkos, penumpang tidak bisa
                // membandingkan harga antar layanan secara adil, karena tidak
                // semua promo berlaku untuk semua layanan.
                discount: null,
                applyPackagingFee: $service->requires_merchant,
                applyInsuranceFee: $service->code === 'send',
            );

            $availability = $this->measureAvailability($service->code, $zone->id, $pickup);

            $options[$service->code] = new QuoteOption(
                serviceCode: $service->code,
                serviceTypeId: $service->id,
                serviceName: $service->name,
                fare: $fare,
                surge: $surge,
                pricingRuleId: $rule->id,
                pickupEtaMinutes: $availability['eta_minutes'],
                availableDrivers: $availability['count'],
                tripDurationMinutes: (int) ceil($route->durationSeconds / 60),
            );
        }

        if ($options === []) {
            throw new PricingRuleNotFoundException(
                'Tarif untuk layanan di area ini belum tersedia.',
                details: ['zone' => $zone->code],
            );
        }

        // --- 6. Promo ---

        $promos = $this->findEligiblePromos->handle(
            userId: $userId,
            zoneId: $zone->id,
            options: $options,
        );

        // --- 8. Simpan ---

        $ttl = (int) config('antaride.quote.ttl_seconds', 300);
        $now = CarbonImmutable::instance(now()->toDateTimeImmutable());

        $quote = new Quote(
            id: (string) Str::uuid7(),
            userId: $userId,
            pickup: $pickup,
            destination: $destination,
            zoneId: $zone->id,
            zoneName: $zone->name,
            distanceMeters: $route->distanceMeters,
            durationSeconds: $route->durationSeconds,
            routePolyline: $route->polyline,
            options: $options,
            eligiblePromos: $promos,
            createdAt: $now,
            expiresAt: $now->addSeconds($ttl),
            stops: array_map(
                static fn (Coordinate $stop) => $stop->jsonSerialize(),
                $stops,
            ),
        );

        $this->quoteStore->put($quote);

        return $quote;
    }

    // -------------------------------------------------------------------------

    /**
     * Layanan yang diminta, atau semua yang aktif.
     *
     * @param  array<int, string>|null  $serviceCodes
     * @return Collection<int, ServiceType>
     */
    private function services(?array $serviceCodes)
    {
        $query = ServiceType::query()->active()->orderBy('sort_order');

        if ($serviceCodes !== null && $serviceCodes !== []) {
            $query->whereIn('code', $serviceCodes);
        }

        return $query->get();
    }

    /**
     * Jumlah driver tersedia dan perkiraan waktu sampai ke titik jemput.
     *
     * ETA dihitung dari driver TERDEKAT, dengan jarak tempuh OSRM, bukan jarak
     * garis lurus. Driver yang jaraknya 800 m garis lurus tapi harus memutar
     * karena jalan satu arah bisa butuh 6 menit, dan menampilkan "2 menit" lalu
     * membuatnya datang 6 menit kemudian adalah cara paling cepat kehilangan
     * kepercayaan penumpang.
     *
     * Kalau OSRM gagal, ETA dikembalikan null, bukan ditebak. Penumpang lebih
     * baik tidak melihat angka daripada melihat angka yang salah.
     *
     * @return array{count: int, eta_minutes: int|null}
     */
    private function measureAvailability(
        string $serviceCode,
        int $zoneId,
        Coordinate $pickup,
    ): array {
        $radius = (int) config('antaride.matching.initial_radius_m', 2000);

        $nearby = $this->driverIndex->findNearby($serviceCode, $pickup, $radius, limit: 20);

        if ($nearby === []) {
            return ['count' => 0, 'eta_minutes' => null];
        }

        // Irisan dengan yang benar-benar siap menerima order. Driver yang
        // sedang mengantar tetap ada di indeks posisi, karena penumpangnya perlu
        // melihat dia bergerak.
        $availableIds = $this->driverIndex->availableDriverIds($serviceCode, [$zoneId]);

        if ($availableIds === []) {
            return ['count' => 0, 'eta_minutes' => null];
        }

        $availableSet = array_flip($availableIds);

        $candidates = array_values(array_filter(
            $nearby,
            static fn ($position) => isset($availableSet[$position->driverId])
                && ! $position->isStale(),
        ));

        if ($candidates === []) {
            return ['count' => 0, 'eta_minutes' => null];
        }

        return [
            'count' => count($candidates),
            'eta_minutes' => $this->estimatePickupEta($candidates[0]->coordinate, $pickup),
        ];
    }

    private function estimatePickupEta(Coordinate $driver, Coordinate $pickup): ?int
    {
        try {
            $durations = $this->routing->durationsTo([$driver], $pickup);
            $seconds = $durations[0] ?? null;

            return $seconds === null ? null : max(1, (int) ceil($seconds / 60));
        } catch (\Throwable $e) {
            // Kegagalan menghitung ETA tidak boleh menggagalkan quote. Yang
            // hilang satu angka di layar; yang dipertahankan adalah kemampuan
            // penumpang memesan.
            Log::info('ETA penjemputan tidak dapat dihitung', ['message' => $e->getMessage()]);

            return null;
        }
    }
}
