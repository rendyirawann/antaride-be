<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backend\Auth;

use App\Domain\Identity\Models\Admin;
use App\Domain\Support\Models\AuditLog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backend\Auth\AdminLoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Masuk ke panel admin.
 *
 * ============================================================================
 *  LIMA LAPIS, DAN SEMUANYA SENGAJA MEREPOTKAN
 * ============================================================================
 *  Panel admin adalah target bernilai tertinggi di seluruh sistem: satu akun
 *  ops yang bobol bisa mengubah tarif seluruh kota atau menyetujui penarikan
 *  fiktif. Lapisannya:
 *
 *    1. Rate limit per EMAIL, bukan per IP
 *    2. Allowlist IP untuk role tertentu (middleware admin_ip)
 *    3. 2FA wajib (middleware admin_2fa)
 *    4. Timeout sesi lebih pendek dari aplikasi biasa
 *    5. Setiap upaya masuk dicatat, berhasil maupun gagal
 *
 *  Yang paling sering dilewatkan adalah nomor 5. Tanpa catatan upaya GAGAL,
 *  tidak ada cara mengetahui bahwa ada yang sedang mencoba menebak kata sandi —
 *  dan yang terlihat hanya akun yang tiba-tiba dipakai dari tempat yang tidak
 *  wajar, setelah kejadian.
 * ============================================================================
 */
class LoginController extends Controller
{
    public function showLoginForm(): View
    {
        return view('auth.admin-login');
    }

    public function login(AdminLoginRequest $request): RedirectResponse
    {
        $email = (string) $request->validated('email');

        $this->assertNotRateLimited($request, $email);

        /** @var Admin|null $admin */
        $admin = Admin::query()->where('email', $email)->first();

        if ($admin === null || ! Hash::check((string) $request->validated('password'), (string) $admin->password)) {
            RateLimiter::hit($this->throttleKey($email));

            $this->recordFailure($request, $email, $admin, 'kredensial salah');

            /*
             * Pesannya SAMA untuk email yang tidak ada dan kata sandi yang salah.
             *
             * Membedakannya memberi tahu penyerang email mana yang benar-benar
             * ada di sistem, dan itu setengah dari pekerjaannya. Daftar email
             * staf juga bukan rahasia yang sulit ditebak — pola
             * nama@perusahaan.com selalu bisa diduga — jadi satu-satunya yang
             * melindungi adalah tidak mengonfirmasi.
             */
            throw ValidationException::withMessages([
                'email' => 'Email atau kata sandi salah.',
            ]);
        }

        /*
         * Status akun diperiksa SETELAH kata sandi, bukan sebelum.
         *
         * Kalau diperiksa lebih dulu, pesan "akun ditangguhkan" muncul untuk
         * siapa pun yang mengetik email itu — tanpa perlu tahu kata sandinya.
         * Itu mengonfirmasi keberadaan akun DAN memberi tahu keadaannya.
         */
        if (! $admin->canAuthenticate()) {
            $this->recordFailure($request, $email, $admin, 'status akun '.$admin->status->value);

            throw ValidationException::withMessages([
                'email' => 'Akun Anda tidak bisa dipakai masuk. Hubungi superadmin.',
            ]);
        }

        RateLimiter::clear($this->throttleKey($email));

        Auth::guard('admin')->login($admin, remember: false);

        /*
         * Session ID diputar setelah login.
         *
         * Menutup session fixation: penyerang yang berhasil menanamkan session
         * ID pada browser korban sebelum dia login tidak akan bisa memakai ID
         * itu setelahnya. Laravel melakukannya otomatis di `Auth::login`, dan
         * dipanggil eksplisit di sini karena `regenerate` juga membuang seluruh
         * data sesi lama — termasuk sisa keadaan dari sesi sebelumnya.
         */
        $request->session()->regenerate();

        $admin->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        // IP dan user agent tidak perlu disebut: AuditLog::record membacanya
        // sendiri dari request. Menuliskannya lagi di sini berarti dua sumber
        // untuk data yang sama, dan salah satunya akan tertinggal.
        AuditLog::record(action: 'admin.login', auditable: $admin);

        /*
         * Tujuan setelah login: setup 2FA kalau belum aktif.
         *
         * Middleware `admin_2fa` juga akan mengarahkannya ke sana, tapi
         * mengarahkan langsung dari sini membuat perjalanannya satu langkah
         * lebih pendek dan pesannya lebih jelas.
         */
        if ($admin->requiresTwoFactor() && ! $admin->hasTwoFactorEnabled()) {
            return redirect()
                ->route('admin.two-factor.setup')
                ->with('warning', 'Aktifkan autentikasi dua faktor sebelum melanjutkan.');
        }

        return redirect()->intended(route('admin.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        $admin = Auth::guard('admin')->user();

        if ($admin !== null) {
            AuditLog::record(action: 'admin.logout', auditable: $admin);
        }

        Auth::guard('admin')->logout();

        /*
         * Sesi diinvalidasi DAN tokennya diputar.
         *
         * `logout()` sendiri hanya melepaskan pengguna dari guard; data sesinya
         * masih ada, termasuk token CSRF. Pada komputer bersama — yang di kantor
         * ops adalah keadaan normal — itu berarti sisa keadaan dari staf
         * sebelumnya masih terbawa.
         */
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')->with('success', 'Anda sudah keluar.');
    }

    // -------------------------------------------------------------------------

    /**
     * Rate limit dikunci per EMAIL, bukan per IP.
     *
     * Yang dilindungi adalah akun tertentu, dan penyerang yang serius memakai
     * banyak IP. Mengunci per IP berarti dia cukup berganti alamat setiap lima
     * percobaan; mengunci per email membuat percobaannya terbatas tanpa peduli
     * dari mana datangnya.
     *
     * Konsekuensi yang diterima: seseorang bisa mengunci akun orang lain dengan
     * sengaja mengirim kata sandi salah. Itu sebabnya lockout-nya 15 menit,
     * bukan permanen, dan superadmin bisa membukanya lebih cepat.
     */
    private function assertNotRateLimited(Request $request, string $email): void
    {
        $key = $this->throttleKey($email);
        $maxAttempts = (int) config('antaride.security.login_max_attempts', 5);

        if (! RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            return;
        }

        $seconds = RateLimiter::availableIn($key);
        $minutes = (int) ceil($seconds / 60);

        $this->recordFailure($request, $email, null, 'terkena rate limit');

        throw ValidationException::withMessages([
            'email' => "Terlalu banyak percobaan. Coba lagi dalam {$minutes} menit.",
        ]);
    }

    private function throttleKey(string $email): string
    {
        return 'admin-login:'.mb_strtolower($email);
    }

    /**
     * Catat upaya masuk yang gagal.
     *
     * Ditulis walaupun akunnya tidak ada. Justru upaya ke email yang tidak
     * terdaftar yang paling menunjukkan adanya percobaan sistematis — seseorang
     * sedang menebak pola alamat email staf.
     */
    private function recordFailure(
        Request $request,
        string $email,
        ?Admin $admin,
        string $reason,
    ): void {
        AuditLog::record(
            action: 'admin.login_failed',
            auditable: $admin,
            newValues: [
                // Email dicatat karena inilah yang diserang. Kata sandinya
                // TIDAK, dalam bentuk apa pun, termasuk panjangnya.
                'email' => $email,
                'reason' => $reason,
            ],
            adminId: $admin?->getKey(),
        );
    }
}
