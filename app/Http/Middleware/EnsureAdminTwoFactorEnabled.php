<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 2FA TOTP wajib, bukan opsional (blueprint admin bagian 3).
 *
 * ============================================================================
 *  DUA HAL YANG BERBEDA, DAN KEDUANYA DIPERIKSA
 * ============================================================================
 *    1. Apakah 2FA AKTIF untuk akun ini.
 *    2. Apakah SESI INI sudah melewatinya.
 *
 *  Versi pertama middleware ini hanya memeriksa nomor 1, dan itu membuat
 *  seluruh 2FA tidak berarti apa pun: admin yang sudah mengaktifkan 2FA tetap
 *  masuk hanya dengan email dan kata sandi, karena tidak ada satu pun tempat
 *  yang MENUNTUT kode keduanya.
 *
 *  Yang membuatnya sulit terlihat: panelnya berperilaku benar dari luar. Status
 *  di menu akun berbunyi "2FA aktif", halaman penyiapannya bekerja, QR-nya bisa
 *  dipindai, dan kode yang diketik saat penyiapan memang diverifikasi. Yang
 *  tidak pernah terjadi hanya satu: kodenya tidak pernah diminta lagi setelah
 *  itu.
 *
 *  Akibatnya kata sandi yang bocor kembali cukup untuk mengambil alih akun
 *  admin — yaitu persis satu-satunya hal yang 2FA ada untuk mencegah.
 * ============================================================================
 *
 * ============================================================================
 *  VERIFIKASI PUNYA MASA BERLAKU
 * ============================================================================
 *  Sesi admin bisa hidup berjam-jam. Verifikasi 2FA yang berlaku selama sesi
 *  itu berarti komputer ops yang ditinggalkan terbuka pagi hari masih bisa
 *  dipakai siapa pun sore hari, dan 2FA-nya sudah lewat.
 *
 *  Karena itu penandanya bercap waktu, dan kadaluarsa mengikuti
 *  `security.two_factor_ttl_minutes`. Nilainya lebih panjang dari timeout sesi
 *  supaya tidak menuntut kode dua kali dalam satu jam kerja, tapi cukup pendek
 *  untuk tidak berlaku sehari penuh.
 * ============================================================================
 */
class EnsureAdminTwoFactorEnabled
{
    /**
     * Route yang tetap boleh diakses tanpa 2FA aktif maupun terverifikasi.
     *
     * Semua route penyiapan, tantangan, dan keluar HARUS ada di sini. Satu yang
     * terlewat berarti admin terkurung tanpa jalan keluar — termasuk tidak bisa
     * keluar untuk mencoba lagi.
     *
     * @var array<int, string>
     */
    private const EXEMPT_ROUTES = [
        'admin.login',
        'admin.login.attempt',
        'admin.logout',
        'admin.two-factor.setup',
        'admin.two-factor.enable',
        'admin.two-factor.confirm',
        'admin.two-factor.challenge',
        'admin.two-factor.verify',
        'admin.two-factor.recovery-codes',
        'admin.password.confirm',
        'admin.password.confirm.store',
    ];

    /** Kunci sesi yang ditulis TwoFactorController setelah kode terverifikasi. */
    public const SESSION_KEY = 'admin_2fa_verified_at';

    public function handle(Request $request, Closure $next): Response
    {
        $admin = Auth::guard('admin')->user();

        if ($admin === null || $this->isExempt($request)) {
            return $next($request);
        }

        // --- Lapis 1: apakah 2FA harus aktif, dan sudah aktif? ---
        if ($admin->requiresTwoFactor() && ! $admin->hasTwoFactorEnabled()) {
            return $this->tolak(
                $request,
                'TWO_FACTOR_REQUIRED',
                'Akun Anda wajib mengaktifkan autentikasi dua faktor sebelum dapat digunakan.',
                route('admin.two-factor.setup'),
            );
        }

        // --- Lapis 2: apakah SESI ini sudah melewatinya? ---
        //
        // Hanya berlaku untuk akun yang 2FA-nya memang aktif. Akun yang tidak
        // diwajibkan dan tidak mengaktifkannya tidak punya kode untuk diminta.
        if ($admin->hasTwoFactorEnabled() && ! $this->sesiSudahTerverifikasi($request)) {
            return $this->tolak(
                $request,
                'TWO_FACTOR_CHALLENGE_REQUIRED',
                'Masukkan kode autentikasi dua faktor untuk melanjutkan.',
                route('admin.two-factor.challenge'),
            );
        }

        return $next($request);
    }

    // -------------------------------------------------------------------------

    private function sesiSudahTerverifikasi(Request $request): bool
    {
        $pada = $request->session()->get(self::SESSION_KEY);

        if (! is_int($pada) && ! is_numeric($pada)) {
            return false;
        }

        $ttlMinutes = (int) config('antaride.security.two_factor_ttl_minutes', 720);

        return (now()->timestamp - (int) $pada) < ($ttlMinutes * 60);
    }

    private function isExempt(Request $request): bool
    {
        $name = $request->route()?->getName();

        return $name !== null && in_array($name, self::EXEMPT_ROUTES, true);
    }

    private function tolak(
        Request $request,
        string $code,
        string $message,
        string $redirectTo,
    ): Response {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'error' => ['code' => $code, 'message' => $message],
            ], 403);
        }

        /*
         * `intended` disimpan supaya setelah 2FA, admin kembali ke halaman yang
         * dia tuju.
         *
         * Tanpa ini, staf finance yang membuka tautan langsung ke satu penarikan
         * akan dilempar ke dashboard setelah mengetik kodenya, dan harus mencari
         * baris itu lagi di antara dua puluh baris lain.
         */
        if ($request->isMethod('GET')) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return redirect()->to($redirectTo)->with('warning', $message);
    }
}
