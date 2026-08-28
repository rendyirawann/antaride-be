<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Customer;

use App\Domain\Ordering\Actions\CancelOrder;
use App\Domain\Ordering\Actions\CreateOrder;
use App\Domain\Ordering\Actions\SubmitRating;
use App\Domain\Ordering\DTOs\NewOrderRequest;
use App\Domain\Ordering\Models\CancellationReason;
use App\Domain\Ordering\Models\Order;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Customer\CancelOrderRequest;
use App\Http\Requests\Api\V1\Customer\CreateOrderRequest;
use App\Http\Requests\Api\V1\Customer\SubmitRatingRequest;
use App\Http\Resources\Api\V1\OrderResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Order dari sisi penumpang.
 *
 * ============================================================================
 *  SETIAP PEMBACAAN ORDER DIBATASI KE PEMILIKNYA
 * ============================================================================
 *  `ownedOrder()` di bawah adalah satu-satunya jalan controller ini memuat
 *  order, dan dia selalu memfilter `user_id`. Route model binding otomatis
 *  TIDAK dipakai, dan itu disengaja: binding otomatis memuat order berdasarkan
 *  uuid saja, dan satu endpoint yang lupa memeriksa pemilik berarti siapa pun
 *  yang punya token bisa membaca order orang lain — lengkap dengan alamat rumah
 *  dan tujuannya.
 *
 *  UUID memang tidak praktis ditebak. Tapi "tidak praktis ditebak" bukan kontrol
 *  akses: UUID order bocor lewat screenshot, lewat tautan yang dibagikan, dan
 *  lewat riwayat HTTP di perangkat yang dipakai bersama.
 * ============================================================================
 */
class OrderController extends Controller
{
    /**
     * Riwayat order.
     */
    public function index(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->where('user_id', $request->user()->getKey())
            ->with(['serviceType', 'driver.vehicles', 'driver.user', 'ratings'])
            ->latest('requested_at')

            /*
             * Cursor pagination, bukan nomor halaman.
             *
             * Riwayat order tumbuh terus, dan `OFFSET 5000` memaksa PostgreSQL
             * memindai lima ribu baris untuk dibuang. Cursor memakai index
             * (user_id, requested_at) dan biayanya tetap sama di halaman
             * pertama maupun keseratus.
             *
             * Konsekuensi yang diterima: tidak ada "halaman 7". Untuk riwayat
             * order yang dibaca dengan menggulir, itu memang bukan yang
             * dibutuhkan.
             */
            ->cursorPaginate(perPage: min(50, (int) $request->integer('per_page', 20)));

        return ApiResponse::success(
            OrderResource::collection($orders),
            meta: [
                'per_page' => $orders->perPage(),
                'next_cursor' => $orders->nextCursor()?->encode(),
                'has_more' => $orders->hasMorePages(),
            ],
        );
    }

    /**
     * Order yang sedang berjalan, kalau ada.
     *
     * Endpoint tersendiri, bukan filter di `index`. Ini yang dipanggil aplikasi
     * setiap kali dibuka, untuk memutuskan apakah langsung menampilkan layar
     * pelacakan alih-alih beranda. Membuatnya endpoint sendiri berarti
     * response-nya kecil dan bisa di-cache berbeda.
     */
    public function active(Request $request): JsonResponse
    {
        $order = Order::query()
            ->where('user_id', $request->user()->getKey())
            ->blockingForUser()
            ->with(['serviceType', 'driver.vehicles', 'driver.user', 'ratings'])
            ->latest('requested_at')
            ->first();

        return ApiResponse::success(
            $order === null ? null : (new OrderResource($order))->resolve(),
        );
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $order = $this->ownedOrder($request, $uuid);

        return ApiResponse::success((new OrderResource($order))->resolve());
    }

    /**
     * Buat order dari sebuah quote.
     */
    public function store(CreateOrderRequest $request, CreateOrder $action): JsonResponse
    {
        $order = $action->handle(
            user: $request->user(),
            request: NewOrderRequest::fromValidated(
                $request->validated(),
                idempotencyKey: $request->header('Idempotency-Key'),
            ),
        );

        $order->load(['serviceType']);

        return ApiResponse::success(
            (new OrderResource($order))->resolve(),
            status: 201,
        );
    }

    public function cancel(
        CancelOrderRequest $request,
        CancelOrder $action,
        string $uuid,
    ): JsonResponse {
        $order = $this->ownedOrder($request, $uuid);

        $cancelled = $action->handle(
            order: $order,
            actorType: 'user',
            actorId: (int) $request->user()->getKey(),
            reasonCode: $request->validated('reason_code'),
            note: $request->validated('note'),
        );

        $cancelled->load(['serviceType', 'driver.vehicles', 'driver.user']);

        return ApiResponse::success((new OrderResource($cancelled))->resolve());
    }

    /**
     * Alasan pembatalan yang boleh dipilih penumpang.
     *
     * Diambil dari database, bukan ditulis di aplikasi. Alasannya: daftar ini
     * ikut menentukan apakah pembatalan dikenai biaya (`charges_fee`), dan
     * kalau aplikasi punya daftarnya sendiri, satu perubahan kebijakan menuntut
     * rilis aplikasi baru — dan pengguna yang belum memperbarui akan mengirim
     * kode yang tidak dikenal.
     */
    public function cancellationReasons(): JsonResponse
    {
        $reasons = CancellationReason::query()
            ->where('actor_type', 'user')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['code', 'text', 'charges_fee']);

        return ApiResponse::success(
            $reasons->map(fn (CancellationReason $r): array => [
                'code' => (string) $r->code,
                'text' => (string) $r->text,

                // Diberitahukan supaya aplikasi bisa menampilkan peringatan
                // SEBELUM penumpang menekan batal, bukan menagihnya lalu
                // menjelaskan sesudahnya.
                'may_charge_fee' => (bool) $r->charges_fee,
            ])->all(),
        );
    }

    /**
     * Nilai driver setelah perjalanan selesai.
     *
     * ==========================================================================
     *  TIDAK MEMAKAI MIDDLEWARE IDEMPOTENCY, DAN ITU DISENGAJA
     * ==========================================================================
     *  Penilaian tidak memindahkan uang, dan penilaian ganda sudah dicegah
     *  `unique(order_id, rater_type)` di database.
     *
     *  Yang lebih penting: percobaan kedua HARUS mendapat jawaban yang benar —
     *  "Anda sudah menilai perjalanan ini" — bukan putaran ulang response
     *  percobaan pertama. Penumpang yang menekan kirim dua kali perlu tahu
     *  penilaiannya sudah masuk, dan middleware idempotency akan
     *  menyembunyikannya di balik response yang identik.
     * ==========================================================================
     *
     *  Order dimuat lewat `ownedOrder()` seperti endpoint lain — jadi order milik
     *  orang lain menghasilkan 404, bukan 403. Lihat penjelasannya di sana.
     */
    public function rate(
        SubmitRatingRequest $request,
        SubmitRating $action,
        string $uuid,
    ): JsonResponse {
        $order = $this->ownedOrder($request, $uuid);

        $rating = $action->handle(
            order: $order,
            userId: (int) $request->user()->getKey(),
            score: (int) $request->validated('score'),
            tags: $request->tagList(),
            comment: $request->validated('comment'),
        );

        return ApiResponse::success([
            'score' => (int) $rating->score,
            'tags' => $rating->tags ?? [],
            'comment' => $rating->comment,
            'rated_at' => $rating->created_at?->toIso8601String(),
        ], status: 201);
    }

    // -------------------------------------------------------------------------

    /**
     * Muat order milik pengguna ini, atau 404.
     *
     * 404, bukan 403. Order orang lain harus tampak seperti tidak ada: 403
     * mengonfirmasi bahwa uuid yang ditebak memang milik seseorang, dan itu
     * satu-satunya informasi yang dibutuhkan untuk tahu bahwa menebak lebih
     * lanjut ada gunanya.
     */
    private function ownedOrder(Request $request, string $uuid): Order
    {
        return Order::query()
            ->where('uuid', $uuid)
            ->where('user_id', $request->user()->getKey())
            ->with(['serviceType', 'driver.vehicles', 'driver.user', 'ratings'])
            ->firstOrFail();
    }
}
