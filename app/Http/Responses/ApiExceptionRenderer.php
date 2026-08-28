<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Domain\Shared\Exceptions\DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Menerjemahkan exception jadi response API dengan bentuk yang selalu sama.
 *
 * Hanya berlaku untuk request yang memang mengharapkan JSON. Panel admin tetap
 * memakai halaman error Laravel biasa, karena yang membacanya manusia yang
 * sedang bekerja, bukan aplikasi.
 */
final class ApiExceptionRenderer
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! self::wantsJson($request)) {
                return null;
            }

            return self::render($e);
        });
    }

    private static function wantsJson(Request $request): bool
    {
        return $request->is('api/*')
            || $request->is('webhooks/*')
            || $request->expectsJson();
    }

    private static function render(\Throwable $e): ?JsonResponse
    {
        // --- Kegagalan aturan bisnis: kodenya sudah dibawa exception-nya ---
        if ($e instanceof DomainException) {
            return ApiResponse::error(
                $e->errorCode(),
                $e->getMessage(),
                $e->httpStatus(),
                $e->details(),
            );
        }

        // --- Validasi ---
        if ($e instanceof ValidationException) {
            return ApiResponse::error(
                'VALIDATION_FAILED',
                'Data yang dikirim tidak valid.',
                422,
                $e->errors(),
            );
        }

        // --- Autentikasi & otorisasi ---
        if ($e instanceof AuthenticationException) {
            return ApiResponse::error(
                'UNAUTHENTICATED',
                'Sesi tidak valid atau sudah berakhir. Silakan masuk kembali.',
                401,
            );
        }

        if ($e instanceof AuthorizationException) {
            return ApiResponse::error(
                'FORBIDDEN',
                'Anda tidak memiliki izin untuk tindakan ini.',
                403,
            );
        }

        // --- Tidak ditemukan ---
        if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
            return ApiResponse::error(
                'NOT_FOUND',
                'Data yang diminta tidak ditemukan.',
                404,
            );
        }

        // --- Rate limit ---
        if ($e instanceof TooManyRequestsHttpException) {
            return ApiResponse::error(
                'RATE_LIMITED',
                'Terlalu banyak permintaan. Coba lagi beberapa saat.',
                429,
                array_filter(['retry_after' => $e->getHeaders()['Retry-After'] ?? null]),
            );
        }

        // --- HttpException lain, kodenya diturunkan dari status ---
        if ($e instanceof HttpExceptionInterface) {
            return ApiResponse::error(
                self::codeForStatus($e->getStatusCode()),
                $e->getMessage() !== '' ? $e->getMessage() : 'Permintaan tidak dapat diproses.',
                $e->getStatusCode(),
            );
        }

        // --- Sisanya: kesalahan tak terduga ---
        //
        // Detail teknis tidak pernah dikirim ke app di produksi. Yang dibutuhkan
        // app hanya tahu bahwa ini bukan kesalahannya dan boleh dicoba lagi.
        // Stack trace tetap masuk log dan Sentry.
        if (config('app.debug')) {
            return null;
        }

        report($e);

        return ApiResponse::error(
            'SERVER_ERROR',
            'Terjadi gangguan pada sistem. Silakan coba beberapa saat lagi.',
            500,
        );
    }

    private static function codeForStatus(int $status): string
    {
        return match ($status) {
            400 => 'BAD_REQUEST',
            401 => 'UNAUTHENTICATED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            405 => 'METHOD_NOT_ALLOWED',
            409 => 'CONFLICT',
            413 => 'PAYLOAD_TOO_LARGE',
            422 => 'UNPROCESSABLE',
            429 => 'RATE_LIMITED',
            503 => 'SERVICE_UNAVAILABLE',
            default => 'REQUEST_FAILED',
        };
    }
}
