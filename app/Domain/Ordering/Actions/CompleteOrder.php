<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Actions;

use App\Domain\Matching\Contracts\DriverLocationIndex;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\StateMachine\OrderStateMachine;
use App\Domain\Ordering\StateMachine\OrderTransition;
use App\Domain\Shared\ValueObjects\Coordinate;
use App\Domain\Shared\ValueObjects\Polyline;
use App\Domain\Wallet\Actions\SettleOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Driver menyelesaikan order.
 *
 * ============================================================================
 *  SETTLEMENT DAN TRANSISI HARUS SATU TRANSAKSI
 * ============================================================================
 *  Kalau dipisah, ada satu keadaan yang tidak bisa diperbaiki: order tercatat
 *  `completed` tapi uangnya belum dibagi, atau sebaliknya.
 *
 *  Yang pertama berarti driver menyelesaikan perjalanan dan tidak dibayar, dan
 *  tidak ada yang tahu sampai dia mengeluh. Yang kedua berarti driver dibayar
 *  untuk order yang masih terlihat berjalan — dan karena partial unique index
 *  melarang dua order berjalan, dia tidak bisa menerima order berikutnya sampai
 *  seseorang memperbaiki barisnya lewat psql.
 *
 *  Keduanya tidak bisa dibereskan otomatis, jadi satu-satunya jawaban adalah
 *  tidak membiarkannya terjadi.
 * ============================================================================
 *
 * ============================================================================
 *  ORDER YANG JARAKNYA JAUH MENYIMPANG TIDAK DI-SETTLE OTOMATIS
 * ============================================================================
 *  Ongkos dibekukan saat order dibuat, berdasarkan estimasi rute. Kalau jarak
 *  sebenarnya jauh berbeda, ada satu dari tiga hal yang terjadi:
 *
 *    - penumpang mengubah tujuan di tengah jalan
 *    - driver mengambil jalan yang jauh berbeda
 *    - GPS-nya kacau
 *
 *  Ketiganya butuh manusia melihatnya. Ordernya tetap SELESAI — penumpang sudah
 *  sampai, dan menahan status order tidak membantu siapa pun — tapi
 *  `needs_fare_review` dinyalakan dan pembagian uangnya menunggu.
 *
 *  Batas penyimpangannya diatur `gps.fare_review_deviation_percent`.
 * ============================================================================
 */
class CompleteOrder
{
    public function __construct(
        private readonly OrderStateMachine $stateMachine,
        private readonly SettleOrder $settleOrder,
        private readonly DriverLocationIndex $locationIndex,
    ) {}

    /**
     * @param  Polyline|null  $actualRoute  jejak GPS sungguhan, dari aplikasi driver
     */
    public function handle(
        Order $order,
        int $driverId,
        ?Coordinate $at = null,
        ?Polyline $actualRoute = null,
        ?int $actualDistanceM = null,
    ): Order {
        $completed = DB::transaction(function () use (
            $order,
            $driverId,
            $at,
            $actualRoute,
            $actualDistanceM,
        ): Order {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->getKey());

            $this->recordActualRoute($locked, $actualRoute, $actualDistanceM);

            $completed = $this->stateMachine->apply(
                $locked,
                OrderTransition::byDriver(
                    to: OrderStatus::Completed,
                    driverId: $driverId,
                    coordinate: $at,
                ),
            );

            if (! $completed->needs_fare_review) {
                $this->settleOrder->handle($completed);
            }

            $this->bumpDriverCounters($completed);

            return $completed;
        });

        // Di luar transaksi: Redis tidak boleh menahan lock baris order.
        $this->locationIndex->markUnavailableEverywhere($driverId);

        if ($completed->needs_fare_review) {
            Log::channel('matching')->warning('Order butuh review ongkos, settlement ditunda', [
                'order_id' => $completed->getKey(),
                'order_number' => $completed->order_number,
                'estimasi_m' => (int) $completed->distance_m,
                'aktual_m' => (int) $completed->actual_distance_m,
                'penyimpangan_persen' => $completed->distanceVariancePercent(),
            ]);
        }

        return $completed;
    }

    // -------------------------------------------------------------------------

    /**
     * Simpan jejak GPS sungguhan, dan tandai kalau menyimpang jauh.
     */
    private function recordActualRoute(
        Order $order,
        ?Polyline $actualRoute,
        ?int $actualDistanceM,
    ): void {
        if ($actualRoute !== null && ! $actualRoute->isEmpty()) {
            /*
             * Polyline disederhanakan sebelum disimpan.
             *
             * Jejak GPS mentah dari perjalanan 20 menit dengan ping tiap 4 detik
             * berisi 300 titik, dan hampir semuanya berada di garis lurus antara
             * dua titik lain. Menyimpannya utuh membuat kolom teks ini menjadi
             * salah satu yang terbesar di database, tanpa menambah satu pun
             * informasi yang berguna untuk sengketa ongkos.
             */
            $order->actual_polyline = $actualRoute->simplified()->encode();
        }

        /*
         * Jarak aktual diambil dari polyline, bukan dari angka yang dikirim
         * client.
         *
         * Angka jarak yang dikirim aplikasi adalah angka yang paling menarik
         * untuk dipalsukan: jarak lebih besar berarti order masuk review dan
         * bisa dinaikkan ongkosnya. Menghitungnya dari jejak titik membuat
         * pemalsuan menuntut pemalsuan seluruh jejak, yang jauh lebih sulit dan
         * jauh lebih terlihat di peta panel admin.
         *
         * Angka dari client tetap diterima sebagai cadangan kalau polyline-nya
         * tidak ada — sebagian perangkat lama tidak mengirimkannya — tapi
         * urutannya jelas: polyline lebih dipercaya.
         */
        if ($actualRoute !== null && ! $actualRoute->isEmpty()) {
            $order->actual_distance_m = (int) round($actualRoute->lengthMeters());
        } elseif ($actualDistanceM !== null) {
            $order->actual_distance_m = $actualDistanceM;
        }

        $order->needs_fare_review = $this->deviatesTooMuch($order);
        $order->save();
    }

    private function deviatesTooMuch(Order $order): bool
    {
        if ($order->actual_distance_m === null || (int) $order->distance_m <= 0) {
            return false;
        }

        $limit = (float) config('antaride.gps.fare_review_deviation_percent', 30);

        $deviation = abs(
            (((int) $order->actual_distance_m - (int) $order->distance_m) / (int) $order->distance_m) * 100
        );

        return $deviation > $limit;
    }

    /**
     * Naikkan penghitung order selesai driver.
     *
     * increment(), bukan baca-lalu-tulis. Dua order yang selesai pada saat yang
     * sama akan menaikkan penghitung dua kali; membaca lalu menulis akan
     * kehilangan salah satunya, dan penghitung yang meleset itu ikut menentukan
     * insentif harian driver.
     */
    private function bumpDriverCounters(Order $order): void
    {
        if ($order->driver_id === null) {
            return;
        }

        DB::table('drivers')
            ->where('id', $order->driver_id)
            ->increment('completed_orders');
    }
}
