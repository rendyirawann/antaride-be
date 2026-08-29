<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\DTOs\AuthenticatedSession;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Masuk sebagai akun demo, tanpa OTP.
 *
 * ============================================================================
 *  INI PINTU BELAKANG, DAN DIPERLAKUKAN SEPERTI PINTU BELAKANG
 * ============================================================================
 *  Yang dilakukan Action ini persis yang dilakukan penyerang kalau dia bisa:
 *  menerbitkan token untuk sebuah akun tanpa membuktikan apa pun.
 *
 *  Itu memang gunanya — penguji harus bisa masuk tanpa menunggu OTP yang, di
 *  proyek ini, TIDAK dikirim ke mana pun (`SMS_DRIVER=log`). Tapi kemampuan itu
 *  harus dibatasi tiga lapis, dan ketiganya harus dilewati sekaligus:
 *
 *    1. FITURNYA MATI SECARA BAWAAN.
 *       `ANTARIDE_DEMO_LOGIN` harus bernilai true secara eksplisit. Server yang
 *       lupa menyetelnya menolak — bukan mengizinkan.
 *
 *    2. HANYA AKUN BERTANDA `demo_role`.
 *       Akun sungguhan tidak bisa dimasuki lewat sini, bahkan kalau nomornya
 *       diketahui. Yang bisa dimasuki hanya akun yang memang dibuat untuk
 *       ditembus.
 *
 *    3. SETIAP PEMAKAIAN DICATAT.
 *       Kalau nanti ada yang bertanya "kenapa akun ini melakukan itu", jawabannya
 *       harus ada di log — termasuk IP-nya.
 *
 *  Yang TIDAK boleh terjadi: fitur ini menyala di server yang memuat data
 *  pengguna sungguhan. Bahkan dengan tiga lapis di atas, akun demo tetap punya
 *  akses ke API yang sama — dan API itu bisa membuat order, memindahkan saldo
 *  demo, dan membaca katalog.
 * ============================================================================
 */
final readonly class DemoLogin
{
    /**
     * @param  string  $uuid  uuid user demo yang dipilih.
     * @param  string|null  $deviceId  penanda perangkat, untuk nama token.
     */
    public function handle(
        string $uuid,
        ?string $deviceId = null,
        ?string $ip = null,
    ): AuthenticatedSession {
        $this->pastikanAktif();

        $user = User::query()
            ->whereNotNull('demo_role')
            ->where('uuid', $uuid)
            ->first();

        if ($user === null) {
            /*
             * 404, bukan 403.
             *
             * Membedakan "akun ini bukan akun demo" dari "akun ini tidak ada"
             * memberi tahu penanya bahwa nomor itu terdaftar — dan endpoint ini
             * tidak diautentikasi, jadi siapa pun bisa memakainya untuk
             * memetakan akun yang ada.
             */
            throw new HttpException(404, 'Akun demo tidak ditemukan.');
        }

        /*
         * Akun yang ditangguhkan tetap ditolak, walaupun dia akun demo. Kalau
         * tidak, akun demo menjadi cara memutari penangguhan — dan jalur demo
         * yang aturannya berbeda dari jalur sungguhan membuat pengujiannya
         * tidak berarti.
         *
         * `status` di-cast menjadi enum `UserStatus`, BUKAN string.
         *
         * Membandingkannya dengan `'active'` selalu bernilai tidak sama — jadi
         * SETIAP akun demo ditolak, termasuk yang aktif. Ini tertangkap saat
         * mencobanya lewat HTTP; analyzer tidak menandainya, karena
         * membandingkan enum dengan string memang sah secara tipe.
         */
        if ($user->status !== UserStatus::Active) {
            throw new HttpException(403, 'Akun demo ini sedang tidak aktif.');
        }

        Log::channel('demo')->info('Masuk lewat akun demo', [
            'user_uuid' => (string) $user->uuid,
            'demo_role' => (string) $user->demo_role,
            'ip' => $ip,
            'device_id' => $deviceId,
        ]);

        $token = $user->createToken(
            name: $deviceId ?? 'demo',

            /*
             * Kemampuan token SAMA dengan token biasa.
             *
             * Token demo yang lebih terbatas akan membuat penguji menemui
             * penolakan yang tidak akan pernah dialami pengguna sungguhan —
             * dan dia akan melaporkannya sebagai bug yang tidak ada.
             */
            abilities: ['*'],
        );

        return new AuthenticatedSession(
            user: $user,
            token: $token->plainTextToken,

            // Akun demo tidak pernah "baru": dia sudah punya riwayat, saldo, dan
            // dokumen. Menandainya baru akan memicu alur perkenalan di aplikasi.
            isNewUser: false,
        );
    }

    /**
     * Daftar akun demo untuk satu aplikasi.
     *
     * @return Collection<int, User>
     */
    public function daftar(string $role): Collection
    {
        $this->pastikanAktif();

        return User::query()
            ->where('demo_role', $role)
            ->where('status', UserStatus::Active)
            ->orderBy('demo_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Apakah fitur akun demo menyala.
     *
     * Dipakai controller untuk menjawab daftar kosong alih-alih 404 — aplikasi
     * yang menerima daftar kosong cukup menyembunyikan bagiannya, sementara 404
     * akan tampil sebagai galat di layar masuk.
     */
    public function aktif(): bool
    {
        return (bool) config('antaride.demo.enabled', false);
    }

    private function pastikanAktif(): void
    {
        if ($this->aktif()) {
            return;
        }

        /*
         * 404, bukan 403.
         *
         * Fitur yang mati sebaiknya tidak mengaku ada. 403 memberi tahu bahwa
         * endpoint-nya nyata dan hanya sedang dimatikan — informasi yang hanya
         * berguna bagi orang yang mencari cara menyalakannya.
         */
        throw new HttpException(404);
    }
}
