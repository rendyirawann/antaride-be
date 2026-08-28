<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Actions;

use App\Domain\Wallet\DTOs\LedgerEntry;
use App\Domain\Wallet\Exceptions\InsufficientBalanceException;
use App\Domain\Wallet\Exceptions\UnbalancedLedgerException;
use App\Domain\Wallet\Exceptions\WalletFrozenException;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Menulis satu peristiwa double-entry ke buku besar.
 *
 * SATU-SATUNYA jalan mengubah saldo. Tidak ada method `addBalance()` di model
 * Wallet, dan itu disengaja: saldo yang berubah tanpa baris transaksi pendamping
 * membuat selisih yang tidak bisa direkonstruksi.
 *
 * ============================================================================
 *  URUTAN LOCK MENENTUKAN ADA TIDAKNYA DEADLOCK
 * ============================================================================
 *  Dompet dikunci berurutan berdasarkan ID MENAIK, selalu.
 *
 *  Ini bukan kerapian. Settlement order melibatkan tiga dompet: penumpang,
 *  driver, dan platform. Dua settlement yang berjalan bersamaan dengan dompet
 *  yang bertumpang tindih akan saling menunggu kalau urutan lock-nya berbeda:
 *  yang satu memegang dompet 5 dan menunggu dompet 12, yang lain memegang 12
 *  dan menunggu 5. PostgreSQL akan mendeteksinya dan membunuh salah satunya
 *  dengan "deadlock detected".
 *
 *  Yang terlihat di lapangan: settlement yang gagal acak pada jam sibuk, dan
 *  makin sering saat volume naik. Dengan urutan lock yang konsisten, keadaan itu
 *  tidak bisa terjadi.
 * ============================================================================
 */
class PostLedgerEntries
{
    /**
     * @param  array<int, LedgerEntry>  $entries
     * @return array{group_uuid: string, transactions: array<int, WalletTransaction>}
     */
    public function handle(array $entries, ?string $groupUuid = null): array
    {
        if ($entries === []) {
            throw UnbalancedLedgerException::withNet(0, []);
        }

        $this->assertBalanced($entries);

        $groupUuid ??= (string) Str::uuid7();

        return DB::transaction(function () use ($entries, $groupUuid): array {
            $wallets = $this->lockWalletsInOrder($entries);
            $transactions = [];

            foreach ($entries as $entry) {
                $wallet = $wallets[$entry->walletId];

                $this->assertCanApply($wallet, $entry);

                $before = (int) $wallet->balance;
                $after = $entry->isCredit()
                    ? $before + $entry->amount->amount
                    : $before - $entry->amount->amount;

                $transactions[] = WalletTransaction::create([
                    'wallet_id' => $wallet->getKey(),
                    'type' => $entry->type,
                    'direction' => $entry->direction,
                    'amount' => $entry->amount->amount,
                    'balance_before' => $before,
                    'balance_after' => $after,
                    'reference_type' => $entry->referenceType,
                    'reference_id' => $entry->referenceId,
                    'group_uuid' => $groupUuid,
                    'description' => $entry->description,
                    'metadata' => $entry->metadata === [] ? null : $entry->metadata,
                    'created_by_admin_id' => $entry->createdByAdminId,
                    'approval_request_id' => $entry->approvalRequestId,
                ]);

                // Cache saldo diperbarui di transaksi yang SAMA dengan barisnya.
                // Kalau dipisah, ada jendela di mana keduanya tidak sepakat, dan
                // pembacaan saldo di jendela itu akan salah.
                $wallet->balance = $after;

                // hold dan release memindahkan dana ANTARA balance dan
                // held_balance dalam dompet yang sama.
                //
                // hold    : balance turun, held_balance naik
                // release : balance naik, held_balance turun
                //
                // Keduanya diurus di sini, bukan di action pemanggil, supaya
                // tidak ada jalur yang menulis satu kolom tanpa yang lain.
                // Held_balance yang tidak sinkron berarti dana tertahan yang
                // tidak pernah bisa dilepas, dan driver melihat saldo yang tidak
                // bisa ditarik tanpa penjelasan.
                if ($entry->type === 'hold') {
                    $wallet->held_balance = (int) $wallet->held_balance + $entry->amount->amount;
                } elseif ($entry->type === 'release') {
                    $held = (int) $wallet->held_balance;

                    if ($held < $entry->amount->amount) {
                        throw new \RuntimeException(sprintf(
                            'Dompet %d hanya menahan %d, tidak bisa melepas %d. '
                            .'Ini berarti ada hold yang hilang atau release ganda.',
                            $wallet->getKey(),
                            $held,
                            $entry->amount->amount,
                        ));
                    }

                    $wallet->held_balance = $held - $entry->amount->amount;
                }

                $wallet->version = (int) $wallet->version + 1;
                $wallet->save();
            }

            return ['group_uuid' => $groupUuid, 'transactions' => $transactions];
        });
    }

    // -------------------------------------------------------------------------

    /**
     * Kunci semua dompet yang terlibat, berurutan ID menaik.
     *
     * Satu query dengan whereIn dan lockForUpdate, bukan satu per dompet.
     * Selain lebih sedikit round trip, `orderBy('id')` pada query yang sama
     * itulah yang menjamin urutan pengambilan lock-nya konsisten.
     *
     * @param  array<int, LedgerEntry>  $entries
     * @return array<int, Wallet> diindeks wallet_id
     */
    private function lockWalletsInOrder(array $entries): array
    {
        $walletIds = array_values(array_unique(array_map(
            static fn (LedgerEntry $e) => $e->walletId,
            $entries,
        )));

        sort($walletIds);

        /** @var array<int, Wallet> $wallets */
        $wallets = Wallet::query()
            ->whereIn('id', $walletIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id')
            ->all();

        $missing = array_diff($walletIds, array_keys($wallets));

        if ($missing !== []) {
            throw new \RuntimeException(
                'Dompet tidak ditemukan: '.implode(', ', $missing)
            );
        }

        return $wallets;
    }

    /**
     * Jumlah seluruh entry harus nol.
     *
     * Entry hold dan release dikecualikan, karena keduanya reklasifikasi dalam
     * satu dompet dan tidak punya lawan transaksi. Daftar pengecualiannya sama
     * dengan yang ada di trigger database.
     *
     * @param  array<int, LedgerEntry>  $entries
     */
    private function assertBalanced(array $entries): void
    {
        $transferEntries = array_values(array_filter(
            $entries,
            static fn (LedgerEntry $e) => ! $e->isIntraWallet(),
        ));

        if ($transferEntries === []) {
            return;
        }

        $net = array_sum(array_map(
            static fn (LedgerEntry $e) => $e->signedAmount(),
            $transferEntries,
        ));

        if ($net !== 0) {
            throw UnbalancedLedgerException::withNet($net, array_map(
                static fn (LedgerEntry $e) => [
                    'wallet_id' => $e->walletId,
                    'type' => $e->type,
                    'direction' => $e->direction,
                    'amount' => $e->amount->amount,
                ],
                $transferEntries,
            ));
        }
    }

    /**
     * Apakah entry ini boleh diterapkan pada dompetnya.
     */
    private function assertCanApply(Wallet $wallet, LedgerEntry $entry): void
    {
        if ($entry->isCredit()) {
            // Dompet yang dibekukan tetap boleh MENERIMA. Pendapatan driver
            // yang sedang diselidiki tidak boleh hilang; yang dibekukan adalah
            // kemampuannya mengeluarkan.
            return;
        }

        if (! $wallet->canDebit()) {
            throw WalletFrozenException::forWallet(
                (int) $wallet->getKey(),
                $wallet->frozen_reason,
            );
        }

        /*
         * =====================================================================
         *  YANG DIJAGA ADALAH JENIS TRANSAKSINYA, BUKAN PEMILIK DOMPETNYA
         * =====================================================================
         *  Aturan sebenarnya bukan "saldo tidak boleh negatif", tapi:
         *
         *      TIDAK ADA yang boleh MENAHAN atau MENARIK uang yang tidak dia
         *      punya.
         *
         *  Perbedaannya menentukan. `hold` dan `withdrawal` adalah dua jenis
         *  yang memindahkan uang KELUAR dari kendali pemiliknya atas
         *  kemauannya sendiri, dan justru di situlah race condition yang
         *  paling mahal berada: dua order yang berhasil menahan dana dari saldo
         *  yang hanya cukup untuk satu, atau dua penarikan dari saldo yang sama.
         *
         *  Jenis lain adalah pembukuan atas kejadian yang SUDAH terjadi:
         *
         *    commission        driver sudah menerima uang tunai penumpang, dan
         *                      bagian platform dipotong dari saldonya. Kalau
         *                      saldonya kurang, itu utang yang nyata — dan
         *                      menolaknya berarti order yang sudah selesai
         *                      tidak bisa ditutup.
         *    penalty           denda atas pelanggaran yang sudah terjadi.
         *    adjustment        koreksi manual yang sudah disetujui.
         *    topup             pasangan debit di akun kontra platform.
         *
         *  Menolak semua itu karena saldo kurang berarti menolak MENCATAT
         *  kenyataan. Yang hilang bukan uangnya — uangnya sudah berpindah di
         *  dunia nyata — yang hilang adalah catatannya.
         *
         *  Dampak saldo minus memperbaiki dirinya sendiri: driver bersaldo di
         *  bawah ambang otomatis tidak lolos filter deposit di matching, jadi
         *  dia tidak bisa menerima order tunai lagi sampai melunasi.
         * =====================================================================
         */
        if (! in_array($entry->type, ['hold', 'withdrawal'], true)) {
            return;
        }

        // Menahan atau menarik uang yang tidak ada tidak boleh. Database juga
        // menolaknya lewat CHECK constraint `wallets_non_negative_check` untuk
        // dompet pengguna, tapi pesan dari sana tidak menyebutkan berapa
        // saldonya dan berapa kekurangannya.
        if ((int) $wallet->balance < $entry->amount->amount) {
            throw InsufficientBalanceException::forWallet(
                (int) $wallet->getKey(),
                $wallet->balance(),
                $entry->amount,
            );
        }
    }
}
