<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Customer\UpdateProfileRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Profil pengguna.
 */
class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success(
            (new UserResource($request->user()))->resolve(),
        );
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        /*
         * Hanya field yang memang dikirim yang diperbarui.
         *
         * `validated()` sudah membuang yang tidak lolos aturan, tapi belum
         * membedakan "dikirim kosong" dari "tidak dikirim". Bedanya nyata:
         * aplikasi yang hanya memperbarui nama tidak boleh menghapus email
         * pengguna hanya karena field itu tidak ada di payload.
         */
        $user->fill($request->safe()->only(['name', 'email', 'gender', 'birth_date']));
        $user->save();

        return ApiResponse::success((new UserResource($user->fresh()))->resolve());
    }

    /**
     * Ajukan penghapusan akun.
     *
     * ========================================================================
     *  DITUNDA, BUKAN LANGSUNG DIHAPUS
     * ========================================================================
     *  Yang dilakukan hanya menandai `deletion_requested_at`. Penghapusan
     *  sebenarnya dijalankan job setelah masa tunggu.
     *
     *  Tiga alasan, dan ketiganya nyata:
     *
     *    1. Penghapusan yang tidak sengaja bisa dibatalkan. Masuk kembali dalam
     *       masa tunggu membatalkan pengajuannya.
     *    2. Order yang sedang berjalan harus selesai lebih dulu — termasuk
     *       pembayarannya. Menghapus akun di tengah order berarti driver
     *       mengantar penumpang yang datanya sudah hilang.
     *    3. Ledger TIDAK boleh ikut terhapus. Riwayat keuangan wajib disimpan
     *       untuk kewajiban pelaporan, dan `wallet_transactions` bersifat
     *       append-only yang ditegakkan trigger database. Yang dihapus adalah
     *       data pribadinya, bukan jejak uangnya.
     * ========================================================================
     */
    public function requestDeletion(Request $request): JsonResponse
    {
        $user = $request->user();

        // Order berjalan menghalangi. Pengguna harus menyelesaikan atau
        // membatalkannya lebih dulu.
        $hasActiveOrder = $user->orders()->blockingForUser()->exists();

        if ($hasActiveOrder) {
            return ApiResponse::error(
                'USER_HAS_ACTIVE_ORDER',
                'Selesaikan dulu order yang sedang berjalan sebelum menghapus akun.',
                409,
            );
        }

        $graceDays = (int) config('antaride.privacy.deletion_grace_days', 30);

        $user->deletion_requested_at = now();
        $user->save();

        return ApiResponse::success([
            'deletion_requested_at' => $user->deletion_requested_at->toIso8601String(),
            'scheduled_for' => now()->addDays($graceDays)->toIso8601String(),
            'grace_days' => $graceDays,
            'message' => "Akun Anda akan dihapus dalam {$graceDays} hari. "
                .'Masuk kembali sebelum itu untuk membatalkan.',
        ]);
    }

    /**
     * Batalkan pengajuan penghapusan.
     */
    public function cancelDeletion(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->deletion_requested_at = null;
        $user->save();

        return ApiResponse::success([
            'message' => 'Pengajuan penghapusan akun dibatalkan.',
        ]);
    }
}
