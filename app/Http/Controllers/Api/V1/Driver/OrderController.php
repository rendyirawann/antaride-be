<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Driver;

use App\Domain\Driver\Models\Driver;
use App\Domain\Ordering\Actions\AcceptOrder;
use App\Domain\Ordering\Actions\CancelOrder;
use App\Domain\Ordering\Actions\CompleteOrder;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\CancellationReason;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\StateMachine\OrderStateMachine;
use App\Domain\Ordering\StateMachine\OrderTransition;
use App\Domain\Shared\ValueObjects\Coordinate;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Driver\CompleteOrderRequest;
use App\Http\Requests\Api\V1\Driver\DriverCancelOrderRequest;
use App\Http\Requests\Api\V1\Driver\TransitionOrderRequest;
use App\Http\Resources\Api\V1\DriverOrderResource;
use App\Http\Responses\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Order dari sisi driver.
 *
 * ============================================================================
 *  DUA CARA MEMUAT ORDER, DAN KEDUANYA DIBATASI
 * ============================================================================
 *    offeredOrder()  order yang DITAWARKAN ke driver ini tapi belum diterima.
 *                    Dipakai hanya oleh `accept`.
 *    assignedOrder() order yang SUDAH menjadi milik driver ini.
 *                    Dipakai seluruh endpoint lain.
 *
 *  Keduanya dipisah karena haknya berbeda. Order yang baru ditawarkan belum
 *  boleh dibaca lengkap — data penumpangnya belum boleh dibuka — sementara order
 *  yang sudah diterima memang harus lengkap supaya drivernya bisa menjemput.
 *
 *  Yang TIDAK ada di sini: cara memuat order tanpa memeriksa hubungan dengan
 *  driver yang sedang login. Endpoint yang lupa memeriksanya berarti setiap
 *  driver bisa mengubah status order milik driver lain.
 * ============================================================================
 */
class OrderController extends Controller
{
    /**
     * Penawaran yang sedang berlaku untuk driver ini.
     *
     * Dipanggil aplikasi driver saat dibuka dari notifikasi. Jalur normalnya
     * lewat realtime; endpoint ini cadangan untuk saat pesan realtime-nya
     * hilang, yang di lapangan sering terjadi.
     */
    public function offers(Request $request): JsonResponse
    {
        $driver = $this->driver($request);

        $orders = Order::query()
            ->join('order_offers', 'order_offers.order_id', '=', 'orders.id')
            ->where('order_offers.driver_id', $driver->getKey())
            ->where('order_offers.response', 'pending')
            ->where('order_offers.expires_at', '>', now())
            ->where('orders.status', OrderStatus::Searching->value)
            ->select('orders.*', 'order_offers.expires_at as offer_expires_at',
                'order_offers.distance_to_pickup_m as offer_distance_m')
            ->with('serviceType')
            ->orderBy('order_offers.offered_at')
            ->get();

        return ApiResponse::success(
            $orders->map(fn (Order $o): array => [
                'order_uuid' => (string) $o->uuid,
                'service_code' => (string) $o->serviceType->code,
                'payment_method' => (string) $o->payment_method,

                'pickup' => [
                    'address' => (string) $o->pickup_address,
                    'lat' => (float) $o->pickup_lat,
                    'lng' => (float) $o->pickup_lng,
                ],

                'destination' => $o->dest_lat === null ? null : [
                    'address' => (string) $o->dest_address,
                    'lat' => (float) $o->dest_lat,
                    'lng' => (float) $o->dest_lng,
                ],

                'trip' => [
                    'distance_m' => (int) $o->distance_m,
                    'duration_s' => (int) $o->duration_s,
                ],

                'driver_earning' => $o->driverEarning()->jsonSerialize(),
                'distance_to_pickup_m' => (int) $o->offer_distance_m,
                /*
                 * ==========================================================
                 *  WAJIB ISO 8601 DENGAN OFFSET, BUKAN STRING MENTAH DB
                 * ==========================================================
                 *  `offer_expires_at` adalah alias dari SELECT, jadi Eloquent
                 *  TIDAK meng-cast-nya ke Carbon — nilainya string mentah
                 *  Postgres seperti "2026-08-28 05:47:29", tanpa penanda zona.
                 *
                 *  Aplikasi Flutter mengurainya dengan `DateTime.tryParse`, yang
                 *  memperlakukan string tanpa penanda zona sebagai waktu LOKAL.
                 *  Nilainya UTC, dan WIB adalah UTC+7 — jadi tawaran yang masih
                 *  berlaku 15 detik lagi terbaca sebagai kadaluarsa tujuh jam
                 *  yang lalu.
                 *
                 *  Akibatnya: SETIAP tawaran disaring keluar oleh
                 *  `DriverOffer.isExpired` dan tidak ada satu pun kartu tawaran
                 *  yang tampil. Driver online, motornya di tempat, dan tidak ada
                 *  order yang masuk — tanpa satu pun galat di kedua sisi.
                 * ==========================================================
                 */
                'expires_at' => Carbon::parse((string) $o->offer_expires_at)
                    ->toIso8601String(),
            ])->all(),
        );
    }

    /**
     * Order yang sedang dipegang driver ini.
     */
    public function active(Request $request): JsonResponse
    {
        $driver = $this->driver($request);

        $order = Order::query()
            ->where('driver_id', $driver->getKey())
            ->activeForDriver()
            ->with(['serviceType', 'user'])
            ->first();

        return ApiResponse::success(
            $order === null ? null : (new DriverOrderResource($order))->resolve(),
        );
    }

    /**
     * Alasan pembatalan yang boleh dipilih DRIVER.
     *
     * ==========================================================================
     *  KENAPA TERPISAH DARI ENDPOINT PENUMPANG
     * ==========================================================================
     *  `cancellation_reasons` disaring per `actor_type`, dan penyaringan itu
     *  ditegakkan validasi: driver yang mengirim kode milik penumpang ditolak
     *  422.
     *
     *  Tanpa endpoint ini, aplikasi driver tidak punya cara mengetahui kode yang
     *  sah — dan yang terjadi adalah daftar yang ditulis di dalam aplikasi, lalu
     *  menyimpang dari tabel begitu ada satu alasan yang ditambah atau diubah
     *  admin. Gejalanya: tombol batalkan yang selalu ditolak tanpa penjelasan.
     * ==========================================================================
     *
     * ==========================================================================
     *  `lowers_score` DIKIRIM, DAN ITU KEPUTUSAN YANG DISENGAJA
     * ==========================================================================
     *  Sebagian alasan menurunkan skor pembatalan driver — yang ikut menentukan
     *  prioritasnya di mesin pencocokan. Menyembunyikannya berarti driver
     *  memilih alasan yang paling menggambarkan keadaan, lalu mendapati order
     *  yang masuk berkurang tanpa tahu sebabnya.
     *
     *  Risikonya diketahui: driver bisa memilih alasan yang tidak menurunkan
     *  skor walaupun kurang tepat. Itu tetap lebih baik daripada sistem yang
     *  menghukum tanpa memberi tahu — dan alasan yang dipilih tetap tercatat
     *  beserta catatan wajibnya, jadi pola penyalahgunaan tetap terlihat di
     *  panel admin.
     * ==========================================================================
     */
    public function cancellationReasons(Request $request): JsonResponse
    {
        // Dipanggil hanya untuk memastikan pemanggilnya benar-benar driver.
        // Daftar ini bukan rahasia, tapi endpoint driver yang bisa dibuka
        // pengguna biasa adalah pintu untuk memetakan mana yang driver.
        $this->driver($request);

        $reasons = CancellationReason::query()
            ->forActor('driver')
            ->get(['code', 'text', 'charges_fee', 'affects_driver_score']);

        return ApiResponse::success(
            $reasons->map(fn (CancellationReason $r): array => [
                'code' => (string) $r->code,
                'text' => (string) $r->text,
                'lowers_score' => (bool) $r->affects_driver_score,

                // Dikirim untuk keseragaman bentuk dengan endpoint penumpang.
                // Untuk alasan driver nilainya selalu false di Fase 1 — driver
                // tidak dikenai biaya pembatalan — tapi aplikasi tidak boleh
                // mengandalkan itu, karena kebijakannya bisa berubah tanpa
                // rilis aplikasi baru.
                'may_charge_fee' => (bool) $r->charges_fee,
            ])->all(),
        );
    }

    public function accept(Request $request, AcceptOrder $action, string $uuid): JsonResponse
    {
        $driver = $this->driver($request);

        // Order dimuat TANPA memeriksa driver_id: order yang mau diterima
        // belum punya driver. Yang memeriksa hak adalah Action, lewat tabel
        // order_offers.
        $order = Order::query()->where('uuid', $uuid)->firstOrFail();

        $accepted = $action->handle($order, $driver);
        $accepted->load(['serviceType', 'user']);

        return ApiResponse::success((new DriverOrderResource($accepted))->resolve());
    }

    /**
     * Tolak penawaran.
     *
     * Bukan pembatalan order: ordernya tetap dicarikan driver lain. Yang
     * berubah hanya penawaran milik driver ini.
     */
    public function reject(Request $request, string $uuid): JsonResponse
    {
        $driver = $this->driver($request);

        $order = Order::query()->where('uuid', $uuid)->firstOrFail();

        $updated = DB::table('order_offers')
            ->where('order_id', $order->getKey())
            ->where('driver_id', $driver->getKey())
            ->where('response', 'pending')
            ->update([
                'response' => 'rejected',
                'responded_at' => now(),
                'updated_at' => now(),
            ]);

        /*
         * Penolakan yang tidak mengenai baris apa pun bukan error.
         *
         * Bisa terjadi kalau penawarannya sudah kadaluarsa, atau driver lain
         * sudah mengambil ordernya. Aplikasi driver menutup dialognya dengan
         * cara yang sama, jadi membalas error hanya menghasilkan pesan
         * membingungkan untuk keadaan yang wajar.
         */
        return ApiResponse::success([
            'rejected' => $updated > 0,
        ]);
    }

    /**
     * Ubah status order yang sedang dipegang.
     *
     * Satu endpoint untuk seluruh transisi — menuju penjemputan, tiba, mulai —
     * bukan satu endpoint per transisi. Alasannya: aturan transisi yang sah ada
     * di state machine, dan endpoint terpisah per transisi berarti daftar aturan
     * yang sama tersebar di beberapa route.
     */
    public function transition(
        TransitionOrderRequest $request,
        OrderStateMachine $stateMachine,
        string $uuid,
    ): JsonResponse {
        $driver = $this->driver($request);
        $order = $this->assignedOrder($driver, $uuid);

        $target = OrderStatus::from((string) $request->validated('status'));

        $updated = $stateMachine->apply(
            $order,
            OrderTransition::byDriver(
                to: $target,
                driverId: (int) $driver->getKey(),
                coordinate: $request->coordinate(),
            ),
        );

        $updated->load(['serviceType', 'user']);

        return ApiResponse::success((new DriverOrderResource($updated))->resolve());
    }

    /**
     * Mulai perjalanan, setelah kode jemput diverifikasi.
     */
    public function startTrip(
        Request $request,
        OrderStateMachine $stateMachine,
        string $uuid,
    ): JsonResponse {
        $driver = $this->driver($request);
        $order = $this->assignedOrder($driver, $uuid);

        $code = (string) $request->input('pickup_code', '');

        /*
         * Kode jemput dibandingkan dengan hash_equals, bukan ===.
         *
         * Perbandingan string biasa berhenti pada karakter pertama yang berbeda,
         * jadi lamanya bergantung pada berapa karakter awal yang benar. Untuk
         * kode empat digit, selisih waktunya kecil — tapi dengan cukup banyak
         * percobaan, itu tetap bisa diukur, dan tidak ada alasan menerima
         * risikonya ketika alternatifnya satu pemanggilan fungsi.
         */
        if ($order->pickup_code !== null && ! hash_equals((string) $order->pickup_code, $code)) {
            return ApiResponse::error(
                'INVALID_PICKUP_CODE',
                'Kode jemput tidak cocok. Minta penumpang menyebutkan kodenya lagi.',
                422,
            );
        }

        $updated = $stateMachine->apply(
            $order,
            OrderTransition::byDriver(
                to: OrderStatus::InProgress,
                driverId: (int) $driver->getKey(),
                coordinate: $this->coordinateFrom($request),
            ),
        );

        $updated->load(['serviceType', 'user']);

        return ApiResponse::success((new DriverOrderResource($updated))->resolve());
    }

    public function complete(
        CompleteOrderRequest $request,
        CompleteOrder $action,
        string $uuid,
    ): JsonResponse {
        $driver = $this->driver($request);
        $order = $this->assignedOrder($driver, $uuid);

        $completed = $action->handle(
            order: $order,
            driverId: (int) $driver->getKey(),
            at: $request->coordinate(),
            actualRoute: $request->actualRoute(),
            actualDistanceM: $request->validated('actual_distance_m'),
        );

        $completed->load(['serviceType', 'user']);

        return ApiResponse::success((new DriverOrderResource($completed))->resolve());
    }

    public function cancel(
        DriverCancelOrderRequest $request,
        CancelOrder $action,
        string $uuid,
    ): JsonResponse {
        $driver = $this->driver($request);
        $order = $this->assignedOrder($driver, $uuid);

        $cancelled = $action->handle(
            order: $order,
            actorType: 'driver',
            actorId: (int) $driver->getKey(),
            reasonCode: $request->validated('reason_code'),
            note: $request->validated('note'),
        );

        $cancelled->load(['serviceType', 'user']);

        return ApiResponse::success((new DriverOrderResource($cancelled))->resolve());
    }

    // -------------------------------------------------------------------------

    private function driver(Request $request): Driver
    {
        /*
         * Profil driver dimuat dari user yang login, bukan dari parameter.
         *
         * Menerima driver_id dari client — bahkan hanya sebagai "optimasi" —
         * berarti satu endpoint yang lupa memeriksanya membuat setiap driver
         * bisa bertindak sebagai driver lain.
         */
        $driver = Driver::query()
            ->where('user_id', $request->user()->getKey())
            ->with('vehicles')
            ->first();

        abort_if($driver === null, 403, 'Akun Anda bukan akun driver.');

        return $driver;
    }

    /**
     * Order yang sudah menjadi milik driver ini.
     *
     * 404, bukan 403: order driver lain harus tampak seperti tidak ada.
     */
    private function assignedOrder(Driver $driver, string $uuid): Order
    {
        return Order::query()
            ->where('uuid', $uuid)
            ->where('driver_id', $driver->getKey())
            ->with(['serviceType', 'user'])
            ->firstOrFail();
    }

    private function coordinateFrom(Request $request): ?Coordinate
    {
        $lat = $request->input('lat');
        $lng = $request->input('lng');

        if ($lat === null || $lng === null) {
            return null;
        }

        return Coordinate::of((float) $lat, (float) $lng);
    }
}
