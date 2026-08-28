<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Actions;

use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Shared\Support\BusinessClock;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Meringkas satu hari operasional ke `metrics_daily` dan `driver_daily_metrics`.
 *
 * ============================================================================
 *  KENAPA AGREGASI SAMA SEKALI
 * ============================================================================
 *  Dashboard backoffice menampilkan tren 14 hari. Menghitungnya dari tabel
 *  `orders` setiap kali dashboard dibuka berarti memindai ratusan ribu baris —
 *  dan dashboard adalah halaman yang paling sering dimuat ulang di seluruh
 *  panel.
 *
 *  Sebelum Action ini ada, `metrics_daily` kosong dan grafik trennya selalu
 *  menampilkan nol untuk setiap hari. Tidak ada galat, tidak ada peringatan —
 *  hanya grafik datar yang terbaca sebagai "tidak ada order sama sekali".
 * ============================================================================
 *
 * ============================================================================
 *  IDEMPOTEN, KARENA AKAN DIJALANKAN ULANG
 * ============================================================================
 *  `upsert` per kombinasi (tanggal, zona, layanan), bukan insert. Yang
 *  memaksanya:
 *
 *    * Hari ini diagregasi berulang sepanjang hari supaya dashboard tidak
 *      tertinggal sampai tengah malam.
 *    * Job yang gagal di tengah jalan akan dijalankan lagi.
 *    * Order yang statusnya berubah setelah agregasi — misalnya pembatalan
 *      terlambat — menuntut hari itu dihitung ulang.
 *
 *  Insert biasa akan gagal pada unique index, atau lebih buruk: menambah baris
 *  kedua untuk hari yang sama dan menggandakan seluruh angka di grafik.
 * ============================================================================
 *
 * ============================================================================
 *  BATAS HARINYA ZONA BISNIS, BUKAN UTC
 * ============================================================================
 *  Penyimpanan pakai UTC; keputusan bisnis pakai Asia/Jakarta. Kalau batas
 *  harinya UTC, "1 Maret" di dashboard sebenarnya berisi order dari 1 Maret
 *  07:00 sampai 2 Maret 07:00 WIB — dan angka penutupan hari tidak akan pernah
 *  cocok dengan yang dihitung tim ops secara manual.
 * ============================================================================
 */
final readonly class AggregateDailyMetrics
{
    /**
     * Agregasi satu tanggal.
     *
     * @return array{daily_rows: int, driver_rows: int}
     */
    public function handle(?DateTimeInterface $date = null): array
    {
        /*
         * Dinormalkan ke CarbonImmutable, dan itu bukan formalitas.
         *
         * `BusinessClock::now()` mengembalikan Carbon yang MUTABLE. Memanggil
         * `->subDay()` padanya mengubah objeknya di tempat — jadi pemanggil yang
         * memakai ulang variabelnya akan mendapati tanggalnya bergeser tanpa
         * dia mengubah apa pun. Di dalam loop pengisian data lama, itu berarti
         * setiap iterasi mundur satu hari lagi dari hasil iterasi sebelumnya.
         */
        $tanggal = CarbonImmutable::instance(
            $date !== null
                ? CarbonImmutable::instance($date)
                : BusinessClock::now()->subDay(),
        )->setTimezone(BusinessClock::timezone())->startOfDay();

        [$mulai, $selesai] = BusinessClock::dayRange($tanggal);

        $barisHarian = $this->agregasiHarian($tanggal->toDateString(), $mulai, $selesai);
        $barisDriver = $this->agregasiDriver($tanggal->toDateString(), $mulai, $selesai);

        return [
            'daily_rows' => $barisHarian,
            'driver_rows' => $barisDriver,
        ];
    }

    // -------------------------------------------------------------------------

    /**
     * Agregasi ke `metrics_daily`, dalam tiga rincian sekaligus.
     *
     * ==========================================================================
     *  TIGA RINCIAN, DAN KETIGANYA DIPAKAI
     * ==========================================================================
     *    (zona null, layanan null)   total keseluruhan — yang dibaca grafik tren
     *    (zona X,  layanan null)     perbandingan antar zona
     *    (zona null, layanan Y)      perbandingan antar layanan
     *
     *  Yang TIDAK dibuat: kombinasi (zona X, layanan Y). Untuk 20 zona × 6
     *  layanan itu 120 baris per hari yang belum ada satu pun layar
     *  membacanya — dan baris yang tidak dibaca tetap menanggung biaya tulis dan
     *  indeks setiap hari.
     *
     *  Unique index-nya memakai `NULLS NOT DISTINCT`, jadi kombinasi dengan null
     *  benar-benar dijaga unik. Tanpa itu, upsert dengan zona null akan selalu
     *  menyisipkan baris baru karena `NULL = NULL` bernilai unknown di SQL.
     * ==========================================================================
     */
    private function agregasiHarian(string $tanggal, mixed $mulai, mixed $selesai): int
    {
        $rincian = [
            ['zone_id' => null, 'service_type_id' => null, 'groupBy' => null],
            ['zone_id' => null, 'service_type_id' => null, 'groupBy' => 'zone_id'],
            ['zone_id' => null, 'service_type_id' => null, 'groupBy' => 'service_type_id'],
        ];

        $baris = [];

        foreach ($rincian as $satu) {
            $baris = array_merge(
                $baris,
                $this->hitungHarian($tanggal, $mulai, $selesai, $satu['groupBy']),
            );
        }

        if ($baris === []) {
            return 0;
        }

        DB::table('metrics_daily')->upsert(
            $baris,
            ['date', 'zone_id', 'service_type_id'],
            [
                'orders_created', 'orders_completed', 'orders_cancelled',
                'orders_no_driver', 'gmv', 'driver_earning', 'commission',
                'discount_cost', 'surge_revenue', 'avg_wait_seconds',
                'avg_trip_seconds', 'p50_wait_seconds', 'p90_wait_seconds',
                'unique_customers', 'active_drivers', 'avg_acceptance_rate',
                'updated_at',
            ],
        );

        return count($baris);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function hitungHarian(
        string $tanggal,
        mixed $mulai,
        mixed $selesai,
        ?string $groupBy,
    ): array {
        $selesaiStatus = OrderStatus::Completed->value;
        $batalStatus = OrderStatus::Cancelled->value;
        $tanpaDriverStatus = OrderStatus::NoDriver->value;

        $query = DB::table('orders')
            ->whereBetween('requested_at', [$mulai, $selesai])
            ->selectRaw('COUNT(*) AS orders_created')
            ->selectRaw('COUNT(*) FILTER (WHERE status = ?) AS orders_completed', [$selesaiStatus])
            ->selectRaw('COUNT(*) FILTER (WHERE status = ?) AS orders_cancelled', [$batalStatus])
            ->selectRaw('COUNT(*) FILTER (WHERE status = ?) AS orders_no_driver', [$tanpaDriverStatus])

            /*
             * GMV, pendapatan driver, dan komisi HANYA dari order yang selesai.
             *
             * Order yang dibatalkan punya nominal ongkos di barisnya — dihitung
             * saat order dibuat — tapi tidak pernah ditagih. Memasukkannya ke
             * GMV membuat angka pendapatan naik setiap kali ada pembatalan, dan
             * itu justru kebalikan dari kenyataannya.
             */
            ->selectRaw('COALESCE(SUM(total_fare) FILTER (WHERE status = ?), 0) AS gmv', [$selesaiStatus])
            ->selectRaw('COALESCE(SUM(driver_earning) FILTER (WHERE status = ?), 0) AS driver_earning', [$selesaiStatus])
            ->selectRaw('COALESCE(SUM(commission_amount) FILTER (WHERE status = ?), 0) AS commission', [$selesaiStatus])
            ->selectRaw('COALESCE(SUM(discount_amount) FILTER (WHERE status = ?), 0) AS discount_cost', [$selesaiStatus])
            ->selectRaw('COALESCE(SUM(surge_amount) FILTER (WHERE status = ?), 0) AS surge_revenue', [$selesaiStatus])

            /*
             * Waktu tunggu = requested_at sampai matched_at.
             *
             * Hanya order yang BENAR-BENAR dapat driver yang dihitung. Order
             * yang tidak pernah cocok tidak punya waktu tunggu — dia punya
             * kegagalan, dan itu sudah dihitung terpisah sebagai
             * `orders_no_driver`. Memasukkannya sebagai waktu tunggu nol akan
             * menurunkan rata-rata justru pada hari yang paling buruk.
             */
            ->selectRaw('COALESCE(AVG(EXTRACT(EPOCH FROM (matched_at - requested_at))) FILTER (WHERE matched_at IS NOT NULL), 0) AS avg_wait_seconds')
            ->selectRaw('COALESCE(AVG(EXTRACT(EPOCH FROM (completed_at - started_at))) FILTER (WHERE completed_at IS NOT NULL AND started_at IS NOT NULL), 0) AS avg_trip_seconds')

            /*
             * Persentil, bukan hanya rata-rata.
             *
             * Rata-rata waktu tunggu menyembunyikan ekor yang panjang: 90%
             * penumpang menunggu 40 detik dan 10% menunggu delapan menit
             * menghasilkan rata-rata 88 detik, yang terlihat sehat. p90 yang
             * menunjukkan masalahnya — dan itu yang dirasakan penumpang yang
             * berhenti memakai aplikasi.
             */
            ->selectRaw('COALESCE(PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (matched_at - requested_at))), 0) AS p50_wait_seconds')
            ->selectRaw('COALESCE(PERCENTILE_CONT(0.9) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (matched_at - requested_at))), 0) AS p90_wait_seconds')

            ->selectRaw('COUNT(DISTINCT user_id) AS unique_customers')
            ->selectRaw('COUNT(DISTINCT driver_id) FILTER (WHERE driver_id IS NOT NULL) AS active_drivers');

        if ($groupBy !== null) {
            $query->addSelect($groupBy)
                ->whereNotNull($groupBy)
                ->groupBy($groupBy);
        }

        $hasil = $query->get();

        return $hasil->map(function (object $row) use ($tanggal, $groupBy): array {
            return [
                'date' => $tanggal,
                'zone_id' => $groupBy === 'zone_id' ? (int) $row->zone_id : null,
                'service_type_id' => $groupBy === 'service_type_id' ? (int) $row->service_type_id : null,

                'orders_created' => (int) $row->orders_created,
                'orders_completed' => (int) $row->orders_completed,
                'orders_cancelled' => (int) $row->orders_cancelled,
                'orders_no_driver' => (int) $row->orders_no_driver,

                'gmv' => (int) $row->gmv,
                'driver_earning' => (int) $row->driver_earning,
                'commission' => (int) $row->commission,
                'discount_cost' => (int) $row->discount_cost,
                'surge_revenue' => (int) $row->surge_revenue,

                'avg_wait_seconds' => (int) round((float) $row->avg_wait_seconds),
                'avg_trip_seconds' => (int) round((float) $row->avg_trip_seconds),
                'p50_wait_seconds' => (int) round((float) $row->p50_wait_seconds),
                'p90_wait_seconds' => (int) round((float) $row->p90_wait_seconds),

                'unique_customers' => (int) $row->unique_customers,
                'active_drivers' => (int) $row->active_drivers,

                // Rasio penerimaan dihitung di agregasi driver, bukan di sini —
                // sumbernya `order_offers`, bukan `orders`. Dibiarkan nol pada
                // baris harian supaya tidak ada dua angka yang mengaku sama.
                'avg_acceptance_rate' => 0,

                'created_at' => now(),
                'updated_at' => now(),
            ];
        })->all();
    }

    // -------------------------------------------------------------------------

    /**
     * Agregasi ke `driver_daily_metrics`.
     *
     * Dipakai halaman pendapatan driver di backoffice dan — nanti — di aplikasi
     * driver. Sumbernya tiga tabel: `orders` untuk order dan uang,
     * `driver_sessions` untuk jam kerja, `order_offers` untuk rasio penerimaan.
     */
    private function agregasiDriver(string $tanggal, mixed $mulai, mixed $selesai): int
    {
        $selesaiStatus = OrderStatus::Completed->value;
        $batalStatus = OrderStatus::Cancelled->value;

        $dariOrder = DB::table('orders')
            ->whereNotNull('driver_id')
            ->whereBetween('requested_at', [$mulai, $selesai])
            ->groupBy('driver_id')
            ->selectRaw('driver_id')
            ->selectRaw('COUNT(*) FILTER (WHERE status = ?) AS orders_completed', [$selesaiStatus])
            ->selectRaw('COUNT(*) FILTER (WHERE status = ? AND cancelled_by = ?) AS orders_cancelled', [$batalStatus, 'driver'])
            ->selectRaw('COALESCE(SUM(driver_earning) FILTER (WHERE status = ?), 0) AS gross_earning', [$selesaiStatus])
            ->selectRaw('COALESCE(SUM(commission_amount) FILTER (WHERE status = ?), 0) AS commission_paid', [$selesaiStatus])

            // Jarak SEBENARNYA kalau ada, estimasi kalau tidak. Driver yang
            // aplikasinya dimatikan paksa di tengah perjalanan kehilangan jejak
            // GPS-nya, dan jarak nol untuk perjalanan yang benar-benar terjadi
            // akan membuat laporan jaraknya salah tanpa sebab yang terlihat.
            ->selectRaw('COALESCE(SUM(COALESCE(actual_distance_m, distance_m)) FILTER (WHERE status = ?), 0) AS distance_m', [$selesaiStatus])
            ->get()
            ->keyBy('driver_id');

        $dariSesi = DB::table('driver_sessions')
            ->whereBetween('started_at', [$mulai, $selesai])
            ->groupBy('driver_id')
            ->selectRaw('driver_id, COALESCE(SUM(online_seconds), 0) AS online_seconds')
            ->get()
            ->keyBy('driver_id');

        $dariTawaran = DB::table('order_offers')
            ->whereBetween('offered_at', [$mulai, $selesai])
            ->groupBy('driver_id')
            ->selectRaw('driver_id')
            ->selectRaw('COUNT(*) AS offers_received')
            ->selectRaw("COUNT(*) FILTER (WHERE response = 'accepted') AS offers_accepted")
            ->get()
            ->keyBy('driver_id');

        /*
         * Gabungan KUNCI dari ketiga sumber, bukan hanya dari salah satunya.
         *
         * Driver yang online sepanjang hari tanpa menerima satu order pun tetap
         * harus punya baris — justru itu baris yang paling perlu dilihat tim
         * ops. Kalau daftarnya diambil dari `orders` saja, driver itu hilang
         * dari laporan, dan yang tampak adalah tidak ada masalah.
         */
        $driverIds = collect($dariOrder->keys())
            ->merge($dariSesi->keys())
            ->merge($dariTawaran->keys())
            ->unique()
            ->values();

        if ($driverIds->isEmpty()) {
            return 0;
        }

        $baris = $driverIds->map(function (mixed $driverId) use (
            $tanggal,
            $dariOrder,
            $dariSesi,
            $dariTawaran,
        ): array {
            $order = $dariOrder->get($driverId);
            $sesi = $dariSesi->get($driverId);
            $tawaran = $dariTawaran->get($driverId);

            $bruto = (int) ($order->gross_earning ?? 0);
            $komisi = (int) ($order->commission_paid ?? 0);

            return [
                'date' => $tanggal,
                'driver_id' => (int) $driverId,

                'online_seconds' => (int) ($sesi->online_seconds ?? 0),
                'offers_received' => (int) ($tawaran->offers_received ?? 0),
                'offers_accepted' => (int) ($tawaran->offers_accepted ?? 0),

                'orders_completed' => (int) ($order->orders_completed ?? 0),
                'orders_cancelled' => (int) ($order->orders_cancelled ?? 0),

                'gross_earning' => $bruto,
                'commission_paid' => $komisi,

                // Insentif belum ada di Fase 1. Nol di sini adalah nilai yang
                // benar, bukan tempat kosong yang menunggu diisi.
                'incentive_earned' => 0,

                /*
                 * `driver_earning` di tabel orders SUDAH bersih dari komisi.
                 *
                 * Jadi net = bruto, bukan bruto − komisi. Mengurangkannya lagi
                 * di sini akan memotong komisi dua kali, dan laporan pendapatan
                 * driver akan lebih kecil daripada yang benar-benar masuk ke
                 * dompetnya — keluhan yang paling sulit dijelaskan.
                 */
                'net_earning' => $bruto,

                'distance_m' => (int) ($order->distance_m ?? 0),

                // Rating harian tidak diagregasi di sini: `ratings` diisi
                // penumpang setelah perjalanan, kadang berhari-hari kemudian.
                // Angka yang dihitung pada hari perjalanannya akan hampir selalu
                // kosong, dan itu lebih menyesatkan daripada null.
                'rating_avg' => null,
                'rating_count' => 0,

                'created_at' => now(),
                'updated_at' => now(),
            ];
        })->all();

        DB::table('driver_daily_metrics')->upsert(
            $baris,
            ['date', 'driver_id'],
            [
                'online_seconds', 'offers_received', 'offers_accepted',
                'orders_completed', 'orders_cancelled', 'gross_earning',
                'commission_paid', 'incentive_earned', 'net_earning',
                'distance_m', 'updated_at',
            ],
        );

        return count($baris);
    }
}
