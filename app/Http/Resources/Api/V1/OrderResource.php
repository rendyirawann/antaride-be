<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\Rating;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Order dari sudut pandang PENUMPANG.
 *
 * ============================================================================
 *  KODE JEMPUT HANYA DIKIRIM KE PENUMPANG
 * ============================================================================
 *  `pickup_code` adalah empat digit yang disebutkan penumpang ke driver untuk
 *  membuktikan orangnya benar. Kalau kode itu ikut terkirim ke aplikasi driver,
 *  seluruh gunanya hilang: driver bisa membacanya dari payload dan mengaku sudah
 *  menjemput tanpa pernah bertemu penumpangnya.
 *
 *  Itu bukan kecurangan teoretis. Order yang ditandai "sudah dijemput" lalu
 *  dibatalkan adalah cara paling langsung memancing biaya pembatalan dari
 *  penumpang yang tidak pernah dijemput.
 *
 *  Model `Order` menaruh `pickup_code` di `$hidden`, jadi serialisasi otomatis
 *  tidak akan pernah membocorkannya. Resource INI menampilkannya secara
 *  eksplisit karena pemiliknya yang membacanya. Resource driver
 *  (DriverOrderResource) tidak.
 * ============================================================================
 *
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => (string) $this->uuid,
            'order_number' => (string) $this->order_number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),

            'service' => $this->whenLoaded('serviceType', fn (): array => [
                'code' => (string) $this->serviceType->code,
                'name' => (string) $this->serviceType->name,
            ]),

            'payment' => [
                'method' => (string) $this->payment_method,
                'status' => (string) $this->payment_status,
            ],

            'pickup' => [
                'address' => (string) $this->pickup_address,
                'lat' => (float) $this->pickup_lat,
                'lng' => (float) $this->pickup_lng,
                'note' => $this->pickup_note,
            ],

            'destination' => $this->dest_lat === null ? null : [
                'address' => (string) $this->dest_address,
                'lat' => (float) $this->dest_lat,
                'lng' => (float) $this->dest_lng,
            ],

            'trip' => [
                'distance_m' => (int) $this->distance_m,
                'duration_s' => (int) $this->duration_s,
                'actual_distance_m' => $this->actual_distance_m === null
                    ? null
                    : (int) $this->actual_distance_m,
                'route_polyline' => $this->route_polyline,
            ],

            // Rincian ongkos lengkap, dalam bentuk yang siap ditampilkan sebagai
            // struk. Angkanya ikut dikirim mentah supaya aplikasi bisa berhitung,
            // dan terformat supaya tiga aplikasi Flutter tidak perlu sepakat soal
            // pemisah ribuan.
            'fare' => $this->fareBreakdownForApi(),

            'driver' => $this->whenLoaded('driver', fn () => $this->driver === null
                ? null
                : (new OrderDriverResource($this->driver))->resolve()),

            /*
             * Kode jemput hanya saat masih relevan.
             *
             * Setelah perjalanan dimulai, kodenya sudah dipakai dan tidak perlu
             * lagi tampil di layar mana pun — termasuk di riwayat order, yang
             * bisa dibuka orang lain yang memegang HP itu.
             */
            'pickup_code' => $this->when(
                in_array($this->status->value, ['accepted', 'driver_arriving', 'driver_arrived'], true),
                fn (): ?string => $this->pickup_code,
            ),

            'timestamps' => [
                'requested_at' => $this->requested_at?->toIso8601String(),
                'matched_at' => $this->matched_at?->toIso8601String(),
                'arrived_at' => $this->arrived_at?->toIso8601String(),
                'started_at' => $this->started_at?->toIso8601String(),
                'completed_at' => $this->completed_at?->toIso8601String(),
                'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            ],

            'cancellation' => $this->cancelled_at === null ? null : [
                'by' => $this->cancelled_by,
                'note' => $this->cancellation_note,
                'fee' => (int) $this->cancellation_fee,
                'fee_formatted' => $this->cancellationFee()->format(),
            ],

            'can_cancel' => $this->status->isCancellable(),

            /*
             * ==============================================================
             *  APAKAH ORDER INI BOLEH DINILAI, DAN NILAINYA KALAU SUDAH
             * ==============================================================
             *  Ditentukan backend, bukan disimpulkan aplikasi. Aturannya dua:
             *  order harus `completed`, dan penumpang belum menilainya.
             *
             *  Aplikasi yang menyimpulkannya sendiri hanya bisa memeriksa yang
             *  pertama — dia tidak tahu apakah sudah pernah dinilai dari
             *  perangkat lain, atau di sesi sebelumnya. Yang terjadi kalau
             *  disimpulkan: form penilaian muncul lagi di riwayat order untuk
             *  perjalanan yang sudah dinilai, dan pengirimannya ditolak 409.
             *
             *  `rating` terisi kalau sudah dinilai, supaya layar riwayat bisa
             *  MENAMPILKAN bintang yang pernah diberikan — bukan hanya
             *  menyembunyikan formnya.
             * ==============================================================
             */
            'can_rate' => $this->status === OrderStatus::Completed
                && $this->ratingDariPenumpang() === null,

            'rating' => $this->when(
                $this->ratingDariPenumpang() !== null,
                fn (): array => [
                    'score' => (int) $this->ratingDariPenumpang()->score,
                    'tags' => $this->ratingDariPenumpang()->tags ?? [],
                    'comment' => $this->ratingDariPenumpang()->comment,
                    'rated_at' => $this->ratingDariPenumpang()
                        ->created_at?->toIso8601String(),
                ],
            ),
        ];
    }

    /**
     * Penilaian yang diberikan PENUMPANG untuk order ini, kalau ada.
     *
     * ========================================================================
     *  DI-CACHE PER INSTANS, KARENA DIPANGGIL EMPAT KALI
     * ========================================================================
     *  `toArray` memanggilnya untuk `can_rate` lalu untuk setiap field di dalam
     *  `rating`. Tanpa cache itu lima query untuk satu order — dan di daftar
     *  riwayat dengan 20 order, seratus query untuk satu halaman.
     *
     *  Yang lebih baik lagi: pemanggil melakukan eager load `ratings`. Cache ini
     *  yang membuat halaman riwayat tidak jatuh ke N+1 kalau lupa.
     * ========================================================================
     */
    private ?Rating $ratingPenumpang = null;

    private bool $ratingSudahDicari = false;

    private function ratingDariPenumpang(): ?Rating
    {
        if ($this->ratingSudahDicari) {
            return $this->ratingPenumpang;
        }

        $this->ratingSudahDicari = true;

        /*
         * Relasi yang sudah di-load dipakai apa adanya; kalau belum, dicari
         * lewat query.
         *
         * `relationLoaded` diperiksa lebih dulu karena mode strict Eloquent
         * (`preventLazyLoading`) MELEMPAR pada akses relasi yang belum di-load —
         * jadi memanggil `$this->ratings` tanpa pemeriksaan akan menjatuhkan
         * seluruh response, bukan sekadar melambatkannya.
         */
        if ($this->resource->relationLoaded('ratings')) {
            $this->ratingPenumpang = $this->resource->ratings
                ->firstWhere('rater_type', 'user');

            return $this->ratingPenumpang;
        }

        $this->ratingPenumpang = Rating::query()
            ->where('order_id', $this->resource->getKey())
            ->where('rater_type', 'user')
            ->first();

        return $this->ratingPenumpang;
    }

    /**
     * @return array<string, mixed>
     */
    private function fareBreakdownForApi(): array
    {
        return [
            'total' => $this->totalFare()->jsonSerialize(),
            'lines' => array_values(array_filter([
                $this->fareLine('Tarif dasar', (int) $this->base_fare),
                $this->fareLine('Jarak', (int) $this->distance_fare),
                $this->fareLine('Waktu', (int) $this->time_fare),
                $this->fareLine('Tarif sibuk', (int) $this->surge_amount),
                $this->fareLine('Penyesuaian tarif resmi', (int) $this->regulatory_adjustment),
                $this->fareLine('Biaya aplikasi', (int) $this->platform_fee),
                $this->fareLine('Biaya layanan', (int) $this->service_fee),
                $this->fareLine('Diskon', -(int) $this->discount_amount),
            ])),
        ];
    }

    /**
     * Baris bernilai nol tidak ditampilkan.
     *
     * Struk yang memuat "Waktu Rp 0" dan "Diskon Rp 0" membuat baris yang
     * benar-benar penting jadi sulit ditemukan. Yang nol tidak menjelaskan apa
     * pun.
     *
     * @return array<string, mixed>|null
     */
    private function fareLine(string $label, int $amount): ?array
    {
        if ($amount === 0) {
            return null;
        }

        $money = Money::of($amount);

        return [
            'label' => $label,
            'amount' => $amount,
            'formatted' => $money->format(),
        ];
    }
}
