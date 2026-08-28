<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Rate limit berbeda per endpoint, bukan satu angka global (blueprint bagian 5).
 *
 * Satu angka global selalu salah di dua arah sekaligus: terlalu longgar untuk
 * endpoint OTP yang biaya penyalahgunaannya kita tanggung dalam Rupiah per SMS,
 * dan terlalu ketat untuk ping GPS yang memang harus datang tiap 4 detik.
 *
 * Yang perlu dipegang: kunci limiter harus memakai identitas yang tidak bisa
 * diganti murah oleh penyerang. Membatasi OTP per IP saja tidak ada gunanya
 * karena IP seluler berganti dengan mematikan mode pesawat; karena itu batas
 * utamanya per nomor HP, dengan batas per IP sebagai lapisan kedua.
 */
class RateLimitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerAuthLimiters();
        $this->registerOrderLimiters();
        $this->registerAdminLimiters();
        $this->registerDefaultApiLimiter();
    }

    private function registerAuthLimiters(): void
    {
        /*
         * Permintaan OTP: dua lapis sekaligus, keduanya harus lolos.
         *
         *   3 per nomor per 15 menit  — menahan SMS bombing ke satu korban
         *   10 per IP per jam         — menahan enumerasi nomor terdaftar
         */
        RateLimiter::for('otp-request', function (Request $request) {
            $phone = $this->normalizePhone((string) $request->input('phone'));

            return [
                Limit::perMinutes(15, 3)->by("otp:phone:{$phone}"),
                Limit::perHour(10)->by('otp:ip:'.$request->ip()),
            ];
        });

        /*
         * Verifikasi OTP dibatasi lebih longgar dari permintaan, tapi tetap
         * ada. Tanpa ini, kode 4 digit bisa ditebak dengan 10.000 percobaan.
         * Batas percobaan per kode juga ditegakkan di tabel otp_requests;
         * yang di sini mencegah beban request-nya sampai ke database.
         */
        RateLimiter::for('otp-verify', function (Request $request) {
            $phone = $this->normalizePhone((string) $request->input('phone'));

            return [
                Limit::perMinutes(15, 10)->by("otp-verify:phone:{$phone}"),
                Limit::perHour(30)->by('otp-verify:ip:'.$request->ip()),
            ];
        });
    }

    private function registerOrderLimiters(): void
    {
        // Estimasi harga. Wajar dipanggil berulang saat user menggeser pin
        // di peta, jadi batasnya longgar.
        RateLimiter::for('quotes', fn (Request $request) => Limit::perMinute(30)
            ->by($this->userKey($request, 'quotes')));

        // Pembuatan order. Satu user tidak punya alasan sah membuat lima
        // order per menit, dan setiap order yang gagal menyisakan pekerjaan
        // pembersihan di matching engine.
        RateLimiter::for('orders', fn (Request $request) => Limit::perMinute(5)
            ->by($this->userKey($request, 'orders')));

        // Endpoint yang menggerakkan uang. Sengaja lebih ketat dari orders.
        RateLimiter::for('money', fn (Request $request) => Limit::perMinute(3)
            ->by($this->userKey($request, 'money')));

        /*
         * Ping GPS. Angka ini ada di sini sebagai jaring, bukan penegak utama.
         * Ping sebenarnya masuk ke location service Go yang punya rate limit
         * sendiri berbasis Redis INCR, karena 1.000 driver yang ping tiap 4
         * detik tidak boleh sampai menyentuh PHP.
         */
        RateLimiter::for('location-ping', fn (Request $request) => Limit::perMinute(30)
            ->by($this->userKey($request, 'ping')));

        /*
         * Driver menekan online/offline.
         *
         * Longgar, tapi ada. Yang dicegah bukan penyalahgunaan melainkan gejala
         * bug di aplikasi driver: tombol yang tidak terasa merespons ditekan
         * berulang kali, dan setiap tekanan menulis ke Redis dan membuka atau
         * menutup sesi kerja. Tanpa batas, satu aplikasi yang bermasalah bisa
         * membanjiri indeks ketersediaan.
         */
        RateLimiter::for('driver-status', fn (Request $request) => Limit::perMinute(20)
            ->by($this->userKey($request, 'driver-status')));

        /*
         * Driver menekan terima order.
         *
         * ====================================================================
         *  ANGKANYA HARUS LONGGAR, DAN ITU BUKAN KELALAIAN
         * ====================================================================
         *  Driver yang kalah balapan berkali-kali dalam semenit adalah driver
         *  yang RAJIN, bukan yang menyalahgunakan. Pada jam sibuk di zona padat,
         *  dia bisa ditawari sepuluh order dan kalah delapan di antaranya.
         *
         *  Rate limit yang ketat di sini akan menghukum justru driver yang
         *  paling aktif, dan gejalanya adalah 429 di tengah jam ramai — persis
         *  saat pendapatannya paling bergantung pada kecepatan merespons.
         *
         *  Yang menjaga accept dari penyalahgunaan bukan rate limit, tapi
         *  otorisasi penawaran: driver hanya bisa menerima order yang memang
         *  ditawarkan kepadanya. Batas ini hanya jaring terhadap aplikasi yang
         *  mengulang request tanpa henti.
         * ====================================================================
         */
        RateLimiter::for('driver-accept', fn (Request $request) => Limit::perMinute(60)
            ->by($this->userKey($request, 'driver-accept')));
    }

    private function registerAdminLimiters(): void
    {
        /*
         * Login admin: agresif, sesuai blueprint bagian 3. Dikunci per email,
         * bukan per IP, karena yang dilindungi adalah akun tertentu. Lockout
         * 15 menit setelah 5 percobaan gagal.
         */
        RateLimiter::for('admin-login', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email')));
            $attempts = (int) config('antaride.security.login_max_attempts', 5);
            $lockout = (int) config('antaride.security.login_lockout_minutes', 15);

            return [
                Limit::perMinutes($lockout, $attempts)->by("admin-login:email:{$email}"),
                Limit::perMinutes($lockout, $attempts * 4)->by('admin-login:ip:'.$request->ip()),
            ];
        });

        // Aksi finance yang menggerakkan uang dalam jumlah besar. Batas rendah
        // bukan untuk menahan penyerang, tapi untuk memperlambat kesalahan
        // massal yang tidak sengaja.
        RateLimiter::for('admin-sensitive', fn (Request $request) => Limit::perMinute(10)
            ->by('admin-sensitive:'.($request->user('admin')?->id ?? $request->ip())));
    }

    private function registerDefaultApiLimiter(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($this->userKey($request, 'api')));
    }

    /**
     * Kunci berbasis user kalau sudah terautentikasi, IP kalau belum. User yang
     * terautentikasi tidak boleh bisa lolos batas hanya dengan berganti
     * jaringan.
     */
    private function userKey(Request $request, string $scope): string
    {
        $id = $request->user()?->id;

        return $id !== null
            ? "{$scope}:user:{$id}"
            : "{$scope}:ip:".$request->ip();
    }

    /**
     * Nomor dinormalkan supaya 0812..., +62812..., dan 62812... dihitung
     * sebagai satu nomor yang sama. Tanpa ini, batas per nomor bisa dilewati
     * hanya dengan mengubah format penulisan.
     */
    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '0')) {
            return '62'.substr($digits, 1);
        }

        if (str_starts_with($digits, '8')) {
            return '62'.$digits;
        }

        return $digits;
    }
}
