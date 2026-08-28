<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Actions;

use App\Domain\Driver\Models\Driver;
use App\Domain\Matching\Actions\DispatchOfferWave;
use App\Domain\Ordering\Contracts\OrderLock;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Exceptions\DriverBusyException;
use App\Domain\Ordering\Exceptions\NoOfferForDriverException;
use App\Domain\Ordering\Exceptions\OfferExpiredException;
use App\Domain\Ordering\Exceptions\OrderAlreadyTakenException;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\StateMachine\OrderStateMachine;
use App\Domain\Ordering\StateMachine\OrderTransition;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Driver menerima sebuah order.
 *
 * ============================================================================
 *  INI TITIK PALING RAWAN DI SELURUH SISTEM
 * ============================================================================
 *  Lima driver ditawari order yang sama dan bisa menekan tombol pada milidetik
 *  yang sama. Tepat satu harus menang, empat lainnya harus mendapat penolakan
 *  yang jelas dan cepat, dan TIDAK BOLEH ADA keadaan di mana dua driver
 *  sama-sama diberi tahu bahwa order itu miliknya.
 *
 *  Kalau itu sampai terjadi: dua driver berangkat ke titik yang sama, satu
 *  penumpang, satu ongkos, dan yang kalah menuntut kompensasi atas bensin yang
 *  sudah dia keluarkan. Tidak ada cara memperbaikinya setelah kejadian, jadi
 *  seluruh perlindungan harus berada di jalur ini.
 *
 *  TIGA LAPIS, dari yang paling murah ke yang paling bisa dipercaya:
 *
 *    1. LOCK REDIS (SET NX)
 *       Menahan mayoritas request bersaing sebelum menyentuh database. Murah
 *       dan cepat, tapi TIDAK memberi jaminan mutual exclusion saat Redis
 *       failover atau ada jeda GC. Karena itu bukan penjaga tunggal.
 *
 *    2. SELECT ... FOR UPDATE pada baris order
 *       Memastikan hanya satu transaksi membaca-lalu-menulis baris order pada
 *       satu waktu. Yang menunggu akan membaca status TERBARU setelah yang
 *       pertama commit, dan melihat ordernya sudah punya driver.
 *
 *    3. PARTIAL UNIQUE INDEX orders_one_active_per_driver
 *       Jaring terakhir, dan satu-satunya yang tetap berlaku walaupun Redis
 *       baru restart dan seluruh lock-nya hilang. Dia menegakkan invariant yang
 *       BERBEDA: satu driver tidak boleh punya dua order berjalan. Itu
 *       melindungi kasus yang tidak tersentuh dua lapis di atas — driver yang
 *       menerima DUA order berbeda pada saat yang sama.
 *
 *  Lapis 3 melindungi hal yang berbeda dari lapis 1 dan 2, jadi tidak ada satu
 *  pun yang bisa dihapus dengan alasan "sudah ada yang lain".
 * ============================================================================
 */
class AcceptOrder
{
    public function __construct(
        private readonly OrderLock $lock,
        private readonly OrderStateMachine $stateMachine,
        private readonly DispatchOfferWave $offers,
    ) {}

    public function handle(Order $order, Driver $driver): Order
    {
        $orderId = (int) $order->getKey();
        $driverId = (int) $driver->getKey();

        // --- Lapis 1 -----------------------------------------------------
        if (! $this->lock->acquire($orderId, $driverId)) {
            $holder = $this->lock->heldBy($orderId);

            throw OrderAlreadyTakenException::heldByAnother(
                $holder === $driverId ? null : $holder
            );
        }

        try {
            $accepted = $this->acceptWithinTransaction($order, $driver);
        } catch (\Throwable $e) {
            /*
             * Lock dilepas pada SETIAP kegagalan.
             *
             * Kalau tidak, order yang gagal diterima karena alasan sepele —
             * misalnya penawarannya sudah kadaluarsa dua detik lalu — akan
             * terkunci sampai TTL habis, dan driver lain yang penawarannya masih
             * berlaku tidak bisa mengambilnya. Penumpang menunggu tanpa alasan
             * yang bisa dilihat siapa pun.
             */
            $this->lock->release($orderId, $driverId);

            throw $e;
        }

        /*
         * Lock TIDAK dilepas setelah berhasil, dan itu disengaja.
         *
         * Dibiarkan kadaluarsa sendiri dalam beberapa detik. Melepasnya
         * langsung membuka jendela sempit di mana order sudah punya driver tapi
         * lock-nya kosong; request accept dari driver lain yang datang tepat di
         * jendela itu akan lolos lapis 1 dan baru ditolak di lapis 2. Ditolak
         * memang, tapi dengan biaya satu transaksi database yang tidak perlu,
         * pada momen paling sibuk.
         */

        // Di luar transaksi: sentuhan ke Redis dan realtime tidak boleh menahan
        // lock baris order.
        $this->offers->withdrawAvailability($driverId);

        return $accepted;
    }

    // -------------------------------------------------------------------------

    private function acceptWithinTransaction(Order $order, Driver $driver): Order
    {
        $orderId = (int) $order->getKey();
        $driverId = (int) $driver->getKey();

        return DB::transaction(function () use ($orderId, $driver, $driverId): Order {
            // --- Lapis 2 -------------------------------------------------
            $fresh = Order::query()->lockForUpdate()->find($orderId);

            if ($fresh === null) {
                throw OrderAlreadyTakenException::orderGone();
            }

            if ($fresh->status !== OrderStatus::Searching) {
                throw OrderAlreadyTakenException::statusChanged($fresh->status);
            }

            $this->assertOfferValid($orderId, $driverId);
            $this->assertDriverFree($driverId);

            $vehicleId = $this->resolveVehicle($driver, $fresh);

            try {
                /*
                 * --- Lapis 3 ---------------------------------------------
                 * Transisi ini yang menulis driver_id, dan partial unique index
                 * yang menolaknya kalau driver sudah punya order berjalan.
                 *
                 * Hasilnya HARUS dipakai, bukan dibuang. State machine mengunci
                 * dan memperbarui instance-nya sendiri, jadi `$fresh` yang ada
                 * di memori di sini tetap memegang status lama. Mengembalikan
                 * `$fresh` berarti controller mengirim ke aplikasi driver bahwa
                 * ordernya masih `searching` padahal di database sudah
                 * `accepted` — dan aplikasi akan menampilkan tombol terima lagi
                 * untuk order yang baru saja dia ambil.
                 */
                $accepted = $this->stateMachine->apply(
                    $fresh,
                    OrderTransition::byDriver(
                        to: OrderStatus::Accepted,
                        driverId: $driverId,
                        metadata: array_filter(
                            ['vehicle_id' => $vehicleId],
                            static fn ($v): bool => $v !== null,
                        ),
                    ),
                );
            } catch (QueryException $e) {
                if ($this->isActiveOrderConflict($e)) {
                    throw DriverBusyException::alreadyHasActiveOrder();
                }

                throw $e;
            }

            $this->markOfferAccepted($orderId, $driverId);

            return $accepted;
        });
    }

    /**
     * Driver hanya boleh menerima order yang memang ditawarkan kepadanya, dan
     * penawarannya harus masih berlaku.
     *
     * Tanpa pemeriksaan ini, siapa pun yang punya token driver bisa menerima
     * order APA PUN yang statusnya masih mencari — cukup dengan menebak UUID
     * order atau membacanya dari channel realtime. Yang terjadi berikutnya:
     * driver yang tidak pernah ditawari mengambil order-order terbaik, dan
     * seluruh sistem skoring beserta bobot keadilannya menjadi tidak berarti.
     */
    private function assertOfferValid(int $orderId, int $driverId): void
    {
        $offer = DB::table('order_offers')
            ->where('order_id', $orderId)
            ->where('driver_id', $driverId)
            ->first();

        if ($offer === null) {
            throw NoOfferForDriverException::make();
        }

        if ($offer->response === 'rejected') {
            throw NoOfferForDriverException::alreadyRejected();
        }

        /*
         * Kadaluarsa diperiksa terhadap waktu server, bukan waktu yang dikirim
         * HP driver.
         *
         * Jam HP bisa diubah, dan penawaran yang "masih berlaku menurut HP saya"
         * adalah cara paling mudah untuk menerima order yang sudah ditawarkan ke
         * orang lain.
         */
        if ($offer->expires_at !== null && now()->greaterThan($offer->expires_at)) {
            throw OfferExpiredException::make();
        }
    }

    /**
     * Driver tidak boleh sedang memegang order lain.
     *
     * Diperiksa di sini walaupun lapis 3 juga menegakkannya, karena pesan error
     * dari CHECK constraint tidak bisa dibaca driver. Yang ini menghasilkan
     * "Selesaikan dulu order yang sedang berjalan", yang bisa langsung
     * ditindaklanjuti.
     */
    private function assertDriverFree(int $driverId): void
    {
        $hasActive = Order::query()
            ->where('driver_id', $driverId)
            ->whereIn('status', OrderStatus::activeValues())
            ->exists();

        if ($hasActive) {
            throw DriverBusyException::alreadyHasActiveOrder();
        }
    }

    /**
     * Kendaraan yang dipakai untuk order ini.
     *
     * Disimpan di order, bukan dibaca dari driver saat dibutuhkan, karena
     * driver bisa mengganti kendaraan besok dan sengketa "plat nomor yang
     * mengantar saya" harus terjawab dengan kendaraan saat itu.
     */
    private function resolveVehicle(Driver $driver, Order $order): ?int
    {
        $vehicles = $driver->relationLoaded('vehicles')
            ? $driver->vehicles
            : $driver->vehicles()->get();

        $match = $vehicles->first(
            static fn ($vehicle): bool => (bool) ($vehicle->is_active ?? true)
        );

        return $match === null ? null : (int) $match->getKey();
    }

    private function markOfferAccepted(int $orderId, int $driverId): void
    {
        $now = now();

        DB::table('order_offers')
            ->where('order_id', $orderId)
            ->where('driver_id', $driverId)
            ->update([
                'response' => 'accepted',
                'responded_at' => $now,
                'updated_at' => $now,
            ]);

        /*
         * Penawaran ke driver lain ditandai 'lost', bukan dihapus.
         *
         * Dihapus berarti kehilangan bahan perhitungan acceptance_rate: driver
         * yang kalah balapan tidak boleh dihitung sebagai "tidak merespons",
         * karena dia tidak punya kesempatan. Menghapusnya membuat statistik itu
         * tidak bisa dibedakan dari driver yang mengabaikan penawaran.
         */
        DB::table('order_offers')
            ->where('order_id', $orderId)
            ->where('driver_id', '!=', $driverId)
            ->where('response', 'pending')
            ->update([
                'response' => 'lost',
                'responded_at' => $now,
                'updated_at' => $now,
            ]);
    }

    /**
     * Apakah exception ini pelanggaran partial unique index satu-order-aktif.
     *
     * Nama index-nya diperiksa, bukan hanya kode SQLSTATE-nya. Kode 23505
     * dipakai SETIAP pelanggaran unique di database ini — termasuk tabrakan
     * order_number, yang butuh penanganan sama sekali berbeda. Memperlakukan
     * semuanya sebagai "driver sedang sibuk" akan menyembunyikan bug lain di
     * balik pesan yang terdengar wajar.
     */
    private function isActiveOrderConflict(QueryException $e): bool
    {
        return str_contains($e->getMessage(), 'orders_one_active_per_driver');
    }
}
