<?php

declare(strict_types=1);

namespace App\Domain\Ordering\StateMachine;

use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Exceptions\InvalidStatusTransitionException;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderStatusLog;
use App\Domain\Shared\Contracts\RealtimePublisher;
use App\Domain\Shared\ValueObjects\RealtimeChannel;
use App\Domain\Support\Actions\SendNotification;
use App\Domain\Support\Models\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Satu-satunya jalan mengubah status order.
 *
 * ============================================================================
 *  KENAPA HARUS LEWAT SINI
 * ============================================================================
 *  Setiap transisi melakukan EMPAT hal yang harus terjadi bersamaan:
 *
 *    1. Memeriksa transisi diizinkan state machine
 *    2. Mengunci baris order dan memeriksa ulang statusnya (SELECT FOR UPDATE)
 *    3. Mengisi kolom cap waktu yang sesuai
 *    4. Mencatat ke order_status_logs
 *
 *  Lalu, DI LUAR transaksi, mengirim event realtime.
 *
 *  Mengubah status dengan `$order->update(['status' => ...])` melewati
 *  keempatnya. Akibat yang paling terasa: penumpang tidak pernah tahu drivernya
 *  sudah tiba, karena event realtime tidak terkirim. Yang paling sulit dilacak:
 *  timeline order kosong, sehingga saat ada sengketa tidak ada yang bisa
 *  menjelaskan urutan kejadiannya.
 * ============================================================================
 *
 *  KENAPA PEMERIKSAAN DILAKUKAN DUA KALI
 *
 *  Sekali sebelum transaksi (murah, menolak mayoritas panggilan salah), sekali
 *  lagi di dalam transaksi setelah baris dikunci. Yang kedua itu yang benar:
 *  antara pemeriksaan pertama dan pengambilan lock, status bisa sudah berubah
 *  oleh request lain. Ini bukan kemungkinan teoretis — driver menekan tombol
 *  dua kali karena aplikasi terasa lambat adalah kejadian harian.
 */
class OrderStateMachine
{
    public function __construct(
        private readonly RealtimePublisher $realtime,
        private readonly SendNotification $notifikasi,
    ) {}

    /**
     * Jalankan transisi.
     *
     * Mengembalikan order dengan status baru. Melempar
     * InvalidStatusTransitionException kalau transisinya tidak diizinkan.
     */
    public function apply(Order $order, OrderTransition $transition): Order
    {
        // Pemeriksaan murah lebih dulu, sebelum menyentuh database.
        $this->assertAllowed($order->status, $transition->to);

        $result = DB::transaction(function () use ($order, $transition): array {
            // Kunci baris. Ini yang membuat dua request bersaing menjadi
            // berurutan, bukan bersamaan.
            /** @var Order $locked */
            $locked = Order::query()
                ->lockForUpdate()
                ->findOrFail($order->getKey());

            $from = $locked->status;

            // Pemeriksaan kedua, setelah lock. Statusnya bisa sudah berubah.
            $this->assertAllowed($from, $transition->to);

            $locked->status = $transition->to;

            // Kolom cap waktu diisi dari enum, bukan dari daftar if-else di
            // sini. Menambah status baru cukup mengubah satu tempat.
            $timestampColumn = $transition->to->timestampColumn();

            if ($timestampColumn !== null && $locked->{$timestampColumn} === null) {
                $locked->{$timestampColumn} = now();
            }

            $this->applyExtraColumns($locked, $transition);

            $locked->save();

            OrderStatusLog::create([
                'order_id' => $locked->getKey(),
                'from_status' => $from,
                'to_status' => $transition->to,
                'actor_type' => $transition->actorType,
                'actor_id' => $transition->actorId,
                'lat' => $transition->coordinate?->lat,
                'lng' => $transition->coordinate?->lng,
                'note' => $transition->note,
                'metadata' => $transition->metadata === [] ? null : $transition->metadata,
            ]);

            return ['order' => $locked, 'from' => $from];
        });

        /** @var Order $updated */
        $updated = $result['order'];

        /** @var OrderStatus $from */
        $from = $result['from'];

        // Event realtime dikirim DI LUAR transaksi.
        //
        // Kalau di dalam, gateway realtime yang lambat akan menahan lock baris
        // order selama itu, dan request lain yang menunggu lock yang sama ikut
        // tertahan. Dengan 1.000 driver aktif, satu Centrifugo yang lambat
        // sepuluh detik bisa membekukan seluruh penerimaan order.
        //
        // Konsekuensinya: event bisa gagal terkirim setelah status sudah
        // berubah. Itu diterima. Yang hilang hanya pembaruan langsung di layar;
        // aplikasi tetap bisa menarik ulang statusnya.
        $this->publish($updated, $from, $transition);

        /*
         * Notifikasi dibuat DI LUAR transaksi, sama seperti event realtime.
         *
         * Alasannya sama: menahan lock baris order sementara menulis baris lain
         * berarti setiap request yang menunggu lock itu ikut tertahan.
         *
         * Alasan tambahan yang khusus untuk notifikasi: `SendNotification` TIDAK
         * PERNAH melempar. Menaruhnya di dalam transaksi berarti kegagalannya
         * yang senyap ikut menahan transaksi tanpa membatalkannya — lebih buruk
         * daripada gagal di luar, di mana statusnya sudah aman ter-commit.
         */
        $this->beriTahu($updated, $transition);

        return $updated;
    }

    /**
     * Apakah transisi ini akan diterima, tanpa menjalankannya.
     *
     * Dipakai panel admin untuk menyembunyikan tombol yang tidak akan berhasil,
     * dan API untuk memberi pesan yang tepat sebelum mencoba.
     */
    public function canApply(Order $order, OrderStatus $to): bool
    {
        return $order->status->canTransitionTo($to);
    }

    // -------------------------------------------------------------------------

    private function assertAllowed(OrderStatus $from, OrderStatus $to): void
    {
        if (! $from->canTransitionTo($to)) {
            throw InvalidStatusTransitionException::between($from, $to);
        }
    }

    /**
     * Kolom tambahan yang harus terisi bersamaan dengan status tertentu.
     *
     * Dilakukan di sini, bukan di pemanggil, karena database punya CHECK
     * constraint yang menuntutnya. `orders_completed_shape_check` menolak order
     * completed tanpa driver_id dan completed_at; kalau pengisiannya diserahkan
     * ke pemanggil, satu jalur yang lupa akan gagal dengan pesan constraint
     * yang tidak menjelaskan apa yang harus diisi.
     */
    private function applyExtraColumns(Order $order, OrderTransition $transition): void
    {
        if ($transition->to === OrderStatus::Accepted) {
            $this->applyAcceptanceColumns($order, $transition);

            return;
        }

        if ($transition->to !== OrderStatus::Cancelled) {
            return;
        }

        // Siapa yang membatalkan diambil dari aktor transisi, bukan dari
        // parameter terpisah. Dua sumber untuk informasi yang sama pasti akan
        // berbeda suatu hari.
        $order->cancelled_by = match ($transition->actorType) {
            'user' => 'user',
            'driver' => 'driver',
            'admin' => 'admin',
            default => 'system',
        };

        if (isset($transition->metadata['cancellation_reason_id'])) {
            $order->cancellation_reason_id = (int) $transition->metadata['cancellation_reason_id'];
        }

        if (isset($transition->metadata['cancellation_fee'])) {
            $order->cancellation_fee = (int) $transition->metadata['cancellation_fee'];
        }

        if ($transition->note !== null) {
            $order->cancellation_note = $transition->note;
        }
    }

    /**
     * Kolom yang wajib terisi saat order diterima driver.
     *
     * driver_id diambil dari AKTOR transisi, bukan dari parameter terpisah.
     * Alasannya sama seperti `cancelled_by` di bawah: dua sumber untuk
     * informasi yang sama pasti akan berbeda suatu hari, dan kalau itu terjadi
     * di sini hasilnya adalah order yang tercatat diterima driver A di
     * `order_status_logs` tapi dikerjakan driver B menurut tabel orders.
     *
     * Kendaraan disimpan sebagai snapshot, bukan dibaca dari driver saat
     * dibutuhkan. Driver bisa mengganti kendaraan besok, dan pertanyaan "plat
     * nomor apa yang mengantar saya" harus terjawab dengan kendaraan saat itu.
     */
    private function applyAcceptanceColumns(Order $order, OrderTransition $transition): void
    {
        if ($transition->actorType === 'driver' && $transition->actorId !== null) {
            $order->driver_id = $transition->actorId;
        } elseif (isset($transition->metadata['driver_id'])) {
            // Jalur admin memaksa assign driver. Aktornya admin, jadi driver
            // yang dituju harus disebut eksplisit.
            $order->driver_id = (int) $transition->metadata['driver_id'];
        }

        if (isset($transition->metadata['vehicle_id'])) {
            $order->vehicle_id = (int) $transition->metadata['vehicle_id'];
        }
    }

    /**
     * Buat notifikasi in-app untuk perubahan status ini.
     *
     * ========================================================================
     *  TIDAK SETIAP TRANSISI MENGHASILKAN NOTIFIKASI
     * ========================================================================
     *  Order melewati enam status dari dibuat sampai selesai. Memberitahu
     *  penumpang di setiap langkah berarti enam notifikasi untuk satu
     *  perjalanan — dan lonceng yang berisi enam baris untuk satu order akan
     *  membuat notifikasi berhenti dibaca sama sekali.
     *
     *  Yang diberitahukan hanya yang MENGUBAH apa yang perlu dilakukan
     *  penumpang:
     *
     *    accepted        driver ditemukan — dia bisa berhenti menunggu jawaban
     *    driver_arrived  driver sudah tiba — dia harus keluar sekarang
     *    completed       perjalanan selesai — dia bisa menilai
     *    cancelled       dibatalkan — dia perlu memesan lagi
     *    no_driver       tidak ada driver — dia perlu mencoba lagi nanti
     *
     *  Yang TIDAK: `searching` (dia baru saja menekan pesan), `driver_arriving`
     *  (tidak ada yang perlu dia lakukan), `in_progress` (dia ada di dalam
     *  kendaraan).
     * ========================================================================
     *
     * ========================================================================
     *  DRIVER DIBERI TAHU HANYA UNTUK PEMBATALAN OLEH PENUMPANG
     * ========================================================================
     *  Driver tahu status ordernya dari layar order berjalan yang dia buka
     *  sepanjang perjalanan — notifikasi untuk transisi yang DIA SENDIRI picu
     *  tidak menambah apa pun.
     *
     *  Kecuali satu: order yang dibatalkan PENUMPANG saat driver sudah di
     *  jalan. Itu satu-satunya perubahan yang datang dari luar dan mengubah apa
     *  yang harus dia lakukan sekarang.
     * ========================================================================
     */
    private function beriTahu(Order $order, OrderTransition $transition): void
    {
        $uuid = (string) $order->uuid;
        $nomor = (string) $order->order_number;

        [$jenis, $judul, $isi] = match ($order->status) {
            OrderStatus::Accepted => [
                Notification::ORDER_ACCEPTED,
                'Driver ditemukan',
                "Driver sudah menerima pesanan {$nomor} dan akan segera berangkat.",
            ],
            OrderStatus::DriverArrived => [
                Notification::ORDER_DRIVER_ARRIVED,
                'Driver sudah tiba',
                'Driver menunggu di titik penjemputan. Sebutkan kode jemput kepadanya.',
            ],
            OrderStatus::Completed => [
                Notification::ORDER_COMPLETED,
                'Perjalanan selesai',
                "Pesanan {$nomor} sudah selesai. Beri penilaian untuk driver Anda.",
            ],
            OrderStatus::Cancelled => [
                Notification::ORDER_CANCELLED,
                'Pesanan dibatalkan',
                "Pesanan {$nomor} dibatalkan.",
            ],
            OrderStatus::NoDriver => [
                Notification::ORDER_NO_DRIVER,
                'Tidak ada driver tersedia',
                'Belum ada driver yang bisa mengambil pesanan Anda. Coba lagi beberapa saat lagi.',
            ],
            default => [null, null, null],
        };

        if ($jenis !== null) {
            $this->notifikasi->forOrder(
                recipientType: 'user',
                recipientId: (int) $order->user_id,
                type: $jenis,
                title: (string) $judul,
                body: (string) $isi,
                orderUuid: $uuid,
            );
        }

        // Driver diberi tahu HANYA saat penumpang membatalkan order yang sudah
        // dia terima. Lihat penjelasan di docblock.
        if ($order->status === OrderStatus::Cancelled
            && $order->driver_id !== null
            && $transition->actorType !== 'driver') {
            $this->notifikasi->forOrder(
                recipientType: 'driver',
                recipientId: (int) $order->driver_id,
                type: Notification::DRIVER_ORDER_CANCELLED,
                title: 'Order dibatalkan',
                body: "Order {$nomor} dibatalkan. Anda bisa menerima tawaran berikutnya.",
                orderUuid: $uuid,
            );
        }
    }

    /**
     * Kirim perubahan status ke semua pihak yang perlu tahu.
     *
     * Satu panggilan broadcast, bukan satu per channel. Perubahan status perlu
     * sampai ke penumpang, driver, dan dashboard ops secara bersamaan.
     */
    private function publish(Order $order, OrderStatus $from, OrderTransition $transition): void
    {
        $channels = [
            (string) RealtimeChannel::order($order->uuid),
            (string) RealtimeChannel::user($order->user_id),
            (string) RealtimeChannel::adminLive(),
        ];

        if ($order->driver_id !== null) {
            $channels[] = (string) RealtimeChannel::driver($order->driver_id);
        }

        $sent = $this->realtime->broadcast($channels, [
            'event' => 'order.status_changed',
            'order_uuid' => $order->uuid,
            'order_number' => $order->order_number,
            'from' => $from->value,
            'to' => $order->status->value,
            'label' => $order->status->label(),
            'message' => $order->status->customerMessage(),
            'actor_type' => $transition->actorType,
            'at' => now()->toIso8601String(),
        ]);

        if (! $sent) {
            // Dicatat, tidak dilempar. Statusnya sudah ter-commit; melempar di
            // sini akan membuat pemanggil berpikir transisinya gagal dan
            // mengulangnya, yang justru akan ditolak state machine.
            Log::warning('Event perubahan status order gagal dikirim', [
                'order_uuid' => $order->uuid,
                'from' => $from->value,
                'to' => $order->status->value,
            ]);
        }
    }
}
