<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\DTOs\AuthenticatedSession;
use App\Domain\Identity\Exceptions\InvalidOtpException;
use App\Domain\Identity\Exceptions\UserBlockedException;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\PhoneNumber;
use App\Domain\Wallet\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Memverifikasi kode OTP, lalu masuk atau mendaftar.
 *
 * ============================================================================
 *  LOGIN DAN REGISTRASI ADALAH SATU ALUR
 * ============================================================================
 *  Nomor yang belum terdaftar mendapat akun baru; yang sudah terdaftar masuk ke
 *  akunnya. Aplikasi tidak perlu tahu mana yang terjadi sebelum mengirim kode,
 *  dan itu yang menutup celah pemeriksaan keanggotaan di RequestOtp.
 *
 *  Nama pengguna baru diisi belakangan lewat endpoint profil. Menuntut nama di
 *  langkah ini berarti satu layar tambahan sebelum orang bisa memesan, dan
 *  angka yang terlihat adalah orang yang berhenti di layar itu.
 * ============================================================================
 *
 * ============================================================================
 *  PENGHITUNG PERCOBAAN DINAIKKAN SEBELUM KODENYA DIPERIKSA
 * ============================================================================
 *  Urutannya begitu supaya percobaan tetap terhitung walaupun proses mati di
 *  tengah pemeriksaan. Kalau dinaikkan setelah, penyerang yang memutus koneksi
 *  tepat setelah request terkirim bisa mencoba tanpa batas: setiap percobaan
 *  memeriksa kode tapi tidak pernah menaikkan penghitungnya.
 *
 *  Konsekuensinya pengguna yang jaringannya terputus kehilangan satu percobaan.
 *  Dari lima, itu harga yang jauh lebih murah daripada batas yang bisa
 *  dilewati.
 * ============================================================================
 */
class VerifyOtp
{
    public function handle(
        string $rawPhone,
        string $code,
        string $purpose = 'login',
        ?string $deviceId = null,
        ?string $platform = null,
        ?string $fcmToken = null,
        ?string $appVersion = null,
    ): AuthenticatedSession {
        $phone = PhoneNumber::normalize($rawPhone);

        $otp = $this->claimOtp($phone, $purpose);

        if (! Hash::check($code, $otp->code_hash)) {
            throw InvalidOtpException::wrongCode(
                remainingAttempts: max(0, $this->maxAttempts() - (int) $otp->attempts),
            );
        }

        DB::table('otp_requests')->where('id', $otp->id)->update(['consumed_at' => now()]);

        return DB::transaction(function () use ($phone, $deviceId, $platform, $fcmToken, $appVersion): AuthenticatedSession {
            [$user, $isNew] = $this->findOrCreateUser($phone);

            $this->assertNotBlocked($user);

            if ($deviceId !== null) {
                $this->rememberDevice($user, $deviceId, $platform, $fcmToken, $appVersion);
            }

            /*
             * Token lama TIDAK dicabut.
             *
             * Satu orang wajar memakai dua perangkat — HP utama dan HP kedua —
             * dan mencabut token lama setiap kali masuk berarti perangkat
             * pertama tiba-tiba keluar tanpa penjelasan. Pencabutan menyeluruh
             * ada di endpoint "keluar dari semua perangkat", di mana penggunanya
             * memang meminta itu.
             */
            $token = $user->createToken(
                name: $deviceId ?? 'mobile',
                abilities: ['*'],
            );

            return new AuthenticatedSession(
                user: $user,
                token: $token->plainTextToken,
                isNewUser: $isNew,
            );
        });
    }

    // -------------------------------------------------------------------------

    /**
     * Ambil OTP yang berlaku, naikkan penghitungnya, dan kunci barisnya.
     */
    private function claimOtp(string $phone, string $purpose): object
    {
        return DB::transaction(function () use ($phone, $purpose): object {
            /*
             * lockForUpdate supaya dua request bersamaan dengan kode berbeda
             * tidak sama-sama membaca `attempts` yang sama.
             *
             * Tanpa lock, penyerang yang mengirim sepuluh request bersamaan akan
             * membuat kesepuluhnya membaca attempts = 0, dan batas lima
             * percobaan berhenti berarti apa pun.
             */
            $otp = DB::table('otp_requests')
                ->where('phone', $phone)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();

            if ($otp === null) {
                throw InvalidOtpException::notRequested();
            }

            if (now()->greaterThan($otp->expires_at)) {
                throw InvalidOtpException::expired();
            }

            if ((int) $otp->attempts >= $this->maxAttempts()) {
                throw InvalidOtpException::tooManyAttempts();
            }

            // Dinaikkan SEBELUM kodenya diperiksa. Lihat docblock kelas.
            DB::table('otp_requests')->where('id', $otp->id)->increment('attempts');

            $otp->attempts = (int) $otp->attempts + 1;

            return $otp;
        });
    }

    /**
     * @return array{User, bool}
     */
    private function findOrCreateUser(string $phone): array
    {
        /** @var User|null $user */
        $user = User::query()->where('phone', $phone)->first();

        if ($user !== null) {
            if ($user->phone_verified_at === null) {
                // Pendaftaran yang dulu berhenti sebelum OTP; sekarang selesai.
                $user->phone_verified_at = now();
                $user->save();
            }

            return [$user, false];
        }

        $user = User::create([
            'phone' => $phone,

            // Nama sementara dari empat digit terakhir. Diisi pengguna nanti di
            // halaman profil. Kolomnya NOT NULL, dan mengisinya dengan string
            // kosong akan membuat setiap daftar order menampilkan baris tanpa
            // nama yang tidak bisa dibedakan satu dari yang lain.
            'name' => 'Pengguna '.substr($phone, -4),

            'status' => 'active',
            'phone_verified_at' => now(),
            'referral_code' => $this->generateReferralCode(),
        ]);

        /*
         * Dompet dibuat sekarang, bukan saat top up pertama.
         *
         * Alasannya: setiap tempat yang membaca saldo harus bisa mengasumsikan
         * barisnya ada. Kalau dibuat belakangan, ada jalur yang membaca saldo
         * pengguna yang belum pernah top up dan mendapat null — dan penanganan
         * null itu akan lupa di satu tempat.
         */
        Wallet::forOwner('user', (int) $user->getKey());

        return [$user, true];
    }

    private function assertNotBlocked(User $user): void
    {
        if ($user->status->value === 'active') {
            return;
        }

        throw UserBlockedException::becauseStatus($user->status->value);
    }

    private function rememberDevice(
        User $user,
        string $deviceId,
        ?string $platform,
        ?string $fcmToken,
        ?string $appVersion,
    ): void {
        DB::table('user_devices')->updateOrInsert(
            [
                'user_id' => $user->getKey(),
                'device_id' => $deviceId,
            ],
            [
                'platform' => $platform ?? 'unknown',
                'fcm_token' => $fcmToken,
                'app_version' => $appVersion,
                'is_rooted' => false,
                'last_active_at' => now(),

                // Perangkat yang pernah dicabut lalu masuk lagi dengan OTP yang
                // sah adalah pemilik akun yang sama. Membiarkan revoked_at
                // terisi berarti dia tidak akan menerima notifikasi apa pun
                // tanpa satu pun error yang menjelaskan kenapa.
                'revoked_at' => null,

                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    /**
     * Kode referral yang tidak ambigu saat dibacakan.
     *
     * Huruf I, O, dan angka 0, 1 dibuang: kode referral dibagikan lewat suara
     * dan tulisan tangan, dan "I" versus "1" adalah kesalahan yang paling sering
     * membuat orang menyimpulkan kodenya tidak berlaku.
     */
    private function generateReferralCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        do {
            $code = '';

            for ($i = 0; $i < 8; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (User::query()->where('referral_code', $code)->exists());

        return $code;
    }

    private function maxAttempts(): int
    {
        return (int) config('antaride.otp.max_attempts', 5);
    }
}
