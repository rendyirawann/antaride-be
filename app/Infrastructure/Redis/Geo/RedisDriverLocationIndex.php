<?php

declare(strict_types=1);

namespace App\Infrastructure\Redis\Geo;

use App\Domain\Matching\Contracts\DriverLocationIndex;
use App\Domain\Matching\DTOs\DriverPosition;
use App\Domain\Matching\Exceptions\RedisGeoCommandException;
use App\Domain\Shared\ValueObjects\Coordinate;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

/**
 * Indeks posisi driver di Redis.
 *
 * ============================================================================
 *  DUA HAL YANG MUDAH SALAH DI SINI, DAN KEDUANYA GAGAL DALAM DIAM
 * ============================================================================
 *
 *  1. KONEKSI TANPA PREFIX.
 *     Nama key di bawah dibagi dengan location service Go yang menulisnya.
 *     Laravel secara default menempelkan prefix "antaride-database-" ke setiap
 *     key. Kalau class ini memakai koneksi default, dia akan membaca
 *     "antaride-database-drv:loc:ride_bike" sementara Go menulis
 *     "drv:loc:ride_bike". Hasilnya: matching selalu melaporkan tidak ada
 *     driver, tanpa satu pun error muncul, sementara app driver menunjukkan
 *     dirinya online.
 *
 *  2. GEORADIUS, BUKAN GEOSEARCH.
 *     GEOSEARCH baru ada di Redis 6.2. Build Windows yang umum dipakai untuk
 *     pengembangan masih 5.0, dan di sana GEOSEARCH tidak dikenal sama sekali.
 *     Perintah dipilih lewat config supaya produksi (Redis 7) bisa memakai
 *     GEOSEARCH tanpa mengubah kode matching.
 *
 * ============================================================================
 *
 * Bentuk key, sesuai blueprint bagian 3.9:
 *
 *   drv:loc:{service}                 GEO set posisi driver per layanan
 *   drv:meta:{driverId}               HASH heading/speed/akurasi/timestamp
 *   drv:available:{service}:zone:{id} SET driver yang siap terima order
 *   drv:zones:{driverId}              SET kombinasi tempat driver terdaftar
 */
class RedisDriverLocationIndex implements DriverLocationIndex
{
    /**
     * Umur metadata posisi. Lebih pendek dari ini membuat driver hilang dari
     * peta di sela ping; lebih panjang membuat driver yang ponselnya mati
     * tetap terlihat online.
     */
    private const META_TTL_SECONDS = 60;

    public function findNearby(
        string $serviceCode,
        Coordinate $center,
        int $radiusMeters,
        int $limit = 20,
    ): array {
        $members = $this->geoSearchNearby($serviceCode, $center, $radiusMeters, $limit);

        if ($members === []) {
            return [];
        }

        // Jarak dari hasil GEO dipertahankan. Menghitungnya ulang di PHP dengan
        // haversine akan memberi angka yang sedikit berbeda dari yang dipakai
        // Redis untuk mengurutkan, dan skor matching jadi tidak konsisten
        // dengan urutan kandidat.
        $distances = [];
        $driverIds = [];

        foreach ($members as $member) {
            $driverId = $this->driverIdFromMember((string) $member[0]);

            if ($driverId === null) {
                continue;
            }

            $driverIds[] = $driverId;
            $distances[$driverId] = (float) $member[1];
        }

        $positions = $this->positionsOf($driverIds);

        foreach ($positions as $index => $position) {
            $positions[$index] = new DriverPosition(
                driverId: $position->driverId,
                coordinate: $position->coordinate,
                heading: $position->heading,
                speedKmh: $position->speedKmh,
                accuracyM: $position->accuracyM,
                timestamp: $position->timestamp,
                batteryPercent: $position->batteryPercent,
                lowQuality: $position->lowQuality,
                distanceM: $distances[$position->driverId] ?? null,
            );
        }

        // Urutan dari Redis sudah terdekat lebih dulu, tapi positionsOf()
        // membuang driver yang metadatanya sudah kadaluarsa, jadi diurutkan
        // ulang untuk memastikan.
        usort(
            $positions,
            static fn (DriverPosition $a, DriverPosition $b) => ($a->distanceM ?? INF) <=> ($b->distanceM ?? INF),
        );

        return $positions;
    }

    /**
     * @return array<int, int>
     */
    public function availableDriverIds(string $serviceCode, array $zoneIds): array
    {
        if ($zoneIds === []) {
            return [];
        }

        $keys = array_map(
            fn (int $zoneId) => $this->availableKey($serviceCode, $zoneId),
            array_values(array_unique($zoneIds)),
        );

        // SUNION, bukan beberapa SMEMBERS lalu digabung di PHP. Satu round trip
        // alih-alih satu per zona, dan deduplikasinya dilakukan Redis.
        //
        // SINTERCARD dan variannya baru ada di Redis 7, jadi tidak dipakai.
        $members = $this->connection()->sunion($keys);

        $ids = [];

        foreach ($members as $member) {
            $id = filter_var($member, FILTER_VALIDATE_INT);

            if ($id !== false) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public function positionOf(int $driverId): ?DriverPosition
    {
        $meta = $this->connection()->hgetall($this->metaKey($driverId));

        if ($meta === []) {
            return null;
        }

        return DriverPosition::fromRedisHash($driverId, $meta);
    }

    /**
     * @param  array<int, int>  $driverIds
     * @return array<int, DriverPosition>
     */
    public function positionsOf(array $driverIds): array
    {
        if ($driverIds === []) {
            return [];
        }

        // Pipeline: satu round trip untuk semua driver. Live map dengan 500
        // marker akan melakukan 500 round trip tanpa ini.
        $raw = $this->connection()->pipeline(function ($pipe) use ($driverIds): void {
            foreach ($driverIds as $driverId) {
                $pipe->hgetall($this->metaKey($driverId));
            }
        });

        $positions = [];

        foreach (array_values($driverIds) as $index => $driverId) {
            $meta = $raw[$index] ?? [];

            if (! is_array($meta) || $meta === []) {
                continue;
            }

            $position = DriverPosition::fromRedisHash($driverId, $meta);

            if ($position !== null) {
                $positions[] = $position;
            }
        }

        return $positions;
    }

    public function record(
        string $serviceCode,
        int $driverId,
        Coordinate $coordinate,
        ?float $heading = null,
        ?float $speedKmh = null,
        ?float $accuracyM = null,
        ?int $batteryPercent = null,
    ): void {
        $connection = $this->connection();
        $member = $this->member($driverId);

        $meta = array_filter([
            'lat' => (string) $coordinate->lat,
            'lng' => (string) $coordinate->lng,
            'heading' => $heading !== null ? (string) $heading : null,
            'speed' => $speedKmh !== null ? (string) $speedKmh : null,
            'acc' => $accuracyM !== null ? (string) $accuracyM : null,
            'battery' => $batteryPercent !== null ? (string) $batteryPercent : null,
            'ts' => (string) now()->getTimestamp(),
        ], static fn ($value) => $value !== null);

        $connection->pipeline(function ($pipe) use ($serviceCode, $member, $coordinate, $driverId, $meta): void {
            $pipe->geoadd(
                $this->locationKey($serviceCode),
                $coordinate->lng,
                $coordinate->lat,
                $member,
            );

            $pipe->hmset($this->metaKey($driverId), $meta);
            $pipe->expire($this->metaKey($driverId), self::META_TTL_SECONDS);
        });
    }

    public function markAvailable(string $serviceCode, int $zoneId, int $driverId): void
    {
        $connection = $this->connection();

        $connection->pipeline(function ($pipe) use ($serviceCode, $zoneId, $driverId): void {
            $pipe->sadd($this->availableKey($serviceCode, $zoneId), $driverId);

            // Catat di mana saja driver ini terdaftar, supaya bisa dicabut
            // seluruhnya nanti tanpa harus menebak kombinasi layanan dan zona.
            //
            // Tanpa ini, driver yang offline saat sedang terdaftar di tiga zona
            // akan meninggalkan sisa di dua zona, dan order akan ditawarkan ke
            // orang yang sudah pulang.
            $pipe->sadd($this->zonesKey($driverId), "{$serviceCode}:{$zoneId}");
        });
    }

    public function markUnavailable(string $serviceCode, int $zoneId, int $driverId): void
    {
        $connection = $this->connection();

        $connection->pipeline(function ($pipe) use ($serviceCode, $zoneId, $driverId): void {
            $pipe->srem($this->availableKey($serviceCode, $zoneId), $driverId);
            $pipe->srem($this->zonesKey($driverId), "{$serviceCode}:{$zoneId}");
        });
    }

    public function markUnavailableEverywhere(int $driverId): void
    {
        $connection = $this->connection();
        $registrations = $connection->smembers($this->zonesKey($driverId));

        if ($registrations === []) {
            $connection->del($this->zonesKey($driverId));

            return;
        }

        $connection->pipeline(function ($pipe) use ($registrations, $driverId): void {
            foreach ($registrations as $registration) {
                [$serviceCode, $zoneId] = array_pad(explode(':', (string) $registration, 2), 2, null);

                if ($serviceCode === null || $zoneId === null) {
                    continue;
                }

                $pipe->srem($this->availableKey($serviceCode, (int) $zoneId), $driverId);
            }

            $pipe->del($this->zonesKey($driverId));
        });
    }

    public function availableCount(string $serviceCode, int $zoneId): int
    {
        return (int) $this->connection()->scard($this->availableKey($serviceCode, $zoneId));
    }

    /**
     * @return array<int, DriverPosition>
     */
    public function findInBox(
        string $serviceCode,
        Coordinate $southWest,
        Coordinate $northEast,
        int $limit = 500,
    ): array {
        // Redis 5.0 tidak punya GEOSEARCH BYBOX, jadi kotaknya didekati dengan
        // lingkaran yang melingkupinya lalu hasilnya disaring ke kotak asli.
        //
        // Lingkaran selalu lebih luas dari kotak, jadi tidak ada driver di dalam
        // kotak yang terlewat. Yang terjadi hanya sedikit pekerjaan penyaringan
        // tambahan di PHP, dan itu jauh lebih murah daripada memelihara indeks
        // kedua hanya untuk live map.
        $center = Coordinate::of(
            ($southWest->lat + $northEast->lat) / 2,
            ($southWest->lng + $northEast->lng) / 2,
        );

        // Radius = setengah diagonal kotak, dilebihkan sedikit untuk menutup
        // kesalahan pembulatan.
        $radius = (int) ceil($center->distanceTo($northEast) * 1.05);

        // Ambil lebih banyak dari limit karena sebagian akan terbuang oleh
        // penyaringan kotak. Faktor 3 kira-kira rasio luas lingkaran pembungkus
        // terhadap kotak untuk bentuk viewport yang umum.
        $candidates = $this->findNearby($serviceCode, $center, $radius, $limit * 3);

        $inBox = [];

        foreach ($candidates as $position) {
            $withinBox = $position->coordinate->lat >= $southWest->lat
                && $position->coordinate->lat <= $northEast->lat
                && $position->coordinate->lng >= $southWest->lng
                && $position->coordinate->lng <= $northEast->lng;

            if (! $withinBox) {
                continue;
            }

            $inBox[] = $position;

            if (count($inBox) >= $limit) {
                break;
            }
        }

        return $inBox;
    }

    public function forget(int $driverId): void
    {
        $this->markUnavailableEverywhere($driverId);

        $connection = $this->connection();
        $connection->del($this->metaKey($driverId));

        // Hapus dari setiap GEO set layanan. Jumlah layanan sedikit dan tetap,
        // jadi ini murah.
        foreach ($this->knownServiceCodes() as $serviceCode) {
            $connection->zrem($this->locationKey($serviceCode), $this->member($driverId));
        }
    }

    // -------------------------------------------------------------------------
    // Perintah GEO
    // -------------------------------------------------------------------------

    /**
     * Pencarian radius, dengan perintah yang sesuai versi Redis.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function geoSearchNearby(
        string $serviceCode,
        Coordinate $center,
        int $radiusMeters,
        int $limit,
    ): array {
        $key = $this->locationKey($serviceCode);
        $useGeoSearch = config('antaride.geo.redis_command') === 'geosearch';

        $arguments = $useGeoSearch
            ? [
                'GEOSEARCH', $key,
                'FROMLONLAT', (string) $center->lng, (string) $center->lat,
                'BYRADIUS', (string) $radiusMeters, 'm',
                'WITHDIST',
                'ASC',
                'COUNT', (string) $limit,
            ]
            : [
                'GEORADIUS', $key,
                (string) $center->lng, (string) $center->lat,
                (string) $radiusMeters, 'm',
                'WITHDIST',
                'ASC',
                'COUNT', (string) $limit,
            ];

        $raw = $this->executeRaw($arguments);

        // Redis TIDAK melempar exception untuk perintah yang tidak dikenal saat
        // dipanggil sebagai perintah mentah; dia mengembalikan pesan errornya
        // sebagai string biasa.
        //
        // Ini sudah diuji langsung: GEOSEARCH pada Redis 5.0 mengembalikan
        // "ERR unknown command `GEOSEARCH`" alih-alih melempar. Kalau string itu
        // dibiarkan jatuh ke pemeriksaan is_array() lalu dikembalikan sebagai
        // array kosong, akibatnya adalah matching yang melaporkan "tidak ada
        // driver" pada setiap order, tanpa satu pun baris di log yang
        // menjelaskan kenapa. Salah setel satu variabel env, dan sistem berhenti
        // menerima order dalam diam.
        //
        // Karena itu di sini digagalkan keras, dengan pesan yang menyebutkan apa
        // yang harus diubah.
        if (is_string($raw) && str_starts_with($raw, 'ERR')) {
            throw new RedisGeoCommandException(
                sprintf(
                    'Redis menolak perintah GEO: %s. Setel REDIS_GEO_COMMAND=%s di .env. '
                    .'GEOSEARCH hanya ada di Redis 6.2 atau lebih baru.',
                    $raw,
                    $useGeoSearch ? 'georadius' : 'geosearch',
                ),
            );
        }

        if (! is_array($raw)) {
            throw new RedisGeoCommandException(
                'Bentuk balasan Redis untuk perintah GEO tidak dikenali: '.get_debug_type($raw)
            );
        }

        // Bentuk hasil kedua perintah sama: [[member, distance], ...]
        return array_values(array_filter(
            $raw,
            static fn ($row) => is_array($row) && count($row) >= 2,
        ));
    }

    // -------------------------------------------------------------------------
    // Key & koneksi
    // -------------------------------------------------------------------------

    /**
     * Koneksi 'shared' WAJIB dipakai. Alasannya di docblock class.
     */
    private function connection(): Connection
    {
        return Redis::connection('shared');
    }

    /**
     * Jalankan perintah Redis mentah, tanpa melewati class perintah klien.
     *
     * Kenapa mentah dan tidak lewat $connection->command().
     *
     * Predis punya class perintah sendiri untuk GEORADIUS dan GEOSEARCH yang
     * MENAFSIRKAN ULANG argumennya di sisi klien, dengan urutan dan bentuk yang
     * berbeda dari protokol Redis. Phpredis menafsirkannya dengan cara lain
     * lagi. Akibatnya, kode yang sama bisa mengirim perintah yang berbeda
     * tergantung klien mana yang dipakai, dan itu berarti dev (predis) dan
     * produksi (phpredis) tidak menjalankan hal yang sama.
     *
     * Ini sudah terbukti: pemanggilan lewat class perintah predis melempar
     * "Sorting argument accepts only: asc, desc values" untuk argumen yang
     * diterima Redis tanpa masalah.
     *
     * Dengan perintah mentah, yang dikirim adalah persis apa yang tertulis, dan
     * yang diterima adalah persis balasan Redis, termasuk pesan errornya.
     *
     * @param  array<int, string>  $arguments
     */
    private function executeRaw(array $arguments): mixed
    {
        $client = $this->connection()->client();

        try {
            // Predis
            if (method_exists($client, 'executeRaw')) {
                return $client->executeRaw($arguments);
            }

            // Phpredis
            if (method_exists($client, 'rawCommand')) {
                return $client->rawCommand(...$arguments);
            }
        } catch (\Throwable $e) {
            throw new RedisGeoCommandException(
                'Perintah GEO Redis gagal: '.$e->getMessage(),
            );
        }

        throw new RedisGeoCommandException(
            'Klien Redis '.get_debug_type($client).' tidak mendukung perintah mentah.',
        );
    }

    private function locationKey(string $serviceCode): string
    {
        return "drv:loc:{$serviceCode}";
    }

    private function metaKey(int $driverId): string
    {
        return "drv:meta:{$driverId}";
    }

    private function availableKey(string $serviceCode, int $zoneId): string
    {
        return "drv:available:{$serviceCode}:zone:{$zoneId}";
    }

    private function zonesKey(int $driverId): string
    {
        return "drv:zones:{$driverId}";
    }

    /**
     * Member GEO set diberi awalan "driver:" sesuai blueprint, supaya key yang
     * sama tidak pernah tertukar dengan id jenis lain kalau nanti ada kendaraan
     * atau kurir yang ikut diindeks.
     */
    private function member(int $driverId): string
    {
        return "driver:{$driverId}";
    }

    private function driverIdFromMember(string $member): ?int
    {
        $value = str_starts_with($member, 'driver:')
            ? substr($member, 7)
            : $member;

        $id = filter_var($value, FILTER_VALIDATE_INT);

        return $id === false ? null : $id;
    }

    /**
     * @return array<int, string>
     */
    private function knownServiceCodes(): array
    {
        return ['ride_bike', 'ride_car', 'food', 'send', 'mart', 'shop'];
    }
}
