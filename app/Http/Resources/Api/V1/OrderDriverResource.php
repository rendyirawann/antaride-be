<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Driver\Models\Driver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Driver, dari sudut pandang PENUMPANG yang ordernya dia bawa.
 *
 * ============================================================================
 *  HANYA YANG DIBUTUHKAN UNTUK MENGENALI DAN MENGHUBUNGI
 * ============================================================================
 *  Penumpang perlu tahu siapa yang datang dan kendaraan apa yang dicari. Dia
 *  TIDAK perlu tahu:
 *
 *    uuid / id driver     kunci untuk menelusuri driver di endpoint lain
 *    acceptance_rate      angka internal yang akan disalahpahami sebagai
 *                         penilaian, padahal driver yang menolak order jauh
 *                         bukan driver yang buruk
 *    completed_orders     jumlah order seumur hidup; tidak menambah apa pun
 *                         untuk keputusan penumpang, dan membuat driver baru
 *                         terlihat tidak layak dipercaya
 *    NIK, alamat          data pribadi
 *
 *  Nomor HP DITAMPILKAN PENUH, dan itu keputusan sadar: penumpang harus bisa
 *  menelepon drivernya saat sulit bertemu di titik jemput. Yang membatasinya
 *  adalah KAPAN — hanya selama order berjalan. Setelah order selesai, Resource
 *  ini tidak lagi menyertakan nomornya, karena tidak ada lagi alasan sah untuk
 *  saling menghubungi, dan nomor driver yang tersimpan di riwayat order adalah
 *  jalan pelecehan yang paling sering terjadi pada driver perempuan.
 * ============================================================================
 *
 * @mixin Driver
 */
class OrderDriverResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $vehicle = $this->vehicles->firstWhere('is_active', true)
            ?? $this->vehicles->first();

        return [
            'name' => $this->displayName(),
            'photo_url' => $this->whenLoaded('user', fn () => $this->user?->photo_url),

            'rating' => [
                'average' => (float) $this->rating_avg,
                'count' => (int) $this->rating_count,
            ],

            'vehicle' => $vehicle === null ? null : [
                'type' => (string) $vehicle->type,
                'brand' => $vehicle->brand,
                'model' => $vehicle->model,
                'color' => $vehicle->color,

                // Plat nomor adalah satu-satunya cara penumpang memastikan
                // kendaraan yang berhenti di depannya benar.
                'plate_number' => (string) $vehicle->plate_number,
            ],
        ];
    }

    /**
     * Nama panggilan: nama depan saja.
     *
     * Nama lengkap driver memuat marga dan gelar, dan menampilkannya penuh ke
     * setiap penumpang memberi lebih banyak informasi tentang dia daripada yang
     * dibutuhkan untuk sekadar mengenali orangnya di titik jemput.
     */
    private function displayName(): string
    {
        $parts = preg_split('/\s+/', trim((string) $this->full_name)) ?: [];

        return $parts[0] ?? (string) $this->full_name;
    }
}
