<?php

declare(strict_types=1);

namespace App\Domain\Matching\Actions;

use App\Domain\Driver\Models\Driver;
use App\Domain\Matching\Contracts\DriverLocationIndex;
use App\Domain\Matching\DTOs\DriverCandidate;
use App\Domain\Matching\DTOs\DriverPosition;
use App\Domain\Matching\Scoring\DriverScorer;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Shared\ValueObjects\Coordinate;
use App\Domain\Wallet\Models\Wallet;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Mencari driver yang layak ditawari sebuah order, terurut dari yang paling
 * layak.
 *
 * ============================================================================
 *  URUTAN PENYARINGAN DIPILIH BERDASARKAN BIAYA, BUKAN KERAPIAN
 * ============================================================================
 *  Setiap tahap membuang kandidat sebelum tahap yang lebih mahal berjalan:
 *
 *    1. GEO Redis            satu perintah, mengembalikan yang dekat saja
 *    2. Irisan ketersediaan  operasi set Redis, tanpa menyentuh database
 *    3. Ping basi            aritmetika di PHP, tanpa I/O
 *    4. Sudah ditawari       query kecil ke order_offers
 *    5. Kelayakan driver     satu query ke drivers, hanya untuk yang tersisa
 *    6. Saldo deposit tunai  hanya kalau ordernya tunai
 *
 *  Kalau urutannya dibalik — misalnya memuat semua driver aktif dari database
 *  lalu menghitung jaraknya di PHP — dengan seribu driver setiap pencarian
 *  menjadi seribu baris dan seribu perhitungan haversine, dan matching menjadi
 *  bagian termahal di seluruh sistem.
 * ============================================================================
 *
 *  YANG TIDAK DILAKUKAN DI SINI: memesan driver.
 *
 *  Action ini murni membaca. Yang mengubah keadaan adalah pembuat penawaran
 *  dan `AcceptOrder`. Konsekuensinya seorang driver bisa muncul sebagai
 *  kandidat untuk dua order yang dicarikan pada saat yang sama — dan itu
 *  memang dibiarkan, karena yang menentukan siapa mendapatkannya adalah lock
 *  dan partial unique index pada saat accept, bukan urutan pencarian.
 *  Mencoba mencegahnya di sini hanya akan menghasilkan driver yang "dipesan"
 *  untuk order yang lalu dibatalkan.
 */
class FindCandidateDrivers
{
    public function __construct(
        private readonly DriverLocationIndex $locationIndex,
        private readonly DriverScorer $scorer,
    ) {}

    /**
     * @param  int  $radiusMeters  radius gelombang yang sedang berjalan
     * @param  int  $limit  berapa kandidat yang dibutuhkan gelombang ini
     * @return array<int, DriverCandidate>
     */
    public function handle(Order $order, int $radiusMeters, int $limit): array
    {
        $serviceCode = (string) $order->serviceType->code;
        $pickup = Coordinate::of((float) $order->pickup_lat, (float) $order->pickup_lng);

        /*
         * Diambil lebih banyak daripada yang dibutuhkan, karena tahap-tahap
         * berikutnya akan membuang sebagian. Kalau hanya diambil sebanyak
         * $limit, satu driver yang ping-nya basi sudah cukup untuk membuat
         * gelombang ini kekurangan kandidat padahal ada driver lain yang layak
         * sedikit lebih jauh.
         */
        $positions = $this->locationIndex->findNearby(
            serviceCode: $serviceCode,
            center: $pickup,
            radiusMeters: $radiusMeters,
            limit: max($limit * 5, 25),
        );

        if ($positions === []) {
            return [];
        }

        $positions = $this->onlyAvailable($order, $serviceCode, $positions);
        $positions = $this->onlyFreshPings($positions);
        $positions = $this->excludeAlreadyOffered($order, $positions);

        if ($positions === []) {
            return [];
        }

        $drivers = $this->eligibleDrivers($order, array_keys($positions));

        if ($drivers === []) {
            return [];
        }

        return $this->rank($order, $drivers, $positions, $radiusMeters, $limit);
    }

    // -------------------------------------------------------------------------

    /**
     * Hanya driver yang menyatakan diri siap menerima order.
     *
     * Indeks posisi memuat SEMUA driver yang online, termasuk yang sedang
     * mengantar — penumpangnya perlu melihat drivernya bergerak. Yang
     * menentukan siapa boleh ditawari adalah set ketersediaan, dan irisannya
     * harus dilakukan di sini.
     *
     * @param  array<int, DriverPosition>  $positions
     * @return array<int, DriverPosition> dikunci driver_id
     */
    private function onlyAvailable(Order $order, string $serviceCode, array $positions): array
    {
        /*
         * Zona diambil dari posisi kandidat, bukan hanya zona order.
         *
         * Radius 8 km di Medan melintasi beberapa zona. Driver di zona sebelah
         * yang jaraknya 500 meter dari penjemputan lebih layak ditawari
         * daripada driver sezona yang jaraknya 4 km, dan menyaring hanya dengan
         * zona order akan membuang dia tanpa alasan yang bisa dijelaskan ke
         * siapa pun.
         */
        $zoneIds = $this->zonesInPlay($order);

        $available = array_flip($this->locationIndex->availableDriverIds($serviceCode, $zoneIds));

        $result = [];

        foreach ($positions as $position) {
            if (isset($available[$position->driverId])) {
                $result[$position->driverId] = $position;
            }
        }

        return $result;
    }

    /**
     * @return array<int, int>
     */
    private function zonesInPlay(Order $order): array
    {
        $zoneIds = DB::table('zones')->where('is_active', true)->pluck('id')->all();

        // Kalau zona order diketahui, dia harus ikut walaupun sudah tidak aktif
        // — order yang sedang jalan tidak boleh terpengaruh perubahan zona.
        if ($order->zone_id !== null) {
            $zoneIds[] = (int) $order->zone_id;
        }

        return array_values(array_unique(array_map('intval', $zoneIds)));
    }

    /**
     * Driver yang ping terakhirnya sudah basi dianggap tidak hadir.
     *
     * Statusnya masih "tersedia" di Redis karena tidak ada yang mencabutnya,
     * tapi HP-nya sudah mati, kehabisan baterai, atau aplikasinya ditutup
     * paksa. Menawari dia berarti membuang satu slot gelombang untuk penawaran
     * yang tidak akan pernah dibuka, dan penumpang menunggu 15 detik lebih lama
     * tanpa alasan.
     *
     * @param  array<int, DriverPosition>  $positions
     * @return array<int, DriverPosition>
     */
    private function onlyFreshPings(array $positions): array
    {
        $maxAge = (int) config('antaride.matching.stale_ping_seconds', 30);
        $now = time();

        return array_filter(
            $positions,
            static function (DriverPosition $position) use ($now, $maxAge): bool {
                // Tanpa timestamp, posisinya tidak bisa dipercaya usianya.
                // Dibiarkan lolos: sumbernya bisa jadi seeding atau koreksi
                // manual admin, dan membuangnya akan membuat data uji tidak
                // pernah bisa dipakai.
                if ($position->timestamp === null) {
                    return true;
                }

                return ($now - $position->timestamp) <= $maxAge;
            },
        );
    }

    /**
     * Driver yang sudah pernah ditawari order ini tidak ditawari lagi.
     *
     * Termasuk yang menolak DAN yang membiarkan penawarannya kadaluarsa.
     * Menawarkan ulang ke driver yang baru saja menolak adalah cara tercepat
     * membuat dia mematikan aplikasi.
     *
     * @param  array<int, DriverPosition>  $positions
     * @return array<int, DriverPosition>
     */
    private function excludeAlreadyOffered(Order $order, array $positions): array
    {
        if ($positions === []) {
            return [];
        }

        $offered = DB::table('order_offers')
            ->where('order_id', $order->getKey())
            ->whereIn('driver_id', array_keys($positions))
            ->pluck('driver_id')
            ->all();

        foreach ($offered as $driverId) {
            unset($positions[(int) $driverId]);
        }

        return $positions;
    }

    /**
     * Driver yang memenuhi syarat menerima order ini.
     *
     * @param  array<int, int>  $driverIds
     * @return array<int, Driver> dikunci driver id
     */
    private function eligibleDrivers(Order $order, array $driverIds): array
    {
        $drivers = Driver::query()
            ->whereIn('id', $driverIds)
            ->where('status', 'active')

            /*
             * Driver yang sedang memegang order tidak boleh ditawari.
             *
             * Ini pemeriksaan KEDUA setelah set ketersediaan Redis, dan
             * keduanya perlu. Redis bisa tertinggal: kalau proses yang
             * seharusnya mencabut ketersediaan mati setelah order tersimpan
             * tapi sebelum Redis diperbarui, satu-satunya sumber kebenaran yang
             * tersisa adalah tabel orders. Tanpa pemeriksaan ini, driver yang
             * sedang mengantar akan ditawari order baru, menekan terima, dan
             * ditolak partial unique index dengan error yang tidak dia mengerti.
             */
            ->whereDoesntHave('orders', function ($query): void {
                $query->whereIn('status', OrderStatus::activeValues());
            })

            ->with('vehicles')
            ->get()
            ->keyBy('id');

        if ($drivers->isEmpty()) {
            return [];
        }

        if ($order->isCash()) {
            $drivers = $this->onlyWithCashDeposit($drivers);
        }

        return $drivers->all();
    }

    /**
     * Order tunai menuntut saldo deposit minimum.
     *
     * Pada order tunai, seluruh ongkos diterima driver langsung dari penumpang,
     * dan komisi platform dipotong dari saldonya SETELAH order selesai. Driver
     * bersaldo nol yang menerima order tunai berarti komisi yang tidak bisa
     * ditagih, dan tidak ada mekanisme untuk memaksanya membayar.
     *
     * Pemeriksaannya di sini, bukan saat settlement. Menolak di saat settlement
     * berarti ordernya sudah selesai dan uangnya sudah di tangan driver.
     *
     * @param  Collection<int, Driver>  $drivers
     * @return Collection<int, Driver>
     */
    private function onlyWithCashDeposit(Collection $drivers): Collection
    {
        $minimum = (int) config('antaride.wallet.driver_cash_deposit_minimum', 20000);

        if ($minimum <= 0) {
            return $drivers;
        }

        $sufficient = DB::table('wallets')
            ->where('owner_type', 'driver')
            ->whereIn('owner_id', $drivers->keys()->all())
            ->where('is_frozen', false)
            ->where('balance', '>=', $minimum)
            ->pluck('owner_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $sufficient = array_flip($sufficient);

        return $drivers->filter(
            static fn (Driver $driver): bool => isset($sufficient[(int) $driver->getKey()]),
        );
    }

    /**
     * Beri skor lalu urutkan.
     *
     * @param  array<int, Driver>  $drivers
     * @param  array<int, DriverPosition>  $positions
     * @return array<int, DriverCandidate>
     */
    private function rank(
        Order $order,
        array $drivers,
        array $positions,
        int $radiusMeters,
        int $limit,
    ): array {
        $idleSeconds = $this->idleSecondsFor(array_keys($drivers));

        $candidates = [];

        foreach ($drivers as $driverId => $driver) {
            $position = $positions[$driverId] ?? null;

            if ($position === null) {
                continue;
            }

            $scored = $this->scorer->score(
                driver: $driver,
                position: $position,
                radiusMeters: $radiusMeters,
                idleSeconds: $idleSeconds[$driverId] ?? 0,
            );

            $candidates[] = new DriverCandidate(
                driver: $driver,
                position: $position,
                score: $scored['score'],
                scoreBreakdown: $scored['breakdown'],
                distanceToPickupM: (int) round($position->distanceM ?? 0),
            );
        }

        usort(
            $candidates,
            static fn (DriverCandidate $a, DriverCandidate $b): int => $b->score <=> $a->score,
        );

        return array_slice($candidates, 0, $limit);
    }

    /**
     * Berapa lama masing-masing driver belum mendapat order.
     *
     * Diukur dari penawaran terakhir yang DITERIMA, bukan dari penawaran
     * terakhir yang dikirim. Kalau diukur dari yang dikirim, driver yang terus
     * ditawari dan terus menolak akan selamanya terlihat "baru dapat order" dan
     * bonus keadilannya nol — padahal dia justru yang belum pernah bekerja.
     *
     * @param  array<int, int>  $driverIds
     * @return array<int, int> driver_id => detik
     */
    private function idleSecondsFor(array $driverIds): array
    {
        if ($driverIds === []) {
            return [];
        }

        $rows = DB::table('order_offers')
            ->select('driver_id', DB::raw('MAX(responded_at) AS last_accepted'))
            ->whereIn('driver_id', $driverIds)
            ->where('response', 'accepted')
            ->groupBy('driver_id')
            ->get();

        $now = time();
        $idle = [];

        foreach ($rows as $row) {
            $idle[(int) $row->driver_id] = $row->last_accepted === null
                ? PHP_INT_MAX
                : max(0, $now - strtotime((string) $row->last_accepted));
        }

        /*
         * Driver tanpa riwayat penerimaan sama sekali mendapat bonus keadilan
         * PENUH, bukan nol.
         *
         * Mereka adalah driver baru. Nol akan menempatkan mereka di urutan
         * paling bawah persis pada saat mereka paling butuh order pertama, dan
         * itu bentuk paling langsung dari masalah yang bobot keadilan ini ada
         * untuk mencegah.
         */
        $idleCap = (int) config('antaride.matching.idle_cap_seconds', 900);

        foreach ($driverIds as $driverId) {
            $idle[$driverId] ??= $idleCap;
        }

        return $idle;
    }

    /**
     * Ambang deposit tunai, untuk ditampilkan panel admin dan aplikasi driver.
     */
    public static function cashDepositMinimum(): int
    {
        return (int) config('antaride.wallet.driver_cash_deposit_minimum', 20000);
    }

    /**
     * Dompet driver, dibuat kalau belum ada.
     *
     * Ada di sini karena filter deposit di atas menuntut barisnya ada; driver
     * tanpa baris dompet akan selamanya tidak lolos filter order tunai tanpa
     * penjelasan apa pun di panel.
     */
    public static function ensureWallet(int $driverId): Wallet
    {
        return Wallet::forOwner('driver', $driverId);
    }
}
