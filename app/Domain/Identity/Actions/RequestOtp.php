<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\DTOs\OtpChallenge;
use App\Domain\Identity\Exceptions\OtpCooldownException;
use App\Domain\Identity\Support\PhoneNumber;
use App\Domain\Shared\Contracts\SmsSender;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Mengirim kode OTP ke sebuah nomor HP.
 *
 * ============================================================================
 *  KODENYA DI-HASH, BUKAN DISIMPAN APA ADANYA
 * ============================================================================
 *  Kode empat digit yang disimpan sebagai teks di database berarti siapa pun
 *  yang bisa membaca satu baris tabel — backup yang bocor, staf dengan akses
 *  read-only, satu SELECT di panel admin — bisa masuk ke akun siapa pun tanpa
 *  menyentuh HP-nya.
 *
 *  Empat digit memang hanya 10.000 kemungkinan, dan hash tidak mengubah itu.
 *  Yang dilindungi hash bukan kekuatan kodenya, tapi kebocoran lewat DATABASE.
 *  Yang melindungi dari menebak adalah batas percobaan dan masa berlaku.
 * ============================================================================
 *
 * ============================================================================
 *  KENAPA TIDAK MEMBERI TAHU APAKAH NOMORNYA TERDAFTAR
 * ============================================================================
 *  Response-nya sama untuk nomor yang sudah terdaftar dan yang belum. Kalau
 *  dibedakan, endpoint ini menjadi alat pemeriksaan keanggotaan: siapa pun bisa
 *  menguji daftar nomor dan mengetahui siapa saja pengguna Antaride.
 *
 *  Aplikasi tidak perlu tahu: alur login dan registrasi digabung, dan yang
 *  menentukan mana yang terjadi adalah langkah verifikasi.
 * ============================================================================
 */
class RequestOtp
{
    public function __construct(
        private readonly SmsSender $sms,
    ) {}

    public function handle(
        string $rawPhone,
        string $purpose = 'login',
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): OtpChallenge {
        $phone = PhoneNumber::normalize($rawPhone);

        $this->assertNotInCooldown($phone, $purpose);

        $code = $this->generateCode();
        $ttl = (int) config('antaride.otp.ttl_seconds', 300);
        $expiresAt = now()->addSeconds($ttl);

        DB::table('otp_requests')->insert([
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'purpose' => $purpose,
            'attempts' => 0,
            'expires_at' => $expiresAt,

            // IP dan user agent disimpan untuk penyelidikan penyalahgunaan.
            // Tanpa keduanya, satu-satunya yang terlihat saat ada serangan
            // adalah lonjakan jumlah baris tanpa petunjuk dari mana.
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),

            'created_at' => now(),
        ]);

        $this->sms->sendOtp($phone, $code);

        return new OtpChallenge(
            phone: $phone,
            purpose: $purpose,
            expiresAt: $expiresAt,
            resendAfterSeconds: (int) config('antaride.otp.resend_cooldown_seconds', 60),

            /*
             * Kode HANYA dibalikkan di environment non-produksi.
             *
             * Ini yang membuat pengembangan dan test end-to-end mungkin tanpa
             * gateway SMS. Pemeriksaannya `app()->isProduction()`, bukan flag
             * config tersendiri, karena flag bisa lupa dimatikan saat deploy —
             * dan konsekuensinya adalah endpoint publik yang membalas kode OTP
             * untuk nomor HP siapa pun.
             */
            debugCode: app()->isProduction() ? null : $code,
        );
    }

    // -------------------------------------------------------------------------

    /**
     * Jeda pengiriman ulang.
     *
     * Tanpa ini, satu tombol "kirim ulang" yang ditekan berulang kali menjadi
     * biaya SMS yang nyata — dan kalau ada yang mengotomatiskannya, biaya itu
     * bisa mencapai jutaan rupiah dalam satu malam. Rate limit di lapisan HTTP
     * juga ada, tapi yang ini berlaku per NOMOR, bukan per IP, jadi tidak bisa
     * dihindari dengan berganti alamat.
     */
    private function assertNotInCooldown(string $phone, string $purpose): void
    {
        $cooldown = (int) config('antaride.otp.resend_cooldown_seconds', 60);

        $last = DB::table('otp_requests')
            ->where('phone', $phone)
            ->where('purpose', $purpose)
            ->orderByDesc('created_at')
            ->first(['created_at']);

        if ($last === null) {
            return;
        }

        $elapsed = now()->diffInSeconds($last->created_at, absolute: true);

        if ($elapsed < $cooldown) {
            throw OtpCooldownException::retryAfter((int) ceil($cooldown - $elapsed));
        }
    }

    /**
     * Kode acak, atau kode sandbox di luar produksi.
     *
     * random_int, bukan rand: yang kedua bisa diprediksi kalau seseorang tahu
     * beberapa keluaran sebelumnya, dan itu cukup untuk mengambil alih akun.
     */
    private function generateCode(): string
    {
        $length = (int) config('antaride.otp.length', 4);

        if (! app()->isProduction()) {
            $sandbox = (string) config('antaride.otp.sandbox_code', '1234');

            if ($sandbox !== '') {
                return $sandbox;
            }
        }

        $max = (10 ** $length) - 1;

        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }
}
