<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Identity\Actions\RequestOtp;
use App\Domain\Identity\Actions\VerifyOtp;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\RequestOtpRequest;
use App\Http\Requests\Api\V1\Auth\VerifyOtpRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Autentikasi mobile lewat OTP SMS.
 *
 * ============================================================================
 *  LOGIN DAN REGISTRASI TIDAK DIPISAH
 * ============================================================================
 *  Dua endpoint, bukan empat: minta kode, lalu verifikasi. Nomor yang belum
 *  terdaftar mendapat akun baru di langkah kedua.
 *
 *  Alasannya bukan kesederhanaan kode, tapi keamanan: kalau ada endpoint
 *  terpisah untuk login dan registrasi, perbedaan response-nya sudah cukup untuk
 *  menguji nomor mana yang terdaftar. Dengan satu alur, response `otp/request`
 *  identik untuk keduanya.
 * ============================================================================
 *
 *  Rate limit ada di route, bukan di sini. Yang di sini per IP; jeda per NOMOR
 *  ditegakkan Action, karena berganti IP jauh lebih mudah daripada berganti
 *  nomor.
 */
class OtpController extends Controller
{
    /**
     * Minta kode OTP.
     *
     * Response-nya SELALU sama bentuknya, terdaftar maupun belum.
     */
    public function request(RequestOtpRequest $request, RequestOtp $action): JsonResponse
    {
        $challenge = $action->handle(
            rawPhone: (string) $request->validated('phone'),
            purpose: $request->purpose(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return ApiResponse::success($challenge->jsonSerialize());
    }

    /**
     * Verifikasi kode, lalu masuk atau daftar.
     */
    public function verify(VerifyOtpRequest $request, VerifyOtp $action): JsonResponse
    {
        $session = $action->handle(
            rawPhone: (string) $request->validated('phone'),
            code: (string) $request->validated('code'),
            purpose: $request->purpose(),
            deviceId: $request->validated('device_id'),
            platform: $request->validated('platform'),
            fcmToken: $request->validated('fcm_token'),
            appVersion: $request->validated('app_version'),
        );

        return ApiResponse::success([
            'token' => $session->token,
            'token_type' => 'Bearer',
            'is_new_user' => $session->isNewUser,
            'user' => (new UserResource($session->user))->resolve(),
        ], status: $session->isNewUser ? 201 : 200);
    }

    /**
     * Keluar dari perangkat ini saja.
     *
     * Token yang dipakai request ini yang dicabut, bukan seluruh token.
     * Pengguna yang keluar dari HP kedua tidak boleh ikut keluar dari HP
     * utamanya — dan itu yang terjadi kalau di sini memakai `tokens()->delete()`.
     */
    public function logout(): JsonResponse
    {
        $token = auth()->user()?->currentAccessToken();

        // Token bisa null kalau autentikasinya lewat session, bukan Sanctum
        // token — misalnya saat diuji dengan actingAs. Tidak ada yang perlu
        // dicabut, dan itu bukan kegagalan.
        if ($token !== null && method_exists($token, 'delete')) {
            $token->delete();
        }

        return ApiResponse::success(['message' => 'Anda sudah keluar.']);
    }

    /**
     * Keluar dari SEMUA perangkat.
     *
     * Dipisahkan dari logout biasa karena dampaknya berbeda jauh, dan pengguna
     * harus memilihnya secara sadar. Ini yang dipakai saat HP-nya hilang.
     */
    public function logoutAll(): JsonResponse
    {
        $user = auth()->user();

        $user?->tokens()->delete();

        return ApiResponse::success([
            'message' => 'Anda sudah keluar dari semua perangkat.',
        ]);
    }
}
