<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Actions;

use App\Domain\Matching\Contracts\DriverLocationIndex;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Exceptions\OrderNotCancellableException;
use App\Domain\Ordering\Models\CancellationReason;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\StateMachine\OrderStateMachine;
use App\Domain\Ordering\StateMachine\OrderTransition;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Wallet\Actions\PostLedgerEntries;
use App\Domain\Wallet\Actions\ReleaseFunds;
use App\Domain\Wallet\DTOs\LedgerEntry;
use App\Domain\Wallet\Models\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * Membatalkan order.
 *
 * ============================================================================
 *  UANG YANG DITAHAN HARUS DILEPAS, DAN ITU BAGIAN YANG PALING MUDAH LUPA
 * ============================================================================
 *  Order dengan pembayaran wallet punya dana yang ditahan sejak dibuat.
 *  Pembatalan yang hanya mengubah status meninggalkan dana itu tertahan
 *  SELAMANYA: saldo penumpang berkurang, tidak ada order yang berjalan, dan
 *  tidak ada satu pun error di log.
 *
 *  Bentuk keluhannya di lapangan: "saldo saya hilang Rp 25.000 padahal order
 *  saya batal". Dan yang menerima telepon itu adalah CS yang tidak punya
 *  tombol untuk memperbaikinya, karena mengembalikan dana tertahan tanpa jejak
 *  pembukuan bukan sesuatu yang boleh dilakukan panel admin.
 * ============================================================================
 *
 * ============================================================================
 *  BIAYA PEMBATALAN: SIAPA YANG BAYAR, DAN KAPAN
 * ============================================================================
 *  Biaya hanya ditagih kalau TIGA hal terpenuhi sekaligus:
 *
 *    1. Yang membatalkan penumpang (bukan driver, sistem, atau admin)
 *    2. Alasannya bertanda `charges_fee` (bukan "driver tidak bisa dihubungi")
 *    3. Sudah melewati jendela batal gratis DAN driver sudah menerima
 *
 *  Syarat ketiga yang paling sering salah dipahami. Order yang masih mencari
 *  driver tidak pernah dikenai biaya, sebesar apa pun waktunya, karena belum
 *  ada driver yang berkendara ke mana pun. Yang diganti biaya ini adalah bensin
 *  dan waktu driver, dan kalau tidak ada driver, tidak ada yang perlu diganti.
 *
 *  SELURUH biaya masuk ke driver. Platform tidak mengambil komisi dari
 *  pembatalan — lihat penjelasan di config/antaride.php.
 * ============================================================================
 */
class CancelOrder
{
    public function __construct(
        private readonly OrderStateMachine $stateMachine,
        private readonly ReleaseFunds $releaseFunds,
        private readonly PostLedgerEntries $postEntries,
        private readonly DriverLocationIndex $locationIndex,
    ) {}

    /**
     * @param  string  $actorType  user, driver, admin, atau system
     */
    public function handle(
        Order $order,
        string $actorType,
        ?int $actorId = null,
        ?string $reasonCode = null,
        ?string $note = null,
    ): Order {
        return DB::transaction(function () use ($order, $actorType, $actorId, $reasonCode, $note): Order {
            /** @var Order|null $locked */
            $locked = Order::query()->lockForUpdate()->find($order->getKey());

            if ($locked === null) {
                throw OrderNotCancellableException::orderGone();
            }

            if (! $locked->status->isCancellable()) {
                throw OrderNotCancellableException::becauseStatus($locked->status);
            }

            $reason = $this->resolveReason($actorType, $reasonCode);
            $fee = $this->calculateFee($locked, $actorType, $reason);

            /*
             * Dana dilepas SEBELUM transisi, bukan sesudah.
             *
             * Urutannya penting karena keduanya dalam satu transaksi: kalau
             * pelepasan dana gagal — dompet dibekukan, misalnya — yang benar
             * adalah pembatalannya ikut gagal, bukan order tercatat batal
             * dengan dana yang masih tertahan. Menaruh pelepasan lebih dulu
             * membuat kegagalan itu terjadi sebelum ada apa pun yang berubah.
             */
            $this->releaseHeldFunds($locked, $fee);

            $cancelled = $this->stateMachine->apply(
                $locked,
                $this->transitionFor($actorType, $actorId, $reason, $note, $fee),
            );

            $this->releaseDriver($cancelled);
            $this->voidPendingOffers($cancelled);

            return $cancelled;
        });
    }

    // -------------------------------------------------------------------------

    private function resolveReason(string $actorType, ?string $reasonCode): ?CancellationReason
    {
        if ($reasonCode === null) {
            return null;
        }

        /*
         * Alasan harus cocok dengan pihak yang membatalkan.
         *
         * Tanpa pemeriksaan ini, aplikasi driver bisa mengirim kode alasan milik
         * penumpang — termasuk yang bertanda `charges_fee` — dan menagih biaya
         * pembatalan kepada penumpang atas pembatalan yang dilakukan driver
         * sendiri.
         */
        return CancellationReason::query()
            ->where('code', $reasonCode)
            ->where('actor_type', $actorType)
            ->where('is_active', true)
            ->first();
    }

    private function calculateFee(
        Order $order,
        string $actorType,
        ?CancellationReason $reason,
    ): Money {
        if ($actorType !== 'user') {
            return Money::zero();
        }

        if ($reason === null || ! $reason->charges_fee) {
            return Money::zero();
        }

        // Belum ada driver berarti belum ada yang perlu diganti.
        if ($order->driver_id === null || $order->matched_at === null) {
            return Money::zero();
        }

        $window = (int) config('antaride.order.free_cancel_window_seconds', 180);

        if ($order->matched_at->diffInSeconds(now()) <= $window) {
            return Money::zero();
        }

        $fee = (int) config('antaride.order.cancellation_fee', 5_000);

        /*
         * Biaya tidak boleh melebihi ongkos ordernya sendiri.
         *
         * Order jarak sangat pendek bisa berongkos di bawah biaya pembatalan,
         * dan menagih Rp 5.000 untuk order Rp 4.000 adalah angka yang tidak bisa
         * dijelaskan ke siapa pun.
         */
        return Money::of(min($fee, (int) $order->total_fare));
    }

    /**
     * Lepas dana yang ditahan, lalu tagih biaya pembatalan kalau ada.
     */
    private function releaseHeldFunds(Order $order, Money $fee): void
    {
        if ($order->payment_status !== 'held') {
            // Tunai, atau sudah dilepas oleh percobaan pembatalan sebelumnya.
            // Idempoten: pembatalan yang dijalankan dua kali tidak melepas dana
            // dua kali.
            return;
        }

        $userWallet = Wallet::forOwner('user', (int) $order->user_id);

        $this->releaseFunds->handle(
            wallet: $userWallet,
            amount: $order->totalFare(),
            referenceType: 'order',
            referenceId: (int) $order->getKey(),
            description: "Dana dilepas, order {$order->order_number} dibatalkan",
        );

        $order->payment_status = $fee->isPositive() ? 'paid' : 'unpaid';
        $order->save();

        if ($fee->isPositive()) {
            $this->chargeFee($order, $fee);
        }
    }

    /**
     * Tagih biaya pembatalan: dari penumpang, seluruhnya ke driver.
     */
    private function chargeFee(Order $order, Money $fee): void
    {
        $userWallet = Wallet::forOwner('user', (int) $order->user_id);
        $driverWallet = Wallet::forOwner('driver', (int) $order->driver_id);

        $this->postEntries->handle([
            LedgerEntry::debit(
                walletId: (int) $userWallet->getKey(),
                type: 'cancellation_fee',
                amount: $fee,
                referenceType: 'order',
                referenceId: (int) $order->getKey(),
                description: "Biaya pembatalan order {$order->order_number}",
            ),
            LedgerEntry::credit(
                walletId: (int) $driverWallet->getKey(),
                type: 'cancellation_fee',
                amount: $fee,
                referenceType: 'order',
                referenceId: (int) $order->getKey(),
                description: "Ganti biaya pembatalan order {$order->order_number}",
            ),
        ]);
    }

    private function transitionFor(
        string $actorType,
        ?int $actorId,
        ?CancellationReason $reason,
        ?string $note,
        Money $fee,
    ): OrderTransition {
        $metadata = array_filter([
            'cancellation_reason_id' => $reason?->getKey(),
            'cancellation_fee' => $fee->isPositive() ? $fee->amount : null,
        ], static fn ($v): bool => $v !== null);

        return match ($actorType) {
            'user' => OrderTransition::byUser(
                to: OrderStatus::Cancelled,
                userId: (int) $actorId,
                note: $note,
                metadata: $metadata,
            ),
            'driver' => OrderTransition::byDriver(
                to: OrderStatus::Cancelled,
                driverId: (int) $actorId,
                note: $note,
                metadata: $metadata,
            ),
            'admin' => OrderTransition::byAdmin(
                to: OrderStatus::Cancelled,
                adminId: (int) $actorId,
                note: $note ?? 'Dibatalkan admin.',
                metadata: $metadata,
            ),
            default => OrderTransition::bySystem(
                to: OrderStatus::Cancelled,
                note: $note,
                metadata: $metadata,
            ),
        };
    }

    /**
     * Kembalikan driver ke antrean.
     *
     * Kalau tidak, driver yang ordernya dibatalkan penumpang akan tetap
     * dianggap sibuk sampai dia mematikan lalu menyalakan ulang statusnya
     * sendiri — dan sebagian besar driver tidak akan tahu harus melakukan itu.
     * Yang terlihat di data: driver aktif yang tidak pernah dapat order lagi
     * setelah satu pembatalan.
     */
    private function releaseDriver(Order $order): void
    {
        if ($order->driver_id === null) {
            return;
        }

        /*
         * Ketersediaannya dipulihkan HANYA kalau bukan driver itu sendiri yang
         * membatalkan.
         *
         * Driver yang membatalkan karena kendaraannya bermasalah tidak boleh
         * langsung ditawari order lagi; kalau dipulihkan, dia akan menerima
         * penawaran berikutnya dalam hitungan detik dan membatalkannya juga.
         * Dia harus menyalakan statusnya sendiri setelah masalahnya selesai.
         */
        if ($order->cancelled_by === 'driver') {
            $this->locationIndex->markUnavailableEverywhere((int) $order->driver_id);

            return;
        }

        $serviceCode = (string) $order->serviceType->code;

        if ($order->zone_id !== null) {
            $this->locationIndex->markAvailable(
                $serviceCode,
                (int) $order->zone_id,
                (int) $order->driver_id,
            );
        }
    }

    /**
     * Penawaran yang masih menggantung ditandai 'cancelled'.
     *
     * Bukan 'timeout': driver yang belum menjawab tidak melakukan kesalahan, dan
     * timeout menurunkan acceptance_rate-nya. Alasan yang sama seperti 'lost'
     * pada AcceptOrder.
     */
    private function voidPendingOffers(Order $order): void
    {
        DB::table('order_offers')
            ->where('order_id', $order->getKey())
            ->where('response', 'pending')
            ->update([
                'response' => 'cancelled',
                'responded_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
