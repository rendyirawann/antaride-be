<?php

declare(strict_types=1);

namespace App\Infrastructure\Routing;

use App\Domain\Pricing\Contracts\RoutingService;
use App\Domain\Pricing\DTOs\RouteResult;
use App\Domain\Pricing\Exceptions\RoutingUnavailableException;
use App\Domain\Shared\ValueObjects\Coordinate;
use App\Domain\Shared\ValueObjects\Polyline;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Perhitungan rute lewat OSRM self-host.
 *
 * Timeout dibuat pendek (3 detik). Ini keputusan yang disengaja: kalau OSRM
 * lambat, lebih baik quote gagal cepat dan pengguna mencoba lagi daripada
 * worker Octane tertahan menunggu. Dengan empat worker, empat permintaan yang
 * menggantung berarti seluruh API berhenti melayani.
 *
 * TIDAK ADA FALLBACK KE HAVERSINE. Ini penting dan sering salah dibangun.
 * Jarak garis lurus di kota dengan jalan satu arah dan sungai bisa setengah
 * dari jarak tempuh sebenarnya, jadi fallback semacam itu bukan degradasi
 * layanan, tapi menagih pengguna setengah harga tanpa ada yang tahu. Lebih baik
 * gagal terang-terangan.
 */
class OsrmRoutingService implements RoutingService
{
    public function route(Coordinate $origin, Coordinate $destination): RouteResult
    {
        return $this->routeVia([$origin, $destination]);
    }

    /**
     * @param  array<int, Coordinate>  $waypoints
     */
    public function routeVia(array $waypoints): RouteResult
    {
        if (count($waypoints) < 2) {
            throw new RoutingUnavailableException('Rute membutuhkan minimal dua titik.');
        }

        $path = $this->coordinatePath($waypoints);
        $profile = config('services.osrm.profile', 'driving');

        $response = $this->request()->get("/route/v1/{$profile}/{$path}", [
            // Polyline terenkode, bukan GeoJSON. Ukurannya jauh lebih kecil dan
            // formatnya langsung dipahami MapLibre di sisi Flutter tanpa
            // konversi.
            'overview' => 'full',
            'geometries' => 'polyline',
            // Perhentian di tengah tidak dianggap sebagai jeda perjalanan.
            'continue_straight' => 'false',
        ]);

        $data = $this->decode($response, 'route');
        $route = $data['routes'][0] ?? null;

        if (! is_array($route)) {
            throw new RoutingUnavailableException(
                'OSRM tidak mengembalikan rute untuk titik-titik yang diminta.',
            );
        }

        return new RouteResult(
            // OSRM memberi jarak dalam meter dan durasi dalam detik, keduanya
            // float. Dibulatkan ke atas supaya tarif tidak pernah kurang dari
            // jarak sebenarnya.
            distanceMeters: (int) ceil((float) ($route['distance'] ?? 0)),
            durationSeconds: (int) ceil((float) ($route['duration'] ?? 0)),
            polyline: isset($route['geometry']) && is_string($route['geometry'])
                ? Polyline::decode($route['geometry'])
                : Polyline::empty(),
        );
    }

    /**
     * @param  array<int, Coordinate>  $origins
     * @return array<int, int|null>
     */
    public function durationsTo(array $origins, Coordinate $destination): array
    {
        if ($origins === []) {
            return [];
        }

        // Semua titik asal ditambah tujuan di akhir.
        $all = [...array_values($origins), $destination];
        $path = $this->coordinatePath($all);
        $profile = config('services.osrm.profile', 'driving');

        $destinationIndex = count($all) - 1;
        $sourceIndexes = implode(';', range(0, $destinationIndex - 1));

        $response = $this->request()->get("/table/v1/{$profile}/{$path}", [
            'sources' => $sourceIndexes,
            'destinations' => (string) $destinationIndex,
            'annotations' => 'duration',
        ]);

        $data = $this->decode($response, 'table');

        /** @var array<int, array<int, float|null>> $durations */
        $durations = $data['durations'] ?? [];

        $result = [];

        foreach (array_keys($origins) as $position => $key) {
            $value = $durations[$position][0] ?? null;

            // OSRM mengembalikan null untuk titik yang tidak terhubung ke
            // jaringan jalan, misalnya koordinat di tengah danau. Itu bukan
            // error; kandidat itu memang harus dilewati matching.
            $result[$key] = $value === null ? null : (int) ceil((float) $value);
        }

        return $result;
    }

    public function isAvailable(): bool
    {
        try {
            // Rute pendek di Medan sebagai uji nyata. Ping saja tidak cukup:
            // OSRM yang jalan tapi datanya gagal dimuat akan menjawab port
            // tapi tidak bisa menghitung rute apa pun.
            $response = $this->request(timeout: 2)
                ->get('/route/v1/driving/98.6722,3.5952;98.6800,3.6000', [
                    'overview' => 'false',
                ]);

            return $response->successful() && $response->json('code') === 'Ok';
        } catch (\Throwable) {
            return false;
        }
    }

    // -------------------------------------------------------------------------

    private function request(?float $timeout = null): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.osrm.url'), '/'))
            ->timeout($timeout ?? (float) config('services.osrm.timeout', 3))
            ->connectTimeout((float) config('services.osrm.connect_timeout', 1))
            // Satu percobaan ulang saja. OSRM yang gagal sekali biasanya gagal
            // lagi, dan mengulang berkali-kali hanya memperpanjang waktu
            // pengguna menunggu sebelum akhirnya diberi tahu gagal.
            ->retry((int) config('services.osrm.retries', 1) + 1, 100, throw: false)
            ->acceptJson();
    }

    /**
     * OSRM menerima koordinat dalam urutan lng,lat, dipisah titik koma.
     *
     * Urutannya terbalik dari kebiasaan menyebut, sama seperti GeoJSON, dan
     * tertukar di sini berarti rute dihitung dari lokasi yang sama sekali lain
     * tanpa error apa pun. Karena itu konversinya lewat toGeoJsonPair() yang
     * urutannya sudah dijamin, bukan disusun manual.
     *
     * @param  array<int, Coordinate>  $coordinates
     */
    private function coordinatePath(array $coordinates): string
    {
        return implode(';', array_map(
            static function (Coordinate $coordinate): string {
                [$lng, $lat] = $coordinate->toGeoJsonPair();

                return sprintf('%.6F,%.6F', $lng, $lat);
            },
            $coordinates,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response, string $endpoint): array
    {
        if (! $response->successful()) {
            Log::warning('OSRM menjawab dengan status tidak sukses', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
            ]);

            throw new RoutingUnavailableException(
                "Mesin routing menjawab dengan status {$response->status()}.",
            );
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new RoutingUnavailableException('Balasan mesin routing tidak dapat dibaca.');
        }

        $code = $data['code'] ?? null;

        if ($code !== 'Ok') {
            // NoRoute berarti titiknya memang tidak terhubung, misalnya alamat
            // di pulau tanpa jalan. Ini bukan gangguan sistem, jadi tidak
            // dicatat sebagai warning.
            if ($code !== 'NoRoute') {
                Log::warning('OSRM mengembalikan kode bukan Ok', [
                    'endpoint' => $endpoint,
                    'code' => $code,
                ]);
            }

            throw new RoutingUnavailableException(
                $code === 'NoRoute'
                    ? 'Tidak ditemukan rute jalan antara titik-titik tersebut.'
                    : "Mesin routing mengembalikan kode: {$code}.",
            );
        }

        return $data;
    }
}
