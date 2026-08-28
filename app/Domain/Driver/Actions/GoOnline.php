<?php

declare(strict_types=1);

namespace App\Domain\Driver\Actions;

use App\Domain\Catalog\Contracts\ZoneResolver;
use App\Domain\Driver\Exceptions\DriverNotEligibleException;
use App\Domain\Driver\Models\Driver;
use App\Domain\Driver\Models\DriverSession;
use App\Domain\Driver\Models\Vehicle;
use App\Domain\Matching\Contracts\DriverLocationIndex;
use App\Domain\Shared\ValueObjects\Coordinate;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Driver menyatakan diri siap menerima order.
 *
 * ============================================================================
 *  DUA TEMPAT MENYIMPAN "SEDANG ONLINE", DAN KEDUANYA PERLU
 * ============================================================================
 *    Redis    set ketersediaan per (layanan, zona). Dibaca matching ribuan kali
 *             per menit, jadi harus di memori.
 *    Postgres baris `driver_sessions` yang terbuka. Dibaca laporan jam kerja,
 *             perhitungan insentif, dan panel admin.
 *
 *  Redis bisa hilang seluruhnya saat restart, dan itu memang boleh: yang hilang
 *  hanya "siapa yang siap sekarang", dan driver akan terdaftar ulang pada ping
 *  berikutnya. Yang TIDAK boleh hilang adalah jam kerja, karena itu dasar
 *  perhitungan insentif — dan itu sebabnya dia di Postgres.
 *
 *  Urutannya: Postgres dulu, Redis kemudian. Kalau dibalik dan penulisan
 *  Postgres gagal, driver akan menerima order tanpa punya sesi kerja, dan jam
 *  kerjanya untuk hari itu tidak pernah tercatat.
 * ============================================================================
 */
class GoOnline
{
    public function __construct(
        private readonly DriverLocationIndex $locationIndex,
        private readonly ZoneResolver $zoneResolver,
    ) {}

    /**
     * @param  array<int, string>|null  $serviceCodes  null berarti semua yang dia berhak
     */
    public function handle(
        Driver $driver,
        Coordinate $at,
        ?int $vehicleId = null,
        ?array $serviceCodes = null,
    ): DriverSession {
        $this->assertEligible($driver);

        $vehicle = $this->resolveVehicle($driver, $vehicleId);
        $services = $this->resolveServices($driver, $serviceCodes, $vehicle);

        if ($services === []) {
            throw DriverNotEligibleException::noServiceEnabled();
        }

        $zone = $this->zoneResolver->resolve($at);

        if ($zone === null) {
            throw DriverNotEligibleException::outsideServiceArea();
        }

        $session = $this->openSession($driver, $vehicle, $at);

        // Redis SETELAH Postgres. Lihat penjelasan di docblock kelas.
        foreach ($services as $serviceCode) {
            $this->locationIndex->record(
                serviceCode: $serviceCode,
                driverId: (int) $driver->getKey(),
                coordinate: $at,
            );

            $this->locationIndex->markAvailable(
                $serviceCode,
                (int) $zone->getKey(),
                (int) $driver->getKey(),
            );
        }

        return $session;
    }

    // -------------------------------------------------------------------------

    private function assertEligible(Driver $driver): void
    {
        if ($driver->status->value !== 'active') {
            throw DriverNotEligibleException::becauseStatus($driver->status->value);
        }

        if ($driver->verified_at === null) {
            throw DriverNotEligibleException::notVerified();
        }

        /*
         * Dokumen kadaluarsa menghalangi online.
         *
         * Diperiksa di sini, bukan hanya di panel admin, karena SIM dan STNK
         * kadaluarsa tanpa ada yang menekan tombol apa pun — hanya karena waktu
         * berjalan. Kalau pemeriksaannya hanya saat verifikasi, driver yang
         * SIM-nya habis bulan lalu tetap mengambil order sampai ada yang sempat
         * memeriksa manual.
         *
         * Yang dipertaruhkan bukan kepatuhan administratif: kalau terjadi
         * kecelakaan dengan driver tanpa SIM berlaku, tanggung jawabnya ada di
         * platform yang membiarkannya bekerja.
         */
        $expiredCount = $driver->documents()
            ->where('status', 'approved')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->count();

        if ($expiredCount > 0) {
            throw DriverNotEligibleException::expiredDocuments($expiredCount);
        }
    }

    private function resolveVehicle(Driver $driver, ?int $vehicleId): ?Vehicle
    {
        $query = $driver->vehicles()->where('is_active', true);

        if ($vehicleId !== null) {
            $query->where('id', $vehicleId);
        }

        return $query->first();
    }

    /**
     * Layanan yang boleh dia terima sekarang.
     *
     * @param  array<int, string>|null  $requested
     * @return array<int, string>
     */
    private function resolveServices(Driver $driver, ?array $requested, ?Vehicle $vehicle): array
    {
        $eligible = DB::table('driver_service_eligibility')
            ->join('service_types', 'service_types.id', '=', 'driver_service_eligibility.service_type_id')
            ->where('driver_service_eligibility.driver_id', $driver->getKey())
            ->where('driver_service_eligibility.is_enabled', true)

            // `enabled_by_driver` adalah saklar milik driver sendiri: dia boleh
            // memilih tidak menerima order makanan hari ini tanpa kehilangan
            // haknya. Dua kolom, bukan satu, karena keduanya punya pemilik
            // berbeda — yang satu ops, yang satu driver.
            ->where('driver_service_eligibility.enabled_by_driver', true)

            ->where('service_types.is_active', true)
            ->pluck('service_types.code')
            ->map(static fn ($code): string => (string) $code)
            ->all();

        /*
         * Kelas kendaraan menentukan layanan yang mungkin.
         *
         * Driver bermotor tidak bisa menerima order ride_car, sekalipun tabel
         * eligibility mengizinkannya. Tanpa pemeriksaan ini, penumpang yang
         * memesan mobil bisa dijemput sepeda motor — dan itu bukan sesuatu yang
         * bisa diperbaiki setelah drivernya sampai.
         */
        if ($vehicle !== null) {
            $eligible = array_values(array_filter(
                $eligible,
                fn (string $code): bool => $this->vehicleFitsService($vehicle, $code),
            ));
        }

        if ($requested === null) {
            return $eligible;
        }

        // Yang diminta driver harus merupakan bagian dari yang dia berhak.
        return array_values(array_intersect($eligible, $requested));
    }

    private function vehicleFitsService(Vehicle $vehicle, string $serviceCode): bool
    {
        $requiredClass = DB::table('service_types')
            ->where('code', $serviceCode)
            ->value('vehicle_class');

        return $requiredClass === null || $requiredClass === $vehicle->type;
    }

    /**
     * Buka sesi kerja, atau pakai yang sudah terbuka.
     *
     * `driver_sessions_one_open` adalah partial unique index yang menjamin satu
     * driver hanya punya satu sesi terbuka. Kalau driver menekan "online" dua
     * kali — yang sering terjadi saat sinyal jelek dan tombolnya tidak terasa
     * merespons — INSERT kedua ditolak database, dan yang benar adalah memakai
     * sesi yang sudah ada, bukan melempar error ke driver yang tidak melakukan
     * kesalahan apa pun.
     */
    private function openSession(Driver $driver, ?Vehicle $vehicle, Coordinate $at): DriverSession
    {
        $existing = $driver->sessions()->whereNull('ended_at')->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DriverSession::create([
                'driver_id' => $driver->getKey(),
                'vehicle_id' => $vehicle?->getKey(),
                'started_at' => now(),
                'start_lat' => $at->lat,
                'start_lng' => $at->lng,
            ]);
        } catch (QueryException $e) {
            if (! str_contains($e->getMessage(), 'driver_sessions_one_open')) {
                throw $e;
            }

            // Balapan sempit: sesi dibuka request lain antara SELECT dan INSERT.
            $session = $driver->sessions()->whereNull('ended_at')->first();

            if ($session === null) {
                throw $e;
            }

            return $session;
        }
    }
}
