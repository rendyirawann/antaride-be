<?php

declare(strict_types=1);

namespace App\Domain\Matching\Scoring;

use App\Domain\Driver\Models\Driver;
use App\Domain\Matching\DTOs\DriverPosition;

/**
 * Memberi skor pada kandidat driver.
 *
 * Rumusnya, sesuai blueprint bagian 4.3:
 *
 *   skor = w1 x (1 - jarak/radius)          jarak dekat lebih baik
 *        + w2 x (rating/5)
 *        + w3 x acceptance_rate
 *        + w4 x min(idle/900, 1)            KEADILAN: yang lama nganggur
 *        - w5 x cancellation_rate
 *
 * Bobot awal: 0.45, 0.15, 0.15, 0.20, 0.05 — KELIMANYA berjumlah tepat 1.00,
 * jadi rentang skor selalu sama dan bisa dibandingkan antar zona.
 *
 * Yang bisa DICAPAI hanya 0.95, karena `cancellation` hanya mengurangi. Itu
 * yang dikembalikan `maxPossibleScore()`, dan itu yang harus dipakai panel
 * admin sebagai pembagi saat menampilkan skor dalam persen — kalau memakai
 * 1.00, driver sempurna akan tampil 95% dan tidak akan pernah ada yang
 * mencapai 100%, angka yang membuat staf ops menyimpulkan ada yang rusak.
 *
 * Rentang penuhnya: -0.05 (pembatal total tepat di batas radius) sampai 0.95.
 *
 * ============================================================================
 *  BOBOT KEADILAN BUKAN HIASAN
 * ============================================================================
 *  Komponen `idle` yang bobotnya 0.20 adalah yang paling mudah dianggap tidak
 *  perlu, dan yang paling penting untuk kelangsungan platform.
 *
 *  Tanpa dia, driver dengan rating tinggi yang parkir di lokasi bagus akan
 *  memenangkan hampir setiap order di sekitarnya. Driver baru yang ratingnya
 *  masih 5.00 tapi acceptance_rate-nya belum terbentuk akan kalah terus, tidak
 *  pernah mendapat order, tidak pernah membangun riwayat, dan berhenti dalam
 *  dua minggu.
 *
 *  Yang terlihat di dashboard: jumlah driver aktif tidak pernah naik walaupun
 *  pendaftaran terus masuk.
 * ============================================================================
 */
class DriverScorer
{
    /**
     * Skor satu kandidat.
     *
     * @param  int  $radiusMeters  radius gelombang yang sedang berjalan
     * @param  int  $idleSeconds  berapa lama driver ini belum dapat order
     * @return array{score: float, breakdown: array<string, float>}
     */
    public function score(
        Driver $driver,
        DriverPosition $position,
        int $radiusMeters,
        int $idleSeconds,
    ): array {
        $weights = config('antaride.matching.weights');

        // --- Jarak: makin dekat makin tinggi ---
        //
        // Dinormalkan terhadap radius gelombang yang sedang berjalan, bukan
        // terhadap radius maksimum. Alasannya: pada gelombang pertama dengan
        // radius 2 km, driver 1,9 km jauh harus mendapat skor jarak rendah.
        // Kalau dinormalkan ke 8 km, dia akan mendapat 0,76 dan terlihat dekat
        // padahal hampir di batas.
        $distance = $position->distanceM ?? (float) $radiusMeters;
        $distanceScore = $radiusMeters > 0
            ? max(0.0, 1.0 - ($distance / $radiusMeters))
            : 0.0;

        // --- Rating ---
        $rating = (float) ($driver->rating_avg ?? 5.0);
        $ratingScore = max(0.0, min(1.0, $rating / 5.0));

        // --- Acceptance rate ---
        $acceptance = (float) ($driver->acceptance_rate ?? 100.0);
        $acceptanceScore = max(0.0, min(1.0, $acceptance / 100.0));

        // --- Keadilan: yang lama menganggur dinaikkan ---
        $idleCap = (int) config('antaride.matching.idle_cap_seconds', 900);
        $idleScore = $idleCap > 0
            ? min(1.0, max(0, $idleSeconds) / $idleCap)
            : 0.0;

        // --- Cancellation rate: pengurang ---
        $cancellation = (float) ($driver->cancellation_rate ?? 0.0);
        $cancellationPenalty = max(0.0, min(1.0, $cancellation / 100.0));

        $breakdown = [
            'distance' => (float) $weights['distance'] * $distanceScore,
            'rating' => (float) $weights['rating'] * $ratingScore,
            'acceptance' => (float) $weights['acceptance'] * $acceptanceScore,
            'idle' => (float) $weights['idle'] * $idleScore,
            'cancellation' => -((float) $weights['cancellation'] * $cancellationPenalty),
        ];

        // Skor bisa negatif kalau cancellation_rate sangat tinggi dan komponen
        // lain rendah. Itu dibiarkan: driver seperti itu memang harus berada di
        // urutan paling bawah, dan mengklem ke nol akan membuatnya setara dengan
        // driver yang jaraknya tepat di batas radius.
        return [
            'score' => array_sum($breakdown),
            'breakdown' => $breakdown + [
                'raw_distance_m' => $distance,
                'raw_rating' => $rating,
                'raw_acceptance' => $acceptance,
                'raw_idle_seconds' => (float) $idleSeconds,
                'raw_cancellation' => $cancellation,
            ],
        ];
    }

    /**
     * Skor tertinggi yang mungkin dicapai.
     *
     * Dipakai panel admin untuk menampilkan skor sebagai persentase, supaya
     * angka 0,82 punya arti bagi staf ops yang tidak tahu rumusnya.
     */
    public function maxPossibleScore(): float
    {
        $weights = config('antaride.matching.weights');

        return (float) $weights['distance']
            + (float) $weights['rating']
            + (float) $weights['acceptance']
            + (float) $weights['idle'];
    }
}
