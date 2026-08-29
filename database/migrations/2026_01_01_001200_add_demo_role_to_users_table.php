<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda akun demo.
 *
 * ============================================================================
 *  SATU KOLOM, BUKAN `is_demo` + `demo_role`
 * ============================================================================
 *  Dua kolom bisa saling bertentangan: `is_demo = false` dengan
 *  `demo_role = 'driver'`, atau sebaliknya. Dan yang membaca salah satunya saja
 *  akan mengambil keputusan yang salah tanpa ada yang menyadarinya.
 *
 *  Satu kolom nullable menutup celah itu: NULL berarti akun sungguhan, terisi
 *  berarti akun demo untuk aplikasi yang disebut. Tidak ada keadaan ketiga.
 * ============================================================================
 *
 * ============================================================================
 *  KENAPA DI TABEL `users`, BUKAN TABEL SENDIRI
 * ============================================================================
 *  Tabel `demo_accounts` terpisah akan menuntut JOIN di setiap pemeriksaan, dan
 *  yang lebih buruk: dia memungkinkan baris demo yang menunjuk ke user yang
 *  sudah dihapus, atau user yang punya dua baris demo.
 *
 *  Yang ditanyakan sebenarnya satu hal tentang user itu sendiri — apakah dia
 *  akun demo — jadi tempatnya di barisnya sendiri.
 * ============================================================================
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            /*
             * Peran menentukan aplikasi mana yang menampilkannya.
             *
             * Aplikasi driver tidak boleh menawarkan akun demo penumpang: yang
             * menekannya akan masuk sebagai penumpang di aplikasi driver, dan
             * seluruh layarnya kosong tanpa satu pun galat yang menjelaskan
             * kenapa.
             */
            $table->string('demo_role', 20)->nullable();

            /*
             * Urutan tampil di daftar akun demo.
             *
             * Tanpa ini urutannya mengikuti `id`, yang berubah setiap
             * `migrate:fresh --seed`. Daftar yang urutannya berpindah-pindah
             * membuat penguji menekan akun yang salah karena posisinya bergeser
             * sejak terakhir dia membukanya.
             */
            $table->unsignedSmallInteger('demo_order')->default(0);

            /*
             * Keterangan singkat yang ditampilkan di bawah nama akun, misalnya
             * "saldo Rp 50.000, dokumen lengkap".
             *
             * Ada supaya penguji tahu akun mana yang dia butuhkan tanpa mencoba
             * satu per satu — dan supaya kalimat itu tidak ditulis ulang di tiga
             * aplikasi Flutter.
             */
            $table->string('demo_note', 160)->nullable();
        });

        DB::statement("
            ALTER TABLE users ADD CONSTRAINT users_demo_role_check
            CHECK (demo_role IS NULL OR demo_role IN ('customer','driver','merchant'))
        ");

        /*
         * Index PARSIAL: hanya memuat baris demo.
         *
         * Akun demo jumlahnya belasan sementara `users` tumbuh tanpa batas.
         * Index penuh pada kolom yang hampir seluruhnya NULL memakan ruang untuk
         * baris yang tidak akan pernah ditanyakan lewat kolom ini.
         */
        DB::statement('
            CREATE INDEX users_demo_idx ON users (demo_role, demo_order)
            WHERE demo_role IS NOT NULL
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_demo_idx');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_demo_role_check');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['demo_role', 'demo_order', 'demo_note']);
        });
    }
};
