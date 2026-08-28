<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Actions;

use App\Domain\Ordering\Models\Order;
use App\Domain\Wallet\DTOs\LedgerEntry;
use App\Domain\Wallet\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Membagi uang order yang selesai.
 *
 * ============================================================================
 *  DUA JALUR YANG BERBEDA SIFATNYA
 * ============================================================================
 *
 *  BAYAR WALLET — uang platform ada di platform
 *
 *    Dana sudah ditahan saat order dibuat. Settlement melepasnya lalu
 *    membayarkannya:
 *
 *      RELEASE  dompet user     +25.000   (held_balance turun)
 *      DEBIT    dompet user     -25.000   (ride_payment, pembayaran final)
 *      CREDIT   dompet driver   +20.000   (ride_earning)
 *      CREDIT   platform revenue +5.000   (commission)
 *
 *    Kenapa release dulu, bukan langsung debit dari held_balance: kolom
 *    balance_before dan balance_after di ledger melacak `balance`, dan CHECK
 *    constraint menuntut aritmetikanya konsisten dengan arah transaksi.
 *    Mengurangi held_balance tanpa menyentuh balance akan melanggar constraint
 *    itu. Pola release-lalu-bayar juga yang dipakai pembukuan sungguhan:
 *    reservasi dibalik lebih dulu, baru perpindahan sebenarnya dicatat.
 *
 *  BAYAR TUNAI — uang platform ada di tangan driver
 *
 *      DEBIT    dompet driver    -5.000   (commission, potong deposit)
 *      CREDIT   platform revenue +5.000
 *
 *    Driver menerima seluruh ongkos tunai dari penumpang, jadi yang perlu
 *    dipindahkan hanya komisi platform. Konsekuensinya driver WAJIB punya saldo
 *    deposit minimum, dan itu diperiksa di filter matching.
 * ============================================================================
 *
 * Idempotency ditegakkan database lewat partial unique index
 * `wallet_transactions_no_duplicate_settlement`. Job settlement yang dijalankan
 * ulang akan gagal pada INSERT kedua, bukan membayar driver dua kali.
 */
class SettleOrder
{
    public function __construct(
        private readonly PostLedgerEntries $postEntries,
    ) {}

    /**
     * @return string group_uuid peristiwanya
     */
    public function handle(Order $order): string
    {
        return DB::transaction(function () use ($order): string {
            $groupUuid = (string) Str::uuid7();

            $entries = $order->isCash()
                ? $this->cashEntries($order)
                : $this->walletEntries($order);

            $this->postEntries->handle($entries, $groupUuid);

            $order->payment_status = 'paid';
            $order->save();

            return $groupUuid;
        });
    }

    // -------------------------------------------------------------------------

    /**
     * @return array<int, LedgerEntry>
     */
    private function walletEntries(Order $order): array
    {
        $userWallet = Wallet::forOwner('user', (int) $order->user_id);
        $driverWallet = Wallet::forOwner('driver', (int) $order->driver_id);
        $platformRevenue = Wallet::platform(Wallet::PLATFORM_REVENUE);

        $total = $order->totalFare();
        $driverEarning = $order->driverEarning();
        $commission = $order->commission();

        $entries = [
            // Dana yang ditahan dilepas lebih dulu.
            LedgerEntry::credit(
                walletId: (int) $userWallet->getKey(),
                type: 'release',
                amount: $total,
                referenceType: 'order',
                referenceId: (int) $order->getKey(),
                description: "Dana dilepas untuk order {$order->order_number}",
            ),

            // Lalu dibayarkan.
            LedgerEntry::debit(
                walletId: (int) $userWallet->getKey(),
                type: 'ride_payment',
                amount: $total,
                referenceType: 'order',
                referenceId: (int) $order->getKey(),
                description: "Pembayaran order {$order->order_number}",
            ),

            LedgerEntry::credit(
                walletId: (int) $driverWallet->getKey(),
                type: 'ride_earning',
                amount: $driverEarning,
                referenceType: 'order',
                referenceId: (int) $order->getKey(),
                description: "Pendapatan order {$order->order_number}",
            ),
        ];

        // Komisi nol tidak menghasilkan baris. Baris bernilai nol tidak
        // menjelaskan apa pun dan hanya membuat mutasi driver penuh dengan
        // "Komisi platform Rp 0".
        if ($commission->isPositive()) {
            $entries[] = LedgerEntry::credit(
                walletId: (int) $platformRevenue->getKey(),
                type: 'commission',
                amount: $commission,
                referenceType: 'order',
                referenceId: (int) $order->getKey(),
                description: "Komisi order {$order->order_number}",
            );
        }

        // Selisih antara yang dibayar penumpang dan yang dibagikan adalah biaya
        // aplikasi, yang juga milik platform.
        $platformFee = $total->minus($driverEarning)->minus($commission);

        if ($platformFee->isPositive()) {
            $entries[] = LedgerEntry::credit(
                walletId: (int) $platformRevenue->getKey(),
                type: 'settlement',
                amount: $platformFee,
                referenceType: 'order',
                referenceId: (int) $order->getKey(),
                description: "Biaya layanan order {$order->order_number}",
            );
        }

        return $entries;
    }

    /**
     * @return array<int, LedgerEntry>
     */
    private function cashEntries(Order $order): array
    {
        $commission = $order->commission();

        // Selisih ongkos yang bukan pendapatan driver dan bukan komisi adalah
        // biaya aplikasi. Pada order tunai, itu juga ada di tangan driver dan
        // harus dipindahkan ke platform.
        $platformShare = $order->totalFare()
            ->minus($order->driverEarning());

        if (! $platformShare->isPositive()) {
            // Tidak ada yang perlu dipindahkan. Bisa terjadi kalau komisi nol
            // dan tidak ada biaya aplikasi, misalnya pada order promo penuh.
            return [];
        }

        $driverWallet = Wallet::forOwner('driver', (int) $order->driver_id);
        $platformRevenue = Wallet::platform(Wallet::PLATFORM_REVENUE);

        return [
            LedgerEntry::debit(
                walletId: (int) $driverWallet->getKey(),
                type: 'commission',
                amount: $platformShare,
                referenceType: 'order',
                referenceId: (int) $order->getKey(),
                description: "Komisi order tunai {$order->order_number}",
                metadata: [
                    'commission' => $commission->amount,
                    'platform_fee' => $platformShare->minus($commission)->amount,
                ],
            ),

            LedgerEntry::credit(
                walletId: (int) $platformRevenue->getKey(),
                type: 'commission',
                amount: $platformShare,
                referenceType: 'order',
                referenceId: (int) $order->getKey(),
                description: "Komisi order tunai {$order->order_number}",
            ),
        ];
    }
}
