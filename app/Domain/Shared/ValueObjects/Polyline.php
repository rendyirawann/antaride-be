<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObjects;

use Countable;
use Stringable;

/**
 * Rangkaian titik perjalanan, dengan encoding dan penyederhanaan.
 *
 * Dua kegunaannya di sistem ini:
 *
 *   1. `orders.route_polyline`  — rute hasil OSRM saat estimasi, dipakai
 *      menggambar rute yang direncanakan di peta.
 *   2. `orders.actual_polyline` — rute sungguhan dari ping GPS, diisi saat
 *      order selesai.
 *
 * Keduanya ditumpuk di halaman detail order panel admin. Itu yang menjawab
 * pertanyaan "kenapa ongkosnya beda dari estimasi" tanpa perlu berdebat.
 *
 * Kenapa disederhanakan sebelum disimpan: satu perjalanan 20 menit dengan ping
 * tiap 4 detik menghasilkan 300 titik. Sembilan puluh persen di antaranya
 * berada di garis yang sama dan tidak menambah informasi apa pun, tapi tetap
 * memakan ruang dan memperlambat penggambaran di peta. Douglas-Peucker
 * membuang titik yang tidak mengubah bentuk garis.
 */
final readonly class Polyline implements Countable, Stringable
{
    /**
     * @param  array<int, Coordinate>  $points
     */
    private function __construct(
        public array $points,
    ) {}

    /**
     * @param  array<int, Coordinate>  $points
     */
    public static function of(array $points): self
    {
        return new self(array_values($points));
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Dari string terenkode Google Encoded Polyline Algorithm Format.
     *
     * Ini format yang dikembalikan OSRM dan dipahami MapLibre serta seluruh
     * pustaka peta Flutter, jadi tidak perlu konversi di sisi mobile.
     */
    public static function decode(string $encoded, int $precision = 5): self
    {
        $points = [];
        $index = 0;
        $lat = 0;
        $lng = 0;
        $length = strlen($encoded);
        $factor = 10 ** $precision;

        while ($index < $length) {
            // Setiap nilai dikodekan sebagai selisih dari nilai sebelumnya,
            // dalam potongan 5 bit dengan bit penanda lanjutan.
            foreach (['lat', 'lng'] as $axis) {
                $shift = 0;
                $result = 0;

                do {
                    if ($index >= $length) {
                        break 2;
                    }

                    $byte = ord($encoded[$index++]) - 63;
                    $result |= ($byte & 0x1F) << $shift;
                    $shift += 5;
                } while ($byte >= 0x20);

                // Bit terakhir menandai negatif (zigzag encoding).
                $delta = ($result & 1) !== 0 ? ~($result >> 1) : ($result >> 1);

                if ($axis === 'lat') {
                    $lat += $delta;
                } else {
                    $lng += $delta;
                }
            }

            $points[] = Coordinate::of($lat / $factor, $lng / $factor);
        }

        return new self($points);
    }

    /**
     * Ke string terenkode.
     */
    public function encode(int $precision = 5): string
    {
        $factor = 10 ** $precision;
        $output = '';
        $prevLat = 0;
        $prevLng = 0;

        foreach ($this->points as $point) {
            $lat = (int) round($point->lat * $factor);
            $lng = (int) round($point->lng * $factor);

            $output .= $this->encodeValue($lat - $prevLat);
            $output .= $this->encodeValue($lng - $prevLng);

            $prevLat = $lat;
            $prevLng = $lng;
        }

        return $output;
    }

    // -------------------------------------------------------------------------
    // Penyederhanaan
    // -------------------------------------------------------------------------

    /**
     * Douglas-Peucker: buang titik yang tidak mengubah bentuk garis.
     *
     * Toleransi dalam derajat. Nilai default dari config, sekitar 5 meter.
     * Titik pertama dan terakhir SELALU dipertahankan, karena itu titik jemput
     * dan titik tujuan.
     *
     * Implementasinya iteratif, bukan rekursif. Alasannya bukan gaya: rekursi
     * pada 300 titik aman, tapi ping GPS dari perjalanan lintas kota bisa
     * menghasilkan ribuan titik, dan stack overflow di worker settlement berarti
     * order selesai tanpa polyline tanpa ada yang tahu kenapa.
     */
    public function simplified(?float $tolerance = null): self
    {
        $tolerance ??= (float) config('antaride.gps.polyline_simplify_tolerance', 0.00005);

        $count = count($this->points);

        if ($count <= 2) {
            return $this;
        }

        $keep = array_fill(0, $count, false);
        $keep[0] = true;
        $keep[$count - 1] = true;

        // Setiap elemen stack adalah rentang [awal, akhir] yang masih perlu
        // diperiksa.
        $stack = [[0, $count - 1]];

        while ($stack !== []) {
            [$first, $last] = array_pop($stack);

            $maxDistance = 0.0;
            $farthest = 0;

            for ($i = $first + 1; $i < $last; $i++) {
                $distance = $this->perpendicularDistance(
                    $this->points[$i],
                    $this->points[$first],
                    $this->points[$last],
                );

                if ($distance > $maxDistance) {
                    $maxDistance = $distance;
                    $farthest = $i;
                }
            }

            // Titik terjauh masih di luar toleransi, jadi dia bermakna dan
            // rentangnya dipecah dua.
            if ($maxDistance > $tolerance && $farthest > 0) {
                $keep[$farthest] = true;
                $stack[] = [$first, $farthest];
                $stack[] = [$farthest, $last];
            }
        }

        $simplified = [];

        foreach ($this->points as $index => $point) {
            if ($keep[$index]) {
                $simplified[] = $point;
            }
        }

        return new self($simplified);
    }

    /**
     * Jarak tegak lurus sebuah titik ke garis antara dua titik lain.
     *
     * Dihitung di ruang derajat, bukan meter. Untuk toleransi sekecil ini dan
     * pada lintang Indonesia yang dekat ekuator, distorsinya tidak bermakna,
     * dan menghindari konversi ke meter membuat perhitungannya jauh lebih murah
     * saat dipanggil ribuan kali.
     */
    private function perpendicularDistance(
        Coordinate $point,
        Coordinate $lineStart,
        Coordinate $lineEnd,
    ): float {
        $dx = $lineEnd->lng - $lineStart->lng;
        $dy = $lineEnd->lat - $lineStart->lat;

        // Garis yang kedua ujungnya sama: jaraknya jarak ke titik itu.
        if ($dx === 0.0 && $dy === 0.0) {
            return sqrt(
                ($point->lng - $lineStart->lng) ** 2
                + ($point->lat - $lineStart->lat) ** 2
            );
        }

        $numerator = abs(
            $dy * ($point->lng - $lineStart->lng)
            - $dx * ($point->lat - $lineStart->lat)
        );

        return $numerator / sqrt($dx ** 2 + $dy ** 2);
    }

    // -------------------------------------------------------------------------
    // Ukuran
    // -------------------------------------------------------------------------

    /**
     * Total panjang garis dalam meter, mengikuti titik-titiknya.
     *
     * Untuk `actual_polyline`, ini yang dibandingkan dengan `distance_m` hasil
     * estimasi. Selisih di atas ambang menandai order untuk direview, bukan
     * di-settle otomatis.
     */
    public function lengthMeters(): float
    {
        $total = 0.0;
        $count = count($this->points);

        for ($i = 1; $i < $count; $i++) {
            $total += $this->points[$i - 1]->distanceTo($this->points[$i]);
        }

        return $total;
    }

    public function first(): ?Coordinate
    {
        return $this->points[0] ?? null;
    }

    public function last(): ?Coordinate
    {
        return $this->points === [] ? null : $this->points[count($this->points) - 1];
    }

    public function count(): int
    {
        return count($this->points);
    }

    public function isEmpty(): bool
    {
        return $this->points === [];
    }

    /**
     * @return array<int, array{0: float, 1: float}>
     */
    public function toGeoJsonCoordinates(): array
    {
        return array_map(
            static fn (Coordinate $point) => $point->toGeoJsonPair(),
            $this->points,
        );
    }

    public function __toString(): string
    {
        return $this->encode();
    }

    // -------------------------------------------------------------------------

    private function encodeValue(int $value): string
    {
        // Zigzag: geser satu bit ke kiri, negatif dibalik seluruh bitnya.
        $value = $value < 0 ? ~($value << 1) : ($value << 1);
        $output = '';

        while ($value >= 0x20) {
            $output .= chr((0x20 | ($value & 0x1F)) + 63);
            $value >>= 5;
        }

        return $output.chr($value + 63);
    }
}
