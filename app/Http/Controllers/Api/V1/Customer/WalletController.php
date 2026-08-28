<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Customer;

use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dompet pengguna.
 *
 * Top up sungguhan menuntut payment gateway yang belum dipilih; jalur itu
 * menunggu keputusan. Yang sudah ada di sini adalah pembacaan saldo dan mutasi,
 * yang dibutuhkan sejak order pertama dibayar dengan wallet.
 */
class WalletController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $wallet = Wallet::forOwner('user', (int) $request->user()->getKey());

        return ApiResponse::success([
            /*
             * TIGA angka, bukan satu.
             *
             * `balance`      yang bisa dipakai sekarang
             * `held`         tertahan untuk order yang sedang berjalan
             * `total`        jumlah keduanya
             *
             * Menampilkan hanya `balance` adalah penyebab keluhan yang paling
             * sering muncul soal dompet: penumpang melihat saldonya berkurang
             * Rp 25.000 saat memesan, menyimpulkan uangnya sudah terpotong,
             * dan menelepon CS. Menampilkan ketiganya menjelaskannya di layar.
             */
            'balance' => $wallet->balance()->jsonSerialize(),
            'held' => $wallet->heldBalance()->jsonSerialize(),
            'total' => $wallet->totalBalance()->jsonSerialize(),

            'is_frozen' => (bool) $wallet->is_frozen,

            // Alasan pembekuan ditampilkan. Dompet yang tidak bisa dipakai tanpa
            // penjelasan hanya menghasilkan telepon ke CS.
            'frozen_reason' => $wallet->is_frozen ? $wallet->frozen_reason : null,
        ]);
    }

    /**
     * Mutasi dompet.
     */
    public function transactions(Request $request): JsonResponse
    {
        $wallet = Wallet::forOwner('user', (int) $request->user()->getKey());

        $transactions = WalletTransaction::query()
            ->where('wallet_id', $wallet->getKey())

            /*
             * Baris `hold` dan `release` DISEMBUNYIKAN dari mutasi pengguna.
             *
             * Keduanya benar secara pembukuan dan wajib ada di ledger, tapi
             * tidak berarti apa pun bagi pengguna: satu order berbayar wallet
             * menghasilkan empat baris (hold, release, payment, dan pasangannya)
             * untuk SATU perjalanan.
             *
             * Yang dilihat pengguna di daftar itu: "Dana ditahan Rp 25.000",
             * "Dana dilepas Rp 25.000", "Pembayaran Rp 25.000" — tiga baris yang
             * membuatnya menyimpulkan dia dipotong dua kali. Yang benar
             * ditampilkan hanya pembayarannya.
             */
            ->whereNotIn('type', WalletTransaction::INTRA_WALLET_TYPES)

            ->latest('created_at')
            ->cursorPaginate(perPage: min(50, (int) $request->integer('per_page', 20)));

        return ApiResponse::success(
            collect($transactions->items())->map(fn (WalletTransaction $t): array => [
                'uuid' => (string) $t->uuid,
                'type' => (string) $t->type,
                'label' => $t->label(),
                'direction' => (string) $t->direction,

                'amount' => $t->amount()->jsonSerialize(),

                // Tanda ditampilkan sesuai arah, supaya aplikasi tidak perlu
                // menyimpulkannya dari `direction` dan salah di satu tempat.
                'signed_amount' => $t->direction === 'credit'
                    ? (int) $t->amount
                    : -(int) $t->amount,

                'balance_after' => (int) $t->balance_after,
                'description' => $t->description,
                'created_at' => $t->created_at?->toIso8601String(),
            ])->all(),
            meta: [
                'per_page' => $transactions->perPage(),
                'next_cursor' => $transactions->nextCursor()?->encode(),
                'has_more' => $transactions->hasMorePages(),
            ],
        );
    }
}
