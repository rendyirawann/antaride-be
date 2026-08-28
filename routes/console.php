<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Jadwal
|--------------------------------------------------------------------------
|
| Dijalankan oleh SATU cron entry di server:
|
|     * * * * * cd /path/antaride-be && php artisan schedule:run >> /dev/null 2>&1
|
| Di Windows, `dev.bat` menjalankan `schedule:work` di tab tersendiri — proses
| yang tetap hidup dan memicu jadwalnya sendiri, karena Task Scheduler Windows
| tidak punya granularitas per menit yang nyaman.
|
| ============================================================================
|  ZONA WAKTU JADWAL ADALAH ASIA/JAKARTA, DAN ITU DINYATAKAN EKSPLISIT
| ============================================================================
|  `APP_TIMEZONE` di proyek ini UTC — seluruh penyimpanan memakai UTC. Tanpa
|  `->timezone()` di bawah, "jalankan 01:30" berarti 01:30 UTC, yaitu 08:30 WIB.
|
|  Akibatnya bukan sekadar waktu yang bergeser: agregasi harian kemarin akan
|  berjalan setelah hari baru sudah berlangsung satu setengah jam, dan angka
|  penutupan hari dihitung dari rentang yang sudah tercampur.
| ============================================================================
*/

$zonaBisnis = (string) config('antaride.timezone', 'Asia/Jakarta');

/*
 * Agregasi HARI INI, tiap 15 menit.
 *
 * Supaya dashboard tidak tertinggal sampai tengah malam. Angkanya belum final,
 * dan itu memang tidak perlu — grafik tren dipakai untuk melihat arah.
 *
 * `withoutOverlapping` bukan formalitas: agregasi hari yang sibuk bisa memakan
 * lebih dari 15 menit, dan dua proses yang meng-upsert baris yang sama akan
 * saling menunggu lock sambil menumpuk.
 *
 * `runInBackground` supaya tugas lain di menit yang sama tidak ikut tertunda.
 */
Schedule::command('antaride:aggregate-metrics --today')
    ->everyFifteenMinutes()
    ->timezone($zonaBisnis)
    ->withoutOverlapping(20)
    ->runInBackground()
    ->description('Agregasi metrik hari ini untuk dashboard');

/*
 * Agregasi HARI KEMARIN, 01:30 WIB.
 *
 * Dijalankan setelah batas hari bisnis benar-benar lewat. Ini yang menjadi
 * angka final — dan karena idempoten, dia menimpa hasil agregasi berjalan
 * yang belum lengkap.
 *
 * Jam 01:30, bukan 00:05: order yang dimulai sebelum tengah malam bisa selesai
 * setelahnya, dan `completed_at`-nya baru terisi saat itu. Agregasi yang
 * berjalan lima menit setelah tengah malam akan melewatkan perjalanan yang
 * masih di jalan.
 */
Schedule::command('antaride:aggregate-metrics')
    ->dailyAt('01:30')
    ->timezone($zonaBisnis)
    ->withoutOverlapping(60)
    ->runInBackground()
    ->description('Agregasi metrik final hari kemarin');

/*
 * Pemangkasan log, 03:00 WIB.
 *
 * Jam paling sepi. DELETE bertahap tetap menghasilkan WAL dan I/O, dan
 * menjalankannya pada jam sibuk berarti bersaing dengan penulisan status order.
 *
 * Setiap hari, bukan mingguan: batch harian yang kecil jauh lebih ringan
 * daripada satu batch besar sekali seminggu — dan kalau satu hari terlewat,
 * jalan berikutnya tinggal membuang sedikit lebih banyak.
 */
Schedule::command('antaride:prune-logs')
    ->dailyAt('03:00')
    ->timezone($zonaBisnis)
    ->withoutOverlapping(120)
    ->runInBackground()
    ->description('Membuang log operasional yang sudah melewati masa retensi');

/*
 * Membersihkan token Sanctum yang kadaluarsa, 03:30 WIB.
 *
 * Tanpa ini, `personal_access_tokens` tumbuh selamanya — satu baris per
 * perangkat per login, dan tidak ada yang pernah menghapusnya. Tabel itu
 * dibaca pada SETIAP request API, jadi ukurannya berpengaruh langsung ke
 * latensi seluruh aplikasi.
 */
Schedule::command('sanctum:prune-expired --hours=24')
    ->dailyAt('03:30')
    ->timezone($zonaBisnis)
    ->runInBackground()
    ->description('Membuang token Sanctum yang sudah kadaluarsa');

/*
 * Membersihkan job gagal yang sudah lama, Senin 04:00 WIB.
 *
 * Mingguan, bukan harian: job gagal adalah hal yang perlu DIBACA sebelum
 * dibuang. Pembersihan harian akan menghapus kegagalan akhir pekan sebelum ada
 * yang masuk kerja dan melihatnya.
 */
Schedule::command('queue:prune-failed --hours=336')
    ->weeklyOn(1, '04:00')
    ->timezone($zonaBisnis)
    ->runInBackground()
    ->description('Membuang catatan job gagal yang lebih tua dari 14 hari');
