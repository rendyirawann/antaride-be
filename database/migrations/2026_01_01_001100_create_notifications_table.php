<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Notifikasi in-app untuk penumpang dan driver.
 *
 * ============================================================================
 *  INI PENGGANTI PUSH NOTIFICATION, BUKAN PELENGKAPNYA
 * ============================================================================
 *  Push notification (FCM) ditunda. Yang menggantikannya: notifikasi yang
 *  tersimpan di sini dan dibaca aplikasi saat dibuka.
 *
 *  Bedanya nyata dan harus disadari: push MENDATANGI pengguna, notifikasi
 *  in-app MENUNGGU dia membuka aplikasi. Untuk penumpang itu cukup — dia
 *  memang sedang menatap layar saat menunggu driver. Untuk driver itu TIDAK
 *  cukup: tawaran order yang hanya muncul saat dia membuka aplikasi akan
 *  terlewat, dan itu sebabnya tawaran tetap dijemput lewat penarikan berkala,
 *  bukan lewat tabel ini.
 *
 *  Jadi tabel ini untuk hal yang boleh terlambat dibaca: order diterima, driver
 *  tiba, perjalanan selesai, promo, pengumuman. Bukan untuk tawaran order.
 * ============================================================================
 *
 * ============================================================================
 *  KENAPA BUKAN TABEL `notifications` BAWAAN LARAVEL
 * ============================================================================
 *  Laravel punya `Illuminate\Notifications\DatabaseNotification` dengan tabel
 *  bawaannya sendiri. Itu tidak dipakai, dan alasannya bentuk kolomnya:
 *
 *    * `notifiable_type` menyimpan NAMA KELAS PHP. Mengubah namespace model
 *      berarti seluruh baris lama menunjuk ke kelas yang tidak ada lagi.
 *      Di sini yang disimpan `recipient_type` — string pendek dari enum
 *      aplikasi, yang tidak terikat struktur kode.
 *    * `data` adalah satu blob JSON untuk SEMUANYA, termasuk judul dan isi.
 *      Menyaring "notifikasi yang judulnya memuat X" jadi memindai JSON di
 *      setiap baris. Di sini judul dan isi punya kolomnya sendiri.
 *    * `id` bertipe UUID sebagai PRIMARY KEY. Untuk tabel yang tumbuh cepat,
 *      primary key acak membuat setiap insert menyisip di tengah index —
 *      bukan menempel di ujungnya seperti bigint berurut.
 * ============================================================================
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            /*
             * Penerima: `user`, `driver`, atau `merchant`.
             *
             * TIDAK ada `admin` di sini. Notifikasi backoffice diturunkan dari
             * keadaan sekarang — berapa approval menunggu, berapa order butuh
             * review — bukan disimpan sebagai baris.
             *
             * Alasannya: notifikasi yang disimpan bisa BASI. Baris "2 approval
             * menunggu" yang dibuat kemarin tetap berbunyi begitu walaupun
             * keduanya sudah disetujui, dan tim ops akan mengejar pekerjaan yang
             * sudah selesai. Yang diturunkan dari keadaan tidak bisa basi.
             */
            $table->string('recipient_type', 10);
            $table->unsignedBigInteger('recipient_id');

            // Jenis notifikasi, misalnya `order.accepted`. Dipakai aplikasi untuk
            // memilih ikon dan menentukan tujuan saat ditekan.
            $table->string('type', 64);

            $table->string('title', 160);
            $table->string('body', 500);

            /*
             * Tujuan saat notifikasi ditekan.
             *
             * Bentuknya `{"screen": "order", "order_uuid": "..."}` — bukan URL
             * atau deep link. Aplikasi yang menerjemahkannya, jadi struktur
             * navigasinya bisa berubah tanpa membuat notifikasi lama menunjuk ke
             * layar yang tidak ada lagi.
             */
            $table->jsonb('action')->nullable();

            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            /*
             * ==============================================================
             *  INDEX UTAMA: PENERIMA + WAKTU, MENURUN
             * ==============================================================
             *  Satu-satunya query yang dijalankan aplikasi adalah "notifikasi
             *  terbaru milik saya", dengan cursor pagination. Index ini
             *  melayaninya sepenuhnya — tanpa sort tambahan, tanpa memindai.
             *
             *  `created_at` DESC di dalam index, bukan ASC: pengurutannya selalu
             *  menurun, dan index yang arahnya berlawanan memaksa PostgreSQL
             *  memindainya mundur — yang bekerja, tapi lebih lambat pada tabel
             *  besar.
             * ==============================================================
             */
            $table->index(['recipient_type', 'recipient_id', 'created_at'], 'notifications_recipient_idx');
        });

        DB::statement("
            ALTER TABLE notifications ADD CONSTRAINT notifications_recipient_type_check
            CHECK (recipient_type IN ('user','driver','merchant'))
        ");

        /*
         * ==================================================================
         *  PARTIAL INDEX UNTUK YANG BELUM DIBACA
         * ==================================================================
         *  Lencana angka di ikon lonceng menjalankan
         *
         *      SELECT COUNT(*) ... WHERE recipient = ? AND read_at IS NULL
         *
         *  pada setiap pembukaan aplikasi. Index parsial ini hanya memuat baris
         *  yang BELUM dibaca — dan karena notifikasi yang dibaca jauh lebih
         *  banyak daripada yang belum, ukurannya tetap kecil selamanya
         *  walaupun tabelnya tumbuh terus.
         *
         *  Index biasa pada `read_at` akan memuat seluruh baris, termasuk
         *  jutaan yang sudah dibaca dan tidak akan pernah ditanyakan lagi.
         * ==================================================================
         */
        DB::statement('
            CREATE INDEX notifications_unread_idx
            ON notifications (recipient_type, recipient_id)
            WHERE read_at IS NULL
        ');

        /*
         * ==================================================================
         *  MENCEGAH NOTIFIKASI GANDA UNTUK PERISTIWA YANG SAMA
         * ==================================================================
         *  Yang memicunya: retry job, atau transisi status yang dijalankan dua
         *  kali karena request yang diulang.
         *
         *  Kuncinya (penerima, type, action) — dua notifikasi dengan jenis dan
         *  tujuan yang sama untuk orang yang sama adalah duplikat. `action`
         *  memuat uuid order, jadi order yang BERBEDA tetap menghasilkan
         *  notifikasi terpisah.
         *
         *  `NULLS NOT DISTINCT` supaya notifikasi tanpa action — pengumuman
         *  umum — juga dijaga unik. Tanpa itu, `NULL = NULL` bernilai unknown
         *  dan setiap pengumuman bisa masuk berkali-kali.
         *
         *  Dijadikan index, bukan constraint: `insertOrIgnore` di Laravel
         *  memerlukan target konflik, dan itu index.
         */
        DB::statement('
            CREATE UNIQUE INDEX notifications_dedupe_idx
            ON notifications (recipient_type, recipient_id, type, (action::text))
            NULLS NOT DISTINCT
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
