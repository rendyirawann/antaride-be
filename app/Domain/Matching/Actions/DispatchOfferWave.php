<?php

declare(strict_types=1);

namespace App\Domain\Matching\Actions;

use App\Domain\Matching\Contracts\DriverLocationIndex;
use App\Domain\Matching\DTOs\DriverCandidate;
use App\Domain\Matching\DTOs\OfferWaveResult;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Shared\Contracts\RealtimePublisher;
use Illuminate\Support\Facades\DB;

/**
 * Menawarkan satu order ke satu gelombang driver.
 *
 * ============================================================================
 *  KENAPA BERGELOMBANG, BUKAN SEMUA SEKALIGUS
 * ============================================================================
 *  Dua pilihan yang jelas, dan keduanya buruk:
 *
 *  SATU DRIVER SEKALIGUS (broadcast berurutan)
 *    Setiap penawaran menunggu 15 detik sebelum pindah ke driver berikutnya.
 *    Kalau tiga driver pertama tidak membuka HP-nya, penumpang menunggu 45
 *    detik sebelum driver keempat bahkan tahu ada order.
 *
 *  SEMUA DRIVER SEKALIGUS
 *    Cepat, tapi lima driver berlomba menekan terima dan empat di antaranya
 *    kalah. Driver yang sudah memutar arah lalu diberi tahu "order sudah
 *    diambil" akan berhenti mempercayai penawaran, dan yang terjadi berikutnya
 *    adalah dia menekan terima untuk SETIAP order tanpa melihat, karena toh
 *    belum tentu dapat.
 *
 *  GELOMBANG adalah jalan tengahnya: 3 driver terbaik dulu, lalu 5 berikutnya
 *  dengan radius 1,6 kali lebih lebar, sampai 4 gelombang. Persaingan terbatas
 *  pada beberapa orang, dan penumpang tidak menunggu berurutan.
 *
 *  Radius yang melebar penting: gelombang pertama sempit supaya yang ditawari
 *  benar-benar dekat, dan pelebarannya baru terjadi setelah terbukti tidak ada
 *  yang dekat mau mengambil.
 * ============================================================================
 *
 *  YANG SENGAJA TIDAK DILAKUKAN: menahan order untuk driver tertentu.
 *
 *  Penawaran adalah undangan, bukan reservasi. Driver yang ditawari tetap bisa
 *  menerima order lain lebih dulu, dan itu benar — menahannya berarti dia
 *  menganggur menunggu penawaran yang mungkin tidak dia buka. Yang menentukan
 *  siapa mendapatkan order adalah lock pada saat accept.
 */
class DispatchOfferWave
{
    public function __construct(
        private readonly FindCandidateDrivers $findCandidates,
        private readonly DriverLocationIndex $locationIndex,
        private readonly RealtimePublisher $realtime,
    ) {}

    public function handle(Order $order, int $wave): OfferWaveResult
    {
        if ($order->status !== OrderStatus::Searching) {
            // Order sudah tidak mencari driver. Bisa karena sudah diterima
            // driver lain, dibatalkan penumpang, atau dihentikan admin.
            return OfferWaveResult::stopped($wave, 'order tidak lagi mencari driver');
        }

        $radius = $this->radiusForWave($wave);
        $limit = $this->candidateLimitForWave($wave);

        $candidates = $this->findCandidates->handle($order, $radius, $limit);

        if ($candidates === []) {
            return OfferWaveResult::empty($wave, $radius);
        }

        $expiresAt = now()->addSeconds($this->offerTtlSeconds());

        $this->recordOffers($order, $candidates, $wave, $expiresAt);
        $this->notifyDrivers($order, $candidates, $wave, $expiresAt);

        return OfferWaveResult::offered($wave, $radius, $candidates, $expiresAt);
    }

    // -------------------------------------------------------------------------

    /**
     * Radius gelombang ke-N, dibatasi radius maksimum.
     *
     * Gelombang 1 memakai radius awal; setiap gelombang berikutnya dikali
     * pengali. Dengan nilai bawaan (2 km, x1,6, batas 8 km):
     *
     *   gelombang 1  2.000 m
     *   gelombang 2  3.200 m
     *   gelombang 3  5.120 m
     *   gelombang 4  8.000 m  (dari 8.192, dipotong batas)
     */
    public function radiusForWave(int $wave): int
    {
        $initial = (int) config('antaride.matching.initial_radius_m', 2000);
        $max = (int) config('antaride.matching.max_radius_m', 8000);
        $multiplier = (float) config('antaride.matching.radius_multiplier', 1.6);

        $radius = (int) round($initial * ($multiplier ** max(0, $wave - 1)));

        return min($radius, $max);
    }

    public function candidateLimitForWave(int $wave): int
    {
        return $wave <= 1
            ? (int) config('antaride.matching.candidates.first_wave', 3)
            : (int) config('antaride.matching.candidates.next_waves', 5);
    }

    public function offerTtlSeconds(): int
    {
        return (int) config('antaride.matching.offer_ttl_seconds', 15);
    }

    public function maxWaves(): int
    {
        return (int) config('antaride.matching.max_waves', 4);
    }

    // -------------------------------------------------------------------------

    /**
     * Simpan penawaran ke database.
     *
     * @param  array<int, DriverCandidate>  $candidates
     */
    private function recordOffers(
        Order $order,
        array $candidates,
        int $wave,
        \DateTimeInterface $expiresAt,
    ): void {
        $now = now();

        $rows = array_map(
            static fn (DriverCandidate $candidate): array => [
                'order_id' => $order->getKey(),
                'driver_id' => $candidate->driverId(),
                'wave' => $wave,
                'distance_to_pickup_m' => $candidate->distanceToPickupM,
                'score' => round($candidate->score, 3),

                // Rincian skor disimpan supaya keluhan "kenapa saya tidak pernah
                // dapat order" bisa dijawab dengan angka, bukan dugaan. Ini juga
                // satu-satunya cara memeriksa apakah bobotnya berperilaku seperti
                // yang diharapkan setelah diubah tim ops.
                'score_breakdown' => json_encode($candidate->scoreBreakdown, JSON_THROW_ON_ERROR),

                'offered_at' => $now,
                'expires_at' => $expiresAt,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $candidates,
        );

        /*
         * insertOrIgnore, bukan insert.
         *
         * Job matching bisa dijalankan ulang oleh queue setelah timeout, dan
         * gelombang yang sama bisa mencoba menawari driver yang sudah ditawari.
         * Unique index (order_id, driver_id) menolaknya; tanpa Ignore,
         * penolakan itu menjadi exception yang menggagalkan SELURUH gelombang
         * dan membuat driver-driver lain yang sah ikut tidak ditawari.
         */
        DB::table('order_offers')->insertOrIgnore($rows);
    }

    /**
     * Kirim penawaran ke HP driver.
     *
     * @param  array<int, DriverCandidate>  $candidates
     */
    private function notifyDrivers(
        Order $order,
        array $candidates,
        int $wave,
        \DateTimeInterface $expiresAt,
    ): void {
        $payload = $this->offerPayload($order, $wave, $expiresAt);

        foreach ($candidates as $candidate) {
            /*
             * ETA dan jarak berbeda per driver, jadi payload-nya tidak bisa
             * disamakan dan broadcast satu panggilan tidak bisa dipakai di sini.
             *
             * Jarak ke penjemputan adalah angka pertama yang dilihat driver
             * sebelum memutuskan, dan mengirim angka yang sama ke semua orang
             * akan membuat sebagian dari mereka menerima order yang jauh lebih
             * jauh daripada yang tertulis.
             */
            $this->realtime->publish(
                "driver:{$candidate->driverId()}",
                $payload + [
                    'distance_to_pickup_m' => $candidate->distanceToPickupM,
                    'eta_to_pickup_minutes' => $candidate->etaMinutes(),
                ],
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function offerPayload(Order $order, int $wave, \DateTimeInterface $expiresAt): array
    {
        /*
         * Yang TIDAK dikirim: nomor HP penumpang, nama lengkapnya, dan
         * pickup_code.
         *
         * Driver belum menerima order ini. Mengirim data penumpang ke setiap
         * driver yang ditawari berarti satu order membocorkan identitas
         * penumpang ke lima orang yang tidak akan pernah mengantarnya, dan
         * driver yang mengumpulkan penawaran bisa memetakan siapa berangkat dari
         * mana setiap hari.
         *
         * Data lengkap dikirim SETELAH accept berhasil.
         */
        return [
            'event' => 'order.offered',
            'order_uuid' => (string) $order->uuid,
            'service_code' => (string) $order->serviceType->code,
            'payment_method' => (string) $order->payment_method,
            'wave' => $wave,

            'pickup' => [
                'address' => (string) $order->pickup_address,
                'lat' => (float) $order->pickup_lat,
                'lng' => (float) $order->pickup_lng,
            ],

            'destination' => $order->dest_lat === null ? null : [
                'address' => (string) $order->dest_address,
                'lat' => (float) $order->dest_lat,
                'lng' => (float) $order->dest_lng,
            ],

            'trip' => [
                'distance_m' => (int) $order->distance_m,
                'duration_s' => (int) $order->duration_s,
            ],

            // Yang paling menentukan keputusan driver: berapa yang dia dapat.
            'driver_earning' => $order->driverEarning()->jsonSerialize(),
            'total_fare' => $order->totalFare()->jsonSerialize(),

            'expires_at' => $expiresAt->format(\DateTimeInterface::ATOM),
            'expires_in_seconds' => $this->offerTtlSeconds(),
        ];
    }

    /**
     * Cabut ketersediaan driver di seluruh layanan dan zona.
     *
     * Dipanggil setelah accept berhasil. Ada di sini, bukan di AcceptOrder,
     * supaya seluruh sentuhan ke indeks ketersediaan berada di satu tempat dan
     * bisa ditelusuri.
     */
    public function withdrawAvailability(int $driverId): void
    {
        $this->locationIndex->markUnavailableEverywhere($driverId);
    }
}
