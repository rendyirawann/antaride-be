<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Satu bentuk response untuk seluruh API (blueprint bagian 8):
 *
 *   { "success": true,  "data": {...}, "meta": {...} }
 *   { "success": false, "error": { "code": "...", "message": "...", "details": {} } }
 *
 * `error.code` selalu ada dan selalu mesin-readable. App harus bisa bereaksi
 * berbeda per kasus tanpa mencocokkan string pesan, karena pesan akan berubah
 * begitu ada yang memperbaiki tata bahasanya, dan app lama yang mencocokkan
 * teks akan diam-diam berhenti bekerja.
 */
final class ApiResponse
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public static function success(
        mixed $data = null,
        array $meta = [],
        int $status = 200,
    ): JsonResponse {
        $payload = ['success' => true];

        $payload['data'] = self::resolve($data);

        // Paginator membawa meta-nya sendiri; digabung supaya client tidak
        // perlu tahu dari mana asalnya.
        $payload['meta'] = array_merge(self::paginationMeta($data), $meta);

        if ($payload['meta'] === []) {
            unset($payload['meta']);
        }

        return response()->json($payload, $status);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function error(
        string $code,
        string $message,
        int $status = 400,
        array $details = [],
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ],
        ], $status);
    }

    public static function noContent(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => null], 200);
    }

    private static function resolve(mixed $data): mixed
    {
        if ($data instanceof JsonResource || $data instanceof ResourceCollection) {
            return $data->resolve();
        }

        if ($data instanceof Paginator || $data instanceof CursorPaginator) {
            return collect($data->items())
                ->map(fn ($item) => $item instanceof JsonResource ? $item->resolve() : $item)
                ->all();
        }

        return $data;
    }

    /**
     * Panel admin memakai cursor pagination untuk tabel besar, jadi meta yang
     * dikembalikan adalah cursor, bukan nomor halaman dan total. `COUNT(*)`
     * pada tabel order berjuta baris dengan filter tanggal memakan detik, dan
     * tidak ada yang benar-benar butuh angka pastinya.
     *
     * @return array<string, mixed>
     */
    private static function paginationMeta(mixed $data): array
    {
        if ($data instanceof CursorPaginator) {
            return [
                'per_page' => $data->perPage(),
                'next_cursor' => $data->nextCursor()?->encode(),
                'prev_cursor' => $data->previousCursor()?->encode(),
                'has_more' => $data->hasMorePages(),
            ];
        }

        if ($data instanceof Paginator) {
            return [
                'current_page' => $data->currentPage(),
                'per_page' => $data->perPage(),
                'has_more' => $data->hasMorePages(),
            ];
        }

        return [];
    }
}
