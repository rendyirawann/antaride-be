<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Identity\Support\PhoneNumber;
use App\Domain\Ordering\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Order dari sudut pandang DRIVER.
 *
 * ============================================================================
 *  TIGA HAL YANG SENGAJA TIDAK ADA DI SINI
 * ============================================================================
 *  1. `pickup_code`
 *     Empat digit yang disebutkan penumpang untuk membuktikan orangnya benar.
 *     Kalau ikut terkirim, driver bisa membacanya dari payload dan menandai
 *     "sudah menjemput" tanpa pernah bertemu penumpangnya — lalu membatalkan,
 *     dan penumpang yang tidak pernah dijemput ditagih biaya pembatalan.
 *
 *  2. Rincian ongkos lengkap
 *     Yang perlu diketahui driver adalah PENDAPATANNYA, bukan seluruh angka
 *     yang dibayar penumpang. Mengirim keduanya membuat komisi platform tampil
 *     sebagai selisih di layar driver setiap order, dan itu bukan informasi yang
 *     membantunya bekerja — hanya sumber keluhan yang berulang.
 *
 *  3. Nomor HP penumpang SETELAH order selesai
 *     Selama order berjalan, nomornya perlu supaya driver bisa menelepon saat
 *     sulit bertemu. Setelah selesai, tidak ada lagi alasan sah, dan nomor
 *     penumpang yang tersimpan di riwayat order driver adalah jalan pelecehan
 *     yang paling sering terjadi.
 * ============================================================================
 *
 * @mixin Order
 */
class DriverOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $sedangBerjalan = $this->status->isActiveForDriver();

        return [
            'uuid' => (string) $this->uuid,
            'order_number' => (string) $this->order_number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),

            'service' => $this->whenLoaded('serviceType', fn (): array => [
                'code' => (string) $this->serviceType->code,
                'name' => (string) $this->serviceType->name,
            ]),

            // Metode pembayaran adalah hal PERTAMA yang perlu diketahui driver:
            // tunai berarti dia harus menerima uang, wallet berarti tidak boleh
            // menagih apa pun.
            'payment_method' => (string) $this->payment_method,

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
                'route_polyline' => $this->route_polyline,
            ],

            // Hanya pendapatan driver, bukan seluruh rincian ongkos.
            'earning' => $this->driverEarning()->jsonSerialize(),

            // Pada order tunai, ini yang harus ditagih ke penumpang.
            'collect_from_passenger' => $this->isCash()
                ? $this->totalFare()->jsonSerialize()
                : null,

            'passenger' => $this->whenLoaded('user', fn (): array => [
                'name' => (string) $this->user->name,
                'photo_url' => $this->user->photo_url,

                // Nomor penuh HANYA selama order berjalan.
                'phone' => $sedangBerjalan
                    ? PhoneNumber::forDisplay((string) $this->user->phone)
                    : PhoneNumber::masked((string) $this->user->phone),
            ]),

            'timestamps' => [
                'matched_at' => $this->matched_at?->toIso8601String(),
                'arrived_at' => $this->arrived_at?->toIso8601String(),
                'started_at' => $this->started_at?->toIso8601String(),
                'completed_at' => $this->completed_at?->toIso8601String(),
                'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            ],

            /*
             * Transisi yang boleh dilakukan dari status sekarang.
             *
             * Dikirim backend, bukan disimpulkan aplikasi. Aturan transisi ada
             * di state machine, dan kalau aplikasi punya salinannya sendiri,
             * akan ada versi aplikasi yang menampilkan tombol yang selalu
             * ditolak — bug yang terlihat sebagai aplikasi rusak.
             */
            'allowed_transitions' => array_map(
                static fn ($status): string => $status->value,
                $this->status->allowedTransitions(),
            ),

            'needs_fare_review' => (bool) $this->needs_fare_review,
        ];
    }
}
