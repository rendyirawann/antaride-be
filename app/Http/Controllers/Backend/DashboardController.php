<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backend;

use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Shared\Support\BusinessClock;
use App\Domain\Shared\ValueObjects\Money;
use App\Http\Controllers\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Dashboard operasional.
 *
 * ============================================================================
 *  ANGKA HARI INI DIHITUNG LANGSUNG, TREN DIBACA DARI TABEL AGREGAT
 * ============================================================================
 *  Dua sumber yang berbeda, dan pemisahannya penting:
 *
 *    HARI INI    dihitung dari tabel orders, karena harus akurat sampai menit
 *                terakhir. Tim ops memakainya untuk keputusan sekarang, dan
 *                angka yang tertinggal satu jam tidak berguna untuk itu.
 *
 *    TREN        dibaca dari metrics_daily. Menghitung 30 hari dari tabel orders
 *                berarti memindai ratusan ribu baris setiap kali dashboard
 *                dibuka — dan dashboard adalah halaman yang paling sering
 *                dimuat ulang di seluruh panel.
 *
 *  Batas hari memakai zona BISNIS. Tanpa itu, "hari ini" di dashboard berganti
 *  pada jam 7 pagi WIB, dan staf yang membuka panel jam 6 pagi melihat angka
 *  yang sudah termasuk hari sebelumnya.
 * ============================================================================
 */
class DashboardController extends Controller
{
    public function index(): View
    {
        [$mulaiHariIni, $selesaiHariIni] = BusinessClock::dayRange();

        return view('backend.dashboard.index', [
            'hariIni' => $this->ringkasanHari($mulaiHariIni, $selesaiHariIni),
            'kemarin' => $this->ringkasanHari(
                BusinessClock::dayRange(now()->subDay())[0],
                BusinessClock::dayRange(now()->subDay())[1],
            ),
            'tren' => $this->tren14Hari(),
            'perZona' => $this->perZonaHariIni($mulaiHariIni, $selesaiHariIni),
            'statusBerjalan' => $this->sebaranStatusBerjalan(),
            'orderMacet' => $this->orderMacet(),
        ]);
    }

    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function ringkasanHari(\DateTimeInterface $mulai, \DateTimeInterface $selesai): array
    {
        $baris = DB::table('orders')
            ->whereBetween('requested_at', [$mulai, $selesai])
            ->selectRaw("
                COUNT(*) AS dibuat,
                COUNT(*) FILTER (WHERE status = 'completed') AS selesai,
                COUNT(*) FILTER (WHERE status = 'cancelled') AS dibatalkan,
                COUNT(*) FILTER (WHERE status = 'no_driver') AS tanpa_driver,
                COALESCE(SUM(total_fare) FILTER (WHERE status = 'completed'), 0) AS gmv,
                COALESCE(SUM(commission_amount) FILTER (WHERE status = 'completed'), 0) AS komisi
            ")
            ->first();

        $dibuat = (int) ($baris->dibuat ?? 0);
        $selesaiJumlah = (int) ($baris->selesai ?? 0);

        return [
            'dibuat' => $dibuat,
            'selesai' => $selesaiJumlah,
            'dibatalkan' => (int) ($baris->dibatalkan ?? 0),
            'tanpa_driver' => (int) ($baris->tanpa_driver ?? 0),

            'gmv' => Money::of((int) ($baris->gmv ?? 0)),
            'komisi' => Money::of((int) ($baris->komisi ?? 0)),

            /*
             * Tingkat penyelesaian, bukan tingkat pembatalan.
             *
             * Keduanya membawa informasi yang sama, tapi arahnya berbeda: angka
             * yang naik berarti membaik lebih mudah dibaca sekilas daripada
             * angka yang naik berarti memburuk. Panel yang dibaca setiap pagi
             * harus bisa dinilai tanpa berpikir dua kali.
             */
            'tingkat_selesai' => $dibuat > 0 ? round($selesaiJumlah / $dibuat * 100, 1) : null,

            /*
             * Order yang tidak mendapat driver dipisah dari yang dibatalkan.
             *
             * Keduanya adalah kegagalan, tapi penyebab dan penanganannya
             * berbeda jauh: pembatalan berarti orangnya berubah pikiran, tanpa
             * driver berarti pasokannya kurang. Menggabungkannya jadi satu angka
             * "gagal" menghilangkan satu-satunya petunjuk yang bisa
             * ditindaklanjuti.
             */
            'tingkat_tanpa_driver' => $dibuat > 0
                ? round((int) ($baris->tanpa_driver ?? 0) / $dibuat * 100, 1)
                : null,
        ];
    }

    /**
     * Tren 14 hari dari tabel agregat.
     *
     * @return array<int, array<string, mixed>>
     */
    private function tren14Hari(): array
    {
        $mulai = BusinessClock::now()->subDays(13)->toDateString();

        $baris = DB::table('metrics_daily')
            ->whereNull('zone_id')
            ->whereNull('service_type_id')
            ->where('date', '>=', $mulai)
            ->orderBy('date')
            ->get(['date', 'orders_created', 'orders_completed', 'gmv']);

        /*
         * Hari tanpa baris agregat diisi nol.
         *
         * Kalau tidak, grafik 14 hari yang datanya hanya ada 9 hari akan
         * menampilkan sembilan titik yang jaraknya tidak sama — dan itu terbaca
         * sebagai tren, padahal yang terjadi hanya job agregasi yang tidak
         * jalan. Nol yang eksplisit menunjukkan lubangnya.
         */
        $terindeks = $baris->keyBy(fn ($b) => (string) $b->date);
        $hasil = [];

        for ($i = 13; $i >= 0; $i--) {
            $tanggal = BusinessClock::now()->subDays($i)->toDateString();
            $b = $terindeks->get($tanggal);

            $hasil[] = [
                'tanggal' => $tanggal,
                'label' => BusinessClock::now()->subDays($i)->format('d/m'),
                'dibuat' => (int) ($b->orders_created ?? 0),
                'selesai' => (int) ($b->orders_completed ?? 0),
                'gmv' => (int) ($b->gmv ?? 0),
                'ada_data' => $b !== null,
            ];
        }

        return $hasil;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function perZonaHariIni(\DateTimeInterface $mulai, \DateTimeInterface $selesai): array
    {
        return DB::table('orders')
            ->join('zones', 'zones.id', '=', 'orders.zone_id')
            ->whereBetween('orders.requested_at', [$mulai, $selesai])
            ->groupBy('zones.id', 'zones.name')
            ->orderByDesc('dibuat')
            ->selectRaw("
                zones.name,
                COUNT(*) AS dibuat,
                COUNT(*) FILTER (WHERE orders.status = 'completed') AS selesai,
                COUNT(*) FILTER (WHERE orders.status = 'no_driver') AS tanpa_driver,
                COALESCE(SUM(orders.total_fare) FILTER (WHERE orders.status = 'completed'), 0) AS gmv
            ")
            ->get()
            ->map(fn ($b): array => [
                'zona' => (string) $b->name,
                'dibuat' => (int) $b->dibuat,
                'selesai' => (int) $b->selesai,
                'tanpa_driver' => (int) $b->tanpa_driver,
                'gmv' => Money::of((int) $b->gmv),
            ])
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function sebaranStatusBerjalan(): array
    {
        $baris = DB::table('orders')
            ->whereIn('status', array_merge(
                OrderStatus::activeValues(),
                [OrderStatus::Searching->value],
            ))
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) AS jumlah')
            ->pluck('jumlah', 'status');

        $hasil = [];

        foreach (array_merge([OrderStatus::Searching->value], OrderStatus::activeValues()) as $status) {
            $hasil[$status] = (int) ($baris[$status] ?? 0);
        }

        return $hasil;
    }

    /**
     * Order yang sudah terlalu lama mencari driver.
     *
     * Ditampilkan sebagai DAFTAR, bukan hanya jumlah. Angkanya sudah ada di
     * sidebar; yang dibutuhkan di dashboard adalah barisnya sendiri, supaya
     * staf ops bisa langsung membukanya dan memaksa assign driver.
     *
     * @return Collection<int, object>
     */
    private function orderMacet(): Collection
    {
        $ambang = (int) config('antaride.live_map.stuck_order_highlight_seconds', 60);

        return DB::table('orders')
            ->leftJoin('zones', 'zones.id', '=', 'orders.zone_id')
            ->leftJoin('service_types', 'service_types.id', '=', 'orders.service_type_id')
            ->where('orders.status', OrderStatus::Searching->value)
            ->where('orders.requested_at', '<', now()->subSeconds($ambang))
            ->orderBy('orders.requested_at')
            ->limit(20)
            ->get([
                'orders.uuid',
                'orders.order_number',
                'orders.requested_at',
                'orders.pickup_address',
                'zones.name AS zona',
                'service_types.name AS layanan',
            ]);
    }
}
