<?php

declare(strict_types=1);

namespace App\Domain\Support\Actions;

use App\Domain\Support\Models\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Membuat notifikasi in-app.
 *
 * ============================================================================
 *  TIDAK PERNAH MELEMPAR, DAN ITU ATURAN YANG PALING PENTING DI SINI
 * ============================================================================
 *  Action ini dipanggil dari dalam alur yang jauh lebih penting daripada
 *  notifikasinya: transisi status order, penyelesaian perjalanan, pembukuan
 *  dompet.
 *
 *  Notifikasi yang gagal dibuat TIDAK BOLEH menggagalkan salah satu pun di
 *  antaranya. Order yang sudah selesai dan uangnya sudah dibagi tidak boleh
 *  di-rollback karena satu baris notifikasi gagal disimpan — penumpang sudah
 *  turun dari kendaraan, dan tidak ada cara membatalkan itu.
 *
 *  Jadi setiap kegagalan ditelan dan dicatat. Yang hilang hanya satu baris
 *  pemberitahuan; statusnya sendiri tetap bisa dibaca aplikasi dari
 *  `/orders/{uuid}`.
 *
 *  Dan menelan exception saja TIDAK CUKUP. PostgreSQL membatalkan seluruh
 *  transaksi begitu satu pernyataan gagal, jadi kegagalan di sini akan meracuni
 *  transaksi pemanggilnya walaupun exception-nya sudah ditangkap. Yang
 *  menyelesaikannya adalah savepoint — lihat penjelasannya di dalam `handle()`.
 * ============================================================================
 *
 * ============================================================================
 *  DUPLIKAT DICEGAH DATABASE, BUKAN PEMERIKSAAN DULU
 * ============================================================================
 *  `insertOrIgnore` di atas unique index `notifications_dedupe_idx`. Yang
 *  memicunya: job yang di-retry, atau transisi status yang dijalankan dua kali
 *  karena request yang diulang.
 *
 *  Memeriksa "sudah ada?" lalu insert menyisakan celah di antara keduanya, dan
 *  dua job yang berjalan bersamaan masuk tepat ke celah itu. Database yang
 *  memutuskan.
 * ============================================================================
 */
final readonly class SendNotification
{
    /**
     * Kirim ke satu penerima.
     *
     * @param  array<string, mixed>|null  $action  Tujuan saat notifikasi ditekan.
     * @return bool True kalau barisnya benar-benar dibuat. False kalau duplikat
     *              atau gagal — dan pemanggil tidak perlu membedakannya.
     */
    public function handle(
        string $recipientType,
        int $recipientId,
        string $type,
        string $title,
        string $body,
        ?array $action = null,
    ): bool {
        try {
            /*
             * ==============================================================
             *  `insertOrIgnore` LEWAT QUERY BUILDER, BUKAN Notification::create
             * ==============================================================
             *  Eloquent tidak punya `insertOrIgnore` yang menghormati unique
             *  index; `create()` melempar `QueryException` pada duplikat.
             *  Menangkapnya lalu mengabaikannya juga bekerja, tapi exception
             *  yang dilempar-dan-ditangkap di jalur NORMAL — dan duplikat di
             *  sini memang normal — mengotori log dan pelacak galat.
             *
             *  Konsekuensinya: uuid dan timestamp harus diisi manual, karena
             *  trait `HasUuid` dan timestamp Eloquent tidak berjalan di query
             *  builder.
             * ==============================================================
             *
             * ==============================================================
             *  DIBUNGKUS TRANSAKSI, DAN ITU BUKAN UNTUK ATOMISITAS
             * ==============================================================
             *  Satu INSERT tidak butuh transaksi untuk atomik — dia sudah
             *  atomik sendiri.
             *
             *  Yang dibeli di sini adalah SAVEPOINT. Kalau Action ini dipanggil
             *  dari dalam transaksi yang sudah berjalan, `DB::transaction()`
             *  bersarang di Laravel menjadi `SAVEPOINT`, dan kegagalannya
             *  menjadi `ROLLBACK TO SAVEPOINT`.
             *
             *  Kenapa itu penting: PostgreSQL MEMBATALKAN SELURUH TRANSAKSI
             *  begitu ada satu pernyataan yang gagal. Setelah itu setiap query
             *  berikutnya ditolak dengan
             *
             *      SQLSTATE[25P02]: current transaction is aborted,
             *      commands ignored until end of transaction block
             *
             *  Jadi menangkap exception saja TIDAK CUKUP. Tanpa savepoint,
             *  notifikasi yang gagal — misalnya karena `recipient_type` yang
             *  tidak lolos CHECK constraint — akan meracuni transaksi
             *  pemanggilnya, dan yang gagal berikutnya adalah pekerjaan yang
             *  sebenarnya: penyimpanan status order, pembukuan dompet.
             *
             *  Dengan savepoint, kegagalannya berhenti di sini. Itu yang
             *  membuat janji "tidak pernah merusak alur pemanggil" benar-benar
             *  berlaku, bukan hanya berlaku selama tidak ada yang gagal.
             * ==============================================================
             */
            $dibuat = DB::transaction(
                fn (): int => DB::table('notifications')->insertOrIgnore([
                    'uuid' => (string) Str::uuid7(),
                    'recipient_type' => $recipientType,
                    'recipient_id' => $recipientId,
                    'type' => $type,

                    // Dipangkas ke panjang kolomnya. Judul yang lebih panjang
                    // dari 160 karakter akan ditolak Postgres — dan sebelum ada
                    // savepoint di atas, penolakan itu menggagalkan seluruh alur
                    // pemanggil untuk satu notifikasi.
                    'title' => Str::limit($title, 157),
                    'body' => Str::limit($body, 497),

                    'action' => $action === null
                        ? null
                        : json_encode($action, JSON_THROW_ON_ERROR),

                    'created_at' => now(),
                    'updated_at' => now(),
                ]),
            );

            return $dibuat === 1;
        } catch (Throwable $e) {
            Log::warning('Notifikasi gagal dibuat', [
                'recipient_type' => $recipientType,
                'recipient_id' => $recipientId,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Kirim notifikasi order ke penumpang.
     *
     * Pembungkus yang menyusun `action` dalam bentuk yang dikenali aplikasi.
     * Ada supaya bentuk itu ditulis di SATU tempat: `{"screen": "order",
     * "order_uuid": "..."}`.
     *
     * Kalau setiap pemanggil menyusunnya sendiri, salah satu akan memakai
     * `orderUuid` alih-alih `order_uuid` — dan notifikasi itu akan terbuka ke
     * layar kosong, tanpa galat apa pun.
     */
    public function forOrder(
        string $recipientType,
        int $recipientId,
        string $type,
        string $title,
        string $body,
        string $orderUuid,
    ): bool {
        return $this->handle(
            recipientType: $recipientType,
            recipientId: $recipientId,
            type: $type,
            title: $title,
            body: $body,
            action: [
                'screen' => 'order',
                'order_uuid' => $orderUuid,
            ],
        );
    }
}
