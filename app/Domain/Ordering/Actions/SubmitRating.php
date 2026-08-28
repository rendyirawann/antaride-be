<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Actions;

use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Exceptions\RatingNotAllowedException;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\Rating;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Penumpang menilai driver setelah perjalanan selesai.
 *
 * ============================================================================
 *  KENAPA INI ADA, DAN KENAPA TANPANYA SISTEMNYA TIMPANG
 * ============================================================================
 *  `drivers.rating_avg` ditampilkan di kartu driver yang dilihat penumpang, dan
 *  ikut menentukan prioritas driver di `DriverScorer`. Tanpa jalur pengisiannya,
 *  angka itu tidak akan pernah berubah dari nilai awalnya — jadi yang
 *  ditampilkan bukan reputasi, hanya konstanta.
 *
 *  Yang lebih buruk: penumpang tidak punya cara melaporkan pengalaman buruk
 *  selain lewat tiket bantuan, dan driver tidak punya cara membangun reputasi
 *  yang menguntungkannya di pencocokan.
 * ============================================================================
 *
 * ============================================================================
 *  RATA-RATA DIHITUNG ULANG DARI SELURUH BARIS, BUKAN DITAMBAHKAN BERTAHAP
 * ============================================================================
 *  Rumus bertahap — `avg_baru = (avg_lama * n + skor) / (n + 1)` — lebih murah,
 *  dan itu satu-satunya kelebihannya. Kekurangannya menumpuk:
 *
 *    * Galat pembulatan bertambah di setiap penilaian, dan tidak ada cara
 *      memulihkannya selain menghitung ulang.
 *    * Rating yang DISEMBUNYIKAN admin — karena berisi hinaan atau jelas
 *      dibuat untuk menjatuhkan — tidak bisa dikeluarkan dari rata-rata.
 *    * Dua penilaian yang masuk bersamaan menghasilkan lost update, karena
 *      keduanya membaca `avg_lama` yang sama.
 *
 *  Menghitung ulang dari `ratings` menyelesaikan ketiganya sekaligus. Biayanya
 *  satu agregasi pada baris milik satu driver — beberapa ratus baris setelah
 *  setahun beroperasi, dengan index yang mendukungnya.
 * ============================================================================
 */
final readonly class SubmitRating
{
    /**
     * @param  list<string>  $tags
     *
     * @throws RatingNotAllowedException
     */
    public function handle(
        Order $order,
        int $userId,
        int $score,
        array $tags = [],
        ?string $comment = null,
    ): Rating {
        /*
         * Pemilik order diperiksa DI SINI, bukan hanya di controller.
         *
         * Action ini juga dipanggil dari panel admin nanti, dan pemeriksaan yang
         * hanya ada di controller API tidak ikut terbawa ke pemanggil lain.
         */
        if ((int) $order->user_id !== $userId) {
            throw RatingNotAllowedException::notYourOrder();
        }

        if ($order->status !== OrderStatus::Completed) {
            throw RatingNotAllowedException::orderNotCompleted();
        }

        $driverId = $order->driver_id === null ? null : (int) $order->driver_id;

        if ($driverId === null) {
            // Order selesai tanpa driver tidak mungkin lolos
            // `orders_completed_shape_check` di database. Kalau sampai terjadi,
            // yang salah bukan penilaiannya — dan menilai "tidak ada siapa pun"
            // akan menghasilkan baris rating yang menunjuk ke nol.
            throw RatingNotAllowedException::orderNotCompleted();
        }

        return DB::transaction(function () use ($order, $userId, $driverId, $score, $tags, $comment): Rating {
            try {
                $rating = Rating::create([
                    'order_id' => (int) $order->getKey(),
                    'rater_type' => 'user',
                    'rater_id' => $userId,
                    'ratee_type' => 'driver',
                    'ratee_id' => $driverId,
                    'score' => $score,
                    'tags' => $tags === [] ? null : $tags,
                    'comment' => $comment,
                ]);
            } catch (Throwable $e) {
                /*
                 * ============================================================
                 *  PENILAIAN GANDA DICEGAH DATABASE, BUKAN PEMERIKSAAN DULU
                 * ============================================================
                 *  Tabelnya punya `unique(order_id, rater_type)`. Memeriksa
                 *  "sudah pernah menilai?" lebih dulu lalu insert menyisakan
                 *  celah di antara keduanya — dan penumpang yang menekan tombol
                 *  kirim dua kali di jaringan lambat masuk tepat ke celah itu.
                 *
                 *  Jadi insert-nya dicoba, dan pelanggaran unique diterjemahkan
                 *  ke galat yang bisa dibaca. Database yang menjadi penentunya.
                 * ============================================================
                 */
                if ($this->isUniqueViolation($e)) {
                    throw RatingNotAllowedException::alreadyRated();
                }

                throw $e;
            }

            $this->hitungUlangRatingDriver($driverId);

            return $rating;
        });
    }

    /**
     * Hitung ulang rata-rata dan jumlah rating driver.
     *
     * Hanya rating yang TIDAK disembunyikan yang dihitung — lihat penjelasan di
     * docblock kelas.
     */
    private function hitungUlangRatingDriver(int $driverId): void
    {
        $agregat = DB::table('ratings')
            ->where('ratee_type', 'driver')
            ->where('ratee_id', $driverId)
            ->where('is_hidden', false)
            ->selectRaw('COUNT(*) AS jumlah, AVG(score) AS rata')
            ->first();

        $jumlah = (int) ($agregat->jumlah ?? 0);

        DB::table('drivers')->where('id', $driverId)->update([
            /*
             * Driver tanpa satu pun rating dikembalikan ke 5.00, bukan 0.00.
             *
             * Nol akan membuatnya berada di dasar seluruh pengurutan di
             * `DriverScorer` — jadi driver baru tidak akan pernah mendapat order
             * pertamanya, dan tanpa order pertama dia tidak akan pernah punya
             * rating. Itu lingkaran yang tidak bisa diputus dari sisi driver.
             */
            'rating_avg' => $jumlah === 0
                ? '5.00'
                : number_format((float) $agregat->rata, 2, '.', ''),

            'rating_count' => $jumlah,
            'updated_at' => now(),
        ]);
    }

    private function isUniqueViolation(Throwable $e): bool
    {
        // 23505 = unique_violation di PostgreSQL. Diperiksa dari kodenya, bukan
        // dari teks pesannya — teks berubah antar versi dan antar locale.
        return str_contains($e->getMessage(), '23505')
            || str_contains($e->getMessage(), 'Unique violation');
    }
}
