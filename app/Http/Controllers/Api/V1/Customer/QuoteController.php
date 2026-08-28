<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Customer;

use App\Domain\Catalog\Models\ServiceType;
use App\Domain\Pricing\Actions\CreateQuote;
use App\Domain\Pricing\Contracts\QuoteStore;
use App\Domain\Shared\ValueObjects\Coordinate;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Customer\CreateQuoteRequest;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Estimasi harga.
 *
 * ============================================================================
 *  INI SATU-SATUNYA TEMPAT HARGA DIHITUNG
 * ============================================================================
 *  Aplikasi memanggil endpoint ini, menampilkan pilihan layanan beserta
 *  harganya, lalu mengirim `quote_id` saat penumpang memilih. Backend membaca
 *  harganya dari Redis pada saat order dibuat.
 *
 *  Quote berumur pendek (5 menit) dan itu bukan pembatasan yang bisa
 *  dilonggarkan: tarif dan surge berubah, dan order yang dibuat dari harga
 *  sepuluh menit lalu adalah janji yang sudah tidak berlaku. Aplikasi harus
 *  meminta quote baru, bukan memakai yang lama.
 * ============================================================================
 */
class QuoteController extends Controller
{
    public function store(CreateQuoteRequest $request, CreateQuote $action): JsonResponse
    {
        $quote = $action->handle(
            userId: (int) $request->user()->getKey(),
            pickup: Coordinate::of(
                (float) $request->validated('pickup.lat'),
                (float) $request->validated('pickup.lng'),
            ),
            destination: Coordinate::of(
                (float) $request->validated('destination.lat'),
                (float) $request->validated('destination.lng'),
            ),
            stops: $request->stopCoordinates(),
            serviceCodes: $request->validated('service_codes'),
        );

        return ApiResponse::success($quote->jsonSerialize());
    }

    /**
     * Baca ulang quote yang sudah dibuat.
     *
     * Dipakai saat aplikasi kembali dari latar belakang: layar konfirmasi masih
     * terbuka, dan yang perlu diketahui adalah apakah quote-nya masih berlaku.
     * Tanpa endpoint ini, aplikasi harus meminta quote BARU — yang berarti
     * harganya bisa berubah tepat saat penumpang menekan tombol pesan.
     */
    public function show(Request $request, string $quoteId): JsonResponse
    {
        $quote = app(QuoteStore::class)->get($quoteId);

        /*
         * Quote milik orang lain diperlakukan sebagai tidak ada.
         *
         * Sama seperti order: 404, bukan 403. Membedakannya mengonfirmasi bahwa
         * quote_id yang ditebak memang ada.
         */
        if ($quote === null || $quote->userId !== (int) $request->user()->getKey()) {
            return ApiResponse::error(
                'QUOTE_EXPIRED',
                'Estimasi harga sudah kadaluarsa. Muat ulang untuk mendapat harga terbaru.',
                404,
            );
        }

        if ($quote->isExpired()) {
            return ApiResponse::error(
                'QUOTE_EXPIRED',
                'Estimasi harga sudah kadaluarsa. Muat ulang untuk mendapat harga terbaru.',
                410,
            );
        }

        return ApiResponse::success($quote->jsonSerialize());
    }

    /**
     * Daftar layanan yang aktif.
     *
     * Tanpa harga: harga bergantung jarak dan zona, jadi tidak ada angka yang
     * bisa disebutkan sebelum ada titik penjemputan. Endpoint ini yang mengisi
     * baris ikon layanan di beranda.
     */
    public function serviceTypes(): JsonResponse
    {
        $services = ServiceType::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return ApiResponse::success(
            $services->map(fn (ServiceType $s): array => [
                'code' => (string) $s->code,
                'name' => (string) $s->name,
                'description' => $s->description,
                'icon_url' => $s->icon_url,
                'vehicle_class' => (string) $s->vehicle_class,
                'requires_merchant' => (bool) $s->requires_merchant,
                'supports_multi_stop' => (bool) $s->requires_multi_stop,
                'max_stops' => (int) $s->max_stops,
            ])->all(),
        );
    }
}
