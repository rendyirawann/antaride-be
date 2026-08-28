<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sesi impersonasi bersifat read-only (blueprint admin bagian 9).
 *
 * CS sering perlu melihat apa yang dilihat pengguna, dan itu berguna. Tapi
 * impersonasi yang bisa menulis berarti seorang agen CS bisa membuat order,
 * menarik saldo, atau mengubah rekening bank atas nama pengguna, dan jejaknya
 * akan terlihat seolah pengguna itu sendiri yang melakukannya.
 *
 * Middleware ini dipasang pada semua route API yang mengubah data. Penegakan
 * di sini, bukan di masing-masing controller, supaya endpoint ke-30 yang
 * ditambahkan enam bulan lagi ikut terlindungi tanpa perlu diingat.
 */
class EnsureNotImpersonating
{
    public const SESSION_KEY = 'impersonation';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isImpersonating($request)) {
            return $next($request);
        }

        if ($request->isMethodSafe()) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'IMPERSONATION_READ_ONLY',
                'message' => 'Sesi peninjauan akun bersifat hanya-baca. Tindakan ini tidak dapat dilakukan.',
                'details' => [],
            ],
        ], 403);
    }

    private function isImpersonating(Request $request): bool
    {
        /*
         * Token Sanctum yang diterbitkan untuk impersonasi ditandai dengan
         * ability khusus, jadi pemeriksaannya tidak bergantung pada session
         * yang tidak ada di jalur API.
         *
         * `method_exists` diperiksa lebih dulu karena middleware ini juga
         * dipakai di route ADMIN, dan model Admin tidak memakai HasApiTokens —
         * guard `admin` berbasis session, bukan token. Tanpa pemeriksaan ini,
         * setiap tindakan admin yang dilindungi middleware ini gagal dengan
         * BadMethodCallException, bukan dengan penolakan yang bisa dibaca.
         */
        $pengguna = $request->user();

        $token = $pengguna !== null && method_exists($pengguna, 'currentAccessToken')
            ? $pengguna->currentAccessToken()
            : null;

        if ($token !== null && method_exists($token, 'can') && $token->can('impersonate')) {
            return true;
        }

        return $request->hasSession()
            && $request->session()->has(self::SESSION_KEY);
    }
}
