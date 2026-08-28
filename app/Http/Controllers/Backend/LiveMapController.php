<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backend;

use App\Domain\Matching\Contracts\DriverLocationIndex;
use App\Domain\Matching\DTOs\DriverPosition;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Shared\ValueObjects\Coordinate;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Live map: driver dan order yang sedang berjalan.
 *
 * ============================================================================
 *  BATAS JUMLAH MARKER BUKAN OPTIMASI, TAPI SYARAT AGAR HALAMANNYA BERGUNA
 * ============================================================================
 *  Lima ratus marker di peta berarti lima ratus elemen DOM yang diperbarui
 *  setiap beberapa detik. Bedanya antara 60 fps dan 4 fps, dan pada 4 fps peta
 *  itu tidak bisa dipakai untuk apa pun.
 *
 *  Di atas batas, yang dikirim adalah agregat per grid — jumlah driver per
 *  kotak — bukan marker individual. Tim ops yang melihat seluruh kota memang
 *  tidak butuh titik per driver; yang dibutuhkan adalah tahu di mana
 *  kepadatannya.
 *
 *  Marker individual muncul saat petanya diperbesar, karena pada zoom itu
 *  jumlah driver dalam kotak pandangnya sudah kecil dengan sendirinya.
 * ============================================================================
 */
class LiveMapController extends Controller
{
    public function index(): View
    {
        return view('backend.livemap.index', [
            'zona' => DB::table('zones')
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'center_lat', 'center_lng', 'polygon_geojson']),

            'layanan' => DB::table('service_types')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(['code', 'name']),

            /*
             * Pusat peta awal dari config, bukan dari zona pertama.
             *
             * Zona pertama menurut abjad bisa berada di pinggir kota, dan peta
             * yang terbuka di pinggir menuntut setiap staf menggesernya setiap
             * kali membuka halaman.
             */
            'pusat' => [
                'lat' => (float) config('antaride.live_map.center_lat', 3.5952),
                'lng' => (float) config('antaride.live_map.center_lng', 98.6722),
                'zoom' => (int) config('antaride.live_map.default_zoom', 12),
            ],

            'intervalRefreshMs' => (int) config('antaride.live_map.refresh_interval_ms', 5000),
        ]);
    }

    /**
     * Data peta: driver dan order dalam kotak pandang.
     */
    public function data(Request $request): JsonResponse
    {
        $request->validate([
            'sw_lat' => ['required', 'numeric', 'between:-90,90'],
            'sw_lng' => ['required', 'numeric', 'between:-180,180'],
            'ne_lat' => ['required', 'numeric', 'between:-90,90'],
            'ne_lng' => ['required', 'numeric', 'between:-180,180'],
            'service_code' => ['nullable', 'string'],
        ]);

        $barat = Coordinate::of(
            (float) $request->input('sw_lat'),
            (float) $request->input('sw_lng'),
        );
        $timur = Coordinate::of(
            (float) $request->input('ne_lat'),
            (float) $request->input('ne_lng'),
        );

        $batasMarker = (int) config('antaride.live_map.max_markers', 500);

        $posisi = $this->posisiDriver($request, $barat, $timur, $batasMarker);

        return response()->json([
            'driver' => count($posisi) >= $batasMarker
                ? ['mode' => 'cluster', 'grid' => $this->kelompokkanKeGrid($posisi)]
                : ['mode' => 'marker', 'items' => array_map(
                    fn (DriverPosition $p): array => [
                        'driver_id' => $p->driverId,
                        'lat' => $p->coordinate->lat,
                        'lng' => $p->coordinate->lng,
                        'heading' => $p->heading,
                        'speed_kmh' => $p->speedKmh,

                        // Posisi berakurasi buruk ditandai, bukan dibuang.
                        // Tim ops perlu tahu bahwa titik itu tidak bisa dipercaya
                        // untuk menilai apakah driver benar-benar di titik jemput.
                        'kualitas_rendah' => $p->lowQuality,

                        'usia_detik' => $p->timestamp === null
                            ? null
                            : max(0, time() - $p->timestamp),
                    ],
                    $posisi,
                )],

            'order' => $this->orderBerjalan($barat, $timur),
            'waktu_server' => now()->toIso8601String(),
        ]);
    }

    // -------------------------------------------------------------------------

    /**
     * @return array<int, DriverPosition>
     */
    private function posisiDriver(
        Request $request,
        Coordinate $barat,
        Coordinate $timur,
        int $batas,
    ): array {
        $index = app(DriverLocationIndex::class);

        $kodeLayanan = $request->filled('service_code')
            ? [(string) $request->string('service_code')]
            : DB::table('service_types')->where('is_active', true)->pluck('code')->all();

        $hasil = [];

        foreach ($kodeLayanan as $kode) {
            foreach ($index->findInBox((string) $kode, $barat, $timur, $batas) as $posisi) {
                /*
                 * Dikunci driver_id supaya tidak dobel.
                 *
                 * Satu driver bisa terdaftar di beberapa layanan sekaligus —
                 * ojek dan antar barang, misalnya — dan tanpa penguncian ini dia
                 * akan muncul sebagai dua marker di titik yang sama. Yang
                 * terlihat: jumlah driver online di peta lebih besar daripada
                 * kenyataannya.
                 */
                $hasil[$posisi->driverId] = $posisi;
            }
        }

        return array_values($hasil);
    }

    /**
     * @param  array<int, DriverPosition>  $posisi
     * @return array<int, array<string, mixed>>
     */
    private function kelompokkanKeGrid(array $posisi): array
    {
        /*
         * Ukuran grid dalam derajat, bukan meter.
         *
         * 0,01 derajat sekitar 1,1 km di lintang Medan. Memakai derajat membuat
         * pengelompokannya bisa dihitung dengan pembulatan sederhana, tanpa
         * proyeksi — dan untuk menampilkan kepadatan di peta, ketepatan
         * proyeksi tidak menambah apa pun.
         */
        $ukuran = (float) config('antaride.live_map.cluster_grid_degrees', 0.01);

        $grid = [];

        foreach ($posisi as $p) {
            $kunciLat = floor($p->coordinate->lat / $ukuran) * $ukuran;
            $kunciLng = floor($p->coordinate->lng / $ukuran) * $ukuran;
            $kunci = $kunciLat.':'.$kunciLng;

            if (! isset($grid[$kunci])) {
                $grid[$kunci] = [
                    'lat' => $kunciLat + $ukuran / 2,
                    'lng' => $kunciLng + $ukuran / 2,
                    'jumlah' => 0,
                ];
            }

            $grid[$kunci]['jumlah']++;
        }

        return array_values($grid);
    }

    /**
     * Order yang sedang berjalan di kotak pandang.
     *
     * @return array<int, array<string, mixed>>
     */
    private function orderBerjalan(Coordinate $barat, Coordinate $timur): array
    {
        $ambangMacet = (int) config('antaride.live_map.stuck_order_highlight_seconds', 60);

        return DB::table('orders')
            ->whereIn('status', array_merge(
                OrderStatus::activeValues(),
                [OrderStatus::Searching->value],
            ))
            ->whereBetween('pickup_lat', [$barat->lat, $timur->lat])
            ->whereBetween('pickup_lng', [$barat->lng, $timur->lng])
            ->limit((int) config('antaride.live_map.max_markers', 500))
            ->get(['uuid', 'order_number', 'status', 'pickup_lat', 'pickup_lng', 'requested_at'])
            ->map(function ($o) use ($ambangMacet): array {
                $status = OrderStatus::from((string) $o->status);
                $menunggu = now()->diffInSeconds(Carbon::parse($o->requested_at), absolute: true);

                return [
                    'uuid' => (string) $o->uuid,
                    'nomor' => (string) $o->order_number,
                    'status' => $status->value,
                    'label' => $status->label(),
                    'lat' => (float) $o->pickup_lat,
                    'lng' => (float) $o->pickup_lng,

                    /*
                     * Order macet ditandai supaya bisa diberi warna berbeda.
                     *
                     * Dihitung backend, bukan di JavaScript. Kalau dihitung di
                     * frontend, ambangnya harus disalin ke sana — dan mengubahnya
                     * berarti mengubah dua tempat, yang salah satunya akan
                     * tertinggal.
                     */
                    'macet' => $status === OrderStatus::Searching && $menunggu > $ambangMacet,
                    'menunggu_detik' => $menunggu,
                ];
            })
            ->all();
    }
}
