<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Driver;

use App\Domain\Driver\Actions\GoOffline;
use App\Domain\Driver\Actions\GoOnline;
use App\Domain\Driver\Models\Driver;
use App\Domain\Driver\Support\LocationTicket;
use App\Domain\Matching\Contracts\DriverLocationIndex;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Shared\Support\BusinessClock;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Wallet\Models\Wallet;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Driver\GoOnlineRequest;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Status kerja driver: online, offline, dan ringkasan hari ini.
 */
class StatusController extends Controller
{
    public function goOnline(GoOnlineRequest $request, GoOnline $action): JsonResponse
    {
        $driver = $this->driver($request);

        $session = $action->handle(
            driver: $driver,
            at: $request->coordinate(),
            vehicleId: $request->validated('vehicle_id'),
            serviceCodes: $request->validated('service_codes'),
        );

        return ApiResponse::success([
            'online' => true,
            'session_started_at' => $session->started_at->toIso8601String(),

            /*
             * Interval ping dikirim backend, bukan ditentukan aplikasi.
             *
             * Ini yang membuat frekuensi GPS bisa diturunkan tanpa rilis
             * aplikasi baru — misalnya saat biaya bandwidth naik atau saat
             * location service kewalahan. Kalau aplikasi menentukannya sendiri,
             * satu-satunya cara mengubahnya adalah menunggu semua pengguna
             * memperbarui aplikasi.
             */
            'ping_interval_seconds' => (int) config('antaride.gps.ping_interval_seconds.idle', 10),

            /*
             * ==============================================================
             *  ALAMAT DAN TIKET UNTUK LAYANAN LOKASI
             * ==============================================================
             *  Ping GPS TIDAK dikirim ke Laravel — dia ke layanan Go di port
             *  8200, yang menulis langsung ke Redis. Alasannya beban: seribu
             *  driver dengan ping tiap empat detik adalah 250 permintaan per
             *  detik yang isinya dua angka, dan tidak ada satu pun fitur
             *  framework yang dibutuhkan untuk itu.
             *
             *  Tiketnya bertanda tangan HMAC dan memuat id driver beserta
             *  layanan yang dia aktifkan. Layanan Go memverifikasi tanda
             *  tangannya tanpa menyentuh database.
             *
             *  Alamatnya dikirim dari sini, bukan ditulis di aplikasi: layanan
             *  lokasi bisa pindah host atau port tanpa menuntut rilis aplikasi
             *  baru. Aplikasi yang menuliskannya sendiri akan berhenti mengirim
             *  ping begitu alamatnya berubah — dan gejalanya driver yang online
             *  tanpa pernah mendapat order.
             * ==============================================================
             */
            'location' => $this->tiketLokasi($driver),
        ]);
    }

    public function goOffline(Request $request, GoOffline $action): JsonResponse
    {
        $driver = $this->driver($request);

        $session = $action->handle($driver);

        return ApiResponse::success([
            'online' => false,
            'session' => $session === null ? null : [
                'started_at' => $session->started_at->toIso8601String(),
                'ended_at' => $session->ended_at?->toIso8601String(),
                'online_seconds' => (int) $session->online_seconds,
                'orders_completed' => (int) $session->orders_completed,
            ],
        ]);
    }

    /**
     * Alamat dan tiket untuk layanan lokasi.
     *
     * ========================================================================
     *  PING GPS TIDAK PERNAH MENYENTUH LARAVEL
     * ========================================================================
     *  Dia dikirim ke layanan Go di port 8200, yang menulis langsung ke Redis.
     *  Alasannya beban: seribu driver dengan ping tiap empat detik adalah 250
     *  permintaan per detik yang isinya dua angka, dan tidak ada satu pun fitur
     *  framework yang dibutuhkan untuk itu.
     *
     *  Tiketnya bertanda tangan HMAC dan memuat id driver beserta layanan yang
     *  dia aktifkan. Layanan Go memverifikasi tanda tangannya tanpa menyentuh
     *  database sama sekali.
     *
     *  Alamatnya dikirim dari sini, bukan ditulis di aplikasi: layanan lokasi
     *  bisa pindah host atau port tanpa menuntut rilis aplikasi baru. Aplikasi
     *  yang menuliskannya sendiri akan berhenti mengirim ping begitu alamatnya
     *  berubah — dan gejalanya driver yang online tanpa pernah mendapat order.
     * ========================================================================
     *
     * ========================================================================
     *  DAFTAR LAYANAN DIBACA DARI DATABASE, BUKAN DARI `driver_sessions`
     * ========================================================================
     *  Tabel itu tidak punya kolom `service_codes`, dan mode strict Eloquent
     *  (`preventAccessingMissingAttributes`) MELEMPAR pada akses atribut yang
     *  tidak ada — bukan mengembalikan null. Jadi `$session->service_codes ?? []`
     *  akan menjatuhkan seluruh response, bukan jatuh ke cadangannya.
     * ========================================================================
     *
     * @return array{url: string, ticket: string}
     */
    private function tiketLokasi(Driver $driver): array
    {
        return [
            'url' => rtrim((string) config('antaride.location_service.url'), '/').'/v1/ping',
            'ticket' => LocationTicket::issue(
                driverId: (int) $driver->getKey(),
                serviceCodes: $this->layananAktif($driver),
            ),
        ];
    }

    /**
     * Keadaan driver sekarang.
     *
     * Dipanggil aplikasi driver setiap kali dibuka, dan menjadi satu-satunya
     * sumber untuk seluruh layar utama: apakah dia online, apakah ada order
     * berjalan, berapa saldonya, dan apakah dia masih boleh menerima order
     * tunai.
     *
     * Digabung jadi satu endpoint, bukan empat, karena aplikasi driver dipakai
     * di jaringan yang buruk dan setiap request tambahan adalah satu kesempatan
     * lagi untuk gagal.
     */
    public function show(Request $request): JsonResponse
    {
        $driver = $this->driver($request);
        $driverId = (int) $driver->getKey();

        $session = $driver->sessions()->whereNull('ended_at')->first();
        $wallet = Wallet::forOwner('driver', $driverId);

        $activeOrder = Order::query()
            ->where('driver_id', $driverId)
            ->activeForDriver()
            ->first(['uuid', 'status', 'order_number']);

        $depositMinimum = (int) config('antaride.wallet.driver_cash_deposit_minimum', 20_000);

        return ApiResponse::success([
            'driver' => [
                'name' => (string) $driver->full_name,
                'status' => $driver->status->value,
                'rating' => [
                    'average' => (float) $driver->rating_avg,
                    'count' => (int) $driver->rating_count,
                ],
                'acceptance_rate' => (float) $driver->acceptance_rate,
                'cancellation_rate' => (float) $driver->cancellation_rate,
                'completed_orders' => (int) $driver->completed_orders,
            ],

            'online' => $session !== null,
            'session_started_at' => $session?->started_at->toIso8601String(),

            /*
             * ==============================================================
             *  TIKET LOKASI IKUT DI SINI, DAN ITU MEMPERBAIKI KEGAGALAN SENYAP
             * ==============================================================
             *  Tiket lokasi awalnya hanya dikirim `goOnline`. Akibatnya: driver
             *  yang aplikasinya di-restart — ditutup Android karena kehabisan
             *  memori, atau di-swipe sendiri — kembali ke aplikasi yang
             *  menyatakan dia masih online, tanpa tiket.
             *
             *  Yang terjadi kemudian: tidak ada satu pun posisi yang terkirim,
             *  TTL 60 detik di Redis habis, dan dia keluar dari indeks
             *  ketersediaan. Layarnya tetap menyatakan online, dan tidak ada
             *  order yang masuk sampai dia menekan offline lalu online lagi —
             *  yang tidak ada alasan bagi dia untuk mencobanya.
             *
             *  Satu-satunya jalan keluar sebelumnya adalah menutup sesinya, dan
             *  itu menghapus catatan jam kerjanya.
             *
             *  Null kalau tidak ada sesi terbuka. Tiket untuk driver yang tidak
             *  bekerja tidak boleh ada: kalau ada, aplikasi bisa mengirim posisi
             *  driver yang sudah pulang dan dia akan tercatat tersedia.
             * ==============================================================
             */
            'location' => $session === null ? null : $this->tiketLokasi($driver),

            'wallet' => [
                'balance' => $wallet->balance()->jsonSerialize(),

                /*
                 * Kenapa saldo bisa menghalangi order tunai dijelaskan DI SINI,
                 * bukan dibiarkan disimpulkan aplikasi.
                 *
                 * Driver yang berhenti mendapat order tunai tanpa penjelasan
                 * akan menyimpulkan aplikasinya rusak, dan itu keluhan yang
                 * paling sering masuk ke CS dari driver. Satu field boolean plus
                 * ambangnya menyelesaikannya di layar.
                 */
                'can_take_cash_orders' => (int) $wallet->balance >= $depositMinimum,
                'cash_deposit_minimum' => $depositMinimum,
            ],

            'active_order' => $activeOrder === null ? null : [
                'uuid' => (string) $activeOrder->uuid,
                'order_number' => (string) $activeOrder->order_number,
                'status' => $activeOrder->status->value,
            ],

            'today' => $this->todaySummary($driverId),
        ]);
    }

    /**
     * Layanan yang boleh dan sedang diaktifkan driver.
     */
    public function services(Request $request): JsonResponse
    {
        $driver = $this->driver($request);

        $rows = DB::table('driver_service_eligibility')
            ->join('service_types', 'service_types.id', '=', 'driver_service_eligibility.service_type_id')
            ->where('driver_service_eligibility.driver_id', $driver->getKey())
            ->where('service_types.is_active', true)
            ->orderBy('service_types.sort_order')
            ->get([
                'service_types.code',
                'service_types.name',
                'driver_service_eligibility.is_enabled',
                'driver_service_eligibility.enabled_by_driver',
            ]);

        return ApiResponse::success(
            $rows->map(fn ($row): array => [
                'code' => (string) $row->code,
                'name' => (string) $row->name,

                // Dua saklar, dan keduanya ditampilkan. `allowed` milik ops,
                // `enabled` milik driver. Menggabungkannya jadi satu boolean
                // membuat driver tidak bisa membedakan "saya matikan sendiri"
                // dari "belum diizinkan" — dan yang kedua butuh menghubungi CS.
                'allowed' => (bool) $row->is_enabled,
                'enabled' => (bool) $row->enabled_by_driver,
            ])->all(),
        );
    }

    /**
     * Driver menyalakan atau mematikan satu layanan untuk dirinya sendiri.
     */
    public function toggleService(Request $request, string $code): JsonResponse
    {
        $driver = $this->driver($request);

        $enabled = $request->boolean('enabled');

        $serviceTypeId = DB::table('service_types')->where('code', $code)->value('id');

        if ($serviceTypeId === null) {
            return ApiResponse::error('SERVICE_NOT_FOUND', 'Layanan tidak dikenali.', 404);
        }

        /*
         * Hanya `enabled_by_driver` yang diubah, TIDAK `is_enabled`.
         *
         * `is_enabled` adalah hak yang diberikan ops setelah verifikasi
         * kendaraan dan dokumen. Kalau endpoint ini bisa mengubahnya, driver
         * bermotor bisa memberi dirinya hak menerima order mobil.
         */
        $affected = DB::table('driver_service_eligibility')
            ->where('driver_id', $driver->getKey())
            ->where('service_type_id', $serviceTypeId)
            ->update([
                'enabled_by_driver' => $enabled,
                'updated_at' => now(),
            ]);

        if ($affected === 0) {
            return ApiResponse::error(
                'SERVICE_NOT_ALLOWED',
                'Layanan ini belum diizinkan untuk akun Anda. Hubungi bantuan.',
                403,
            );
        }

        /*
         * Mematikan layanan mencabut ketersediaannya SEKARANG.
         *
         * Tanpa ini, driver yang mematikan layanan makanan akan tetap ditawari
         * order makanan sampai dia offline lalu online lagi — dan dia tidak akan
         * tahu harus melakukan itu.
         */
        if (! $enabled) {
            app(DriverLocationIndex::class)->markUnavailableEverywhere((int) $driver->getKey());
        }

        return ApiResponse::success(['code' => $code, 'enabled' => $enabled]);
    }

    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function todaySummary(int $driverId): array
    {
        /*
         * Batas hari memakai zona BISNIS, bukan UTC.
         *
         * Tanpa itu, "pendapatan hari ini" berganti angka pada jam 7 pagi WIB:
         * driver yang bekerja dari jam 5 pagi melihat pendapatannya tiba-tiba
         * kembali nol saat masih bekerja.
         */
        [$mulai, $selesai] = BusinessClock::dayRange();

        $stats = DB::table('orders')
            ->where('driver_id', $driverId)
            ->whereBetween('completed_at', [$mulai, $selesai])
            ->where('status', OrderStatus::Completed->value)
            ->selectRaw('COUNT(*) AS jumlah, COALESCE(SUM(driver_earning), 0) AS pendapatan')
            ->first();

        $onlineSeconds = (int) DB::table('driver_sessions')
            ->where('driver_id', $driverId)
            ->whereBetween('started_at', [$mulai, $selesai])
            ->sum('online_seconds');

        return [
            'orders_completed' => (int) ($stats->jumlah ?? 0),
            'earning' => Money::of(
                (int) ($stats->pendapatan ?? 0)
            )->jsonSerialize(),
            'online_seconds' => $onlineSeconds,
        ];
    }

    /**
     * Kode layanan yang driver ini aktifkan DAN berhak ambil.
     *
     * Dua penyaring, dan keduanya perlu:
     *
     *   `is_enabled`        izin ADMIN, biasanya bergantung kelengkapan dokumen
     *   `enabled_by_driver` pilihan DRIVER sendiri
     *
     * Yang boleh masuk indeks ketersediaan hanya yang lolos keduanya. Memakai
     * salah satunya saja berarti driver mendapat tawaran untuk layanan yang
     * belum diizinkan admin, atau untuk layanan yang sengaja dia matikan.
     *
     * @return list<string>
     */
    private function layananAktif(Driver $driver): array
    {
        return DB::table('driver_service_eligibility')
            ->join('service_types', 'service_types.id', '=', 'driver_service_eligibility.service_type_id')
            ->where('driver_service_eligibility.driver_id', $driver->getKey())
            ->where('driver_service_eligibility.is_enabled', true)
            ->where('driver_service_eligibility.enabled_by_driver', true)
            ->where('service_types.is_active', true)
            ->pluck('service_types.code')
            ->map(static fn ($code): string => (string) $code)
            ->values()
            ->all();
    }

    private function driver(Request $request): Driver
    {
        $driver = Driver::query()
            ->where('user_id', $request->user()->getKey())
            ->with('vehicles')
            ->first();

        abort_if($driver === null, 403, 'Akun Anda bukan akun driver.');

        return $driver;
    }
}
