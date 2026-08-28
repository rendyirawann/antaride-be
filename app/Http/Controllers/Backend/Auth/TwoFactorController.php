<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backend\Auth;

use App\Domain\Identity\Models\Admin;
use App\Domain\Support\Models\AuditLog;
use App\Http\Controllers\Controller;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use PragmaRX\Google2FA\Google2FA;

/**
 * Autentikasi dua faktor untuk akun admin.
 *
 * ============================================================================
 *  SECRET DIENKRIPSI, DAN KODE PEMULIHAN DI-HASH
 * ============================================================================
 *  Keduanya berbeda perlakuan, dan itu bukan ketidakkonsistenan:
 *
 *    two_factor_secret          DIENKRIPSI, karena harus bisa dibaca kembali
 *                               untuk memverifikasi setiap kode TOTP.
 *
 *    two_factor_recovery_codes  DI-HASH, karena hanya perlu dibandingkan, dan
 *                               setelah dipakai langsung dibuang.
 *
 *  Kalau kode pemulihan hanya dienkripsi, siapa pun yang bisa membaca database
 *  DAN memegang APP_KEY bisa memakainya untuk masuk. Di-hash membuat kunci
 *  aplikasi yang bocor tidak cukup.
 *
 *  Secret TOTP tidak punya pilihan itu — dia harus bisa dipulihkan — jadi yang
 *  melindunginya adalah enkripsi plus fakta bahwa APP_KEY tidak berada di
 *  database yang sama.
 * ============================================================================
 */
class TwoFactorController extends Controller
{
    public function __construct(
        private readonly Google2FA $google2fa,
    ) {}

    /**
     * Halaman penyiapan: QR code dan kunci manual.
     */
    public function setup(Request $request): View|RedirectResponse
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');

        if ($admin->hasTwoFactorEnabled()) {
            return redirect()
                ->route('admin.dashboard')
                ->with('info', '2FA sudah aktif untuk akun Anda.');
        }

        /*
         * Secret dibuat dan disimpan SEKARANG, sebelum dikonfirmasi.
         *
         * Alternatifnya menyimpannya di sesi sampai dikonfirmasi. Itu terdengar
         * lebih rapi dan justru lebih buruk: pengguna memindai QR dengan
         * aplikasi authenticator, sesinya habis sebelum dia mengetik kode, dan
         * yang tersimpan di HP-nya sekarang adalah secret yang tidak dikenali
         * server. Dia harus menghapus entri itu manual — dan sebagian besar
         * orang tidak tahu caranya, lalu punya dua entri Antaride yang
         * membingungkan.
         *
         * Yang membedakan "disiapkan" dari "aktif" adalah
         * `two_factor_confirmed_at`, bukan keberadaan secret-nya.
         */
        if ($admin->two_factor_secret === null) {
            $admin->forceFill([
                'two_factor_secret' => Crypt::encryptString($this->google2fa->generateSecretKey()),
                'two_factor_confirmed_at' => null,
            ])->save();
        }

        $secret = Crypt::decryptString((string) $admin->two_factor_secret);

        return view('backend.auth.two-factor-setup', [
            'qrSvg' => $this->qrCodeSvg($admin, $secret),

            // Kunci manual ditampilkan berkelompok empat karakter.
            //
            // Sebagian aplikasi authenticator tidak bisa memindai QR di layar
            // tertentu, dan yang tersisa adalah mengetik 32 karakter dari layar.
            // Tanpa pengelompokan, itu hampir selalu salah.
            'secretDikelompokkan' => trim(chunk_split($secret, 4, ' ')),
        ]);
    }

    /**
     * Konfirmasi penyiapan dengan satu kode yang benar.
     */
    public function confirm(Request $request): RedirectResponse
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');

        $request->validate([
            'code' => ['required', 'string', 'digits:6'],
        ], [
            'code.required' => 'Masukkan kode dari aplikasi authenticator.',
            'code.digits' => 'Kode harus 6 angka.',
        ]);

        if ($admin->two_factor_secret === null) {
            return redirect()->route('admin.two-factor.setup')
                ->with('error', 'Mulai penyiapan 2FA dari awal.');
        }

        $secret = Crypt::decryptString((string) $admin->two_factor_secret);

        if (! $this->google2fa->verifyKey($secret, (string) $request->input('code'))) {
            return back()->withErrors([
                'code' => 'Kode tidak cocok. Pastikan jam di HP Anda tepat.',
            ]);
        }

        $kodePemulihan = $this->generateRecoveryCodes();

        $admin->forceFill([
            'two_factor_recovery_codes' => Crypt::encryptString(json_encode(
                array_map(static fn (string $kode): string => Hash::make($kode), $kodePemulihan),
                JSON_THROW_ON_ERROR,
            )),
            'two_factor_confirmed_at' => now(),
        ])->save();

        AuditLog::record(action: 'admin.2fa_enabled', auditable: $admin);

        /*
         * Kode pemulihan dibawa lewat SESSION FLASH, bukan disimpan.
         *
         * Ini satu-satunya kesempatan menampilkannya — versi yang tersimpan
         * sudah di-hash dan tidak bisa dibaca lagi, oleh siapa pun, termasuk
         * superadmin. Halaman yang menampilkannya harus mengatakan itu dengan
         * jelas.
         */
        return redirect()
            ->route('admin.two-factor.recovery-codes')
            ->with('kodePemulihan', $kodePemulihan);
    }

    /**
     * Tampilkan kode pemulihan satu kali.
     */
    public function recoveryCodes(Request $request): View|RedirectResponse
    {
        $kode = session('kodePemulihan');

        if (! is_array($kode) || $kode === []) {
            return redirect()->route('admin.dashboard');
        }

        return view('backend.auth.two-factor-recovery', ['kodePemulihan' => $kode]);
    }

    /**
     * Halaman tantangan saat masuk.
     */
    public function challenge(): View
    {
        return view('backend.auth.two-factor-challenge');
    }

    /**
     * Verifikasi kode TOTP atau kode pemulihan.
     */
    public function verify(Request $request): RedirectResponse
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');

        $request->validate([
            // Panjangnya bebas karena field ini menerima DUA bentuk: kode TOTP
            // 6 angka, atau kode pemulihan. Aturan `digits:6` akan menolak kode
            // pemulihan yang sah — dan itu dipakai justru saat HP-nya hilang,
            // saat orangnya paling tidak punya jalan lain.
            'code' => ['required', 'string', 'max:64'],
        ], [
            'code.required' => 'Masukkan kode.',
        ]);

        $kode = trim((string) $request->input('code'));
        $secret = Crypt::decryptString((string) $admin->two_factor_secret);

        if ($this->google2fa->verifyKey($secret, $kode)) {
            $this->tandaiTerverifikasi($request);

            return redirect()->intended(route('admin.dashboard'));
        }

        if ($this->consumeRecoveryCode($admin, $kode)) {
            $this->tandaiTerverifikasi($request);

            AuditLog::record(action: 'admin.2fa_recovery_used', auditable: $admin);

            /*
             * Peringatan setelah memakai kode pemulihan.
             *
             * Kode pemulihan yang terpakai berarti authenticator-nya hilang, dan
             * yang harus dilakukan berikutnya adalah menyiapkan ulang 2FA. Tanpa
             * peringatan ini, orangnya akan terus memakai kode pemulihan sampai
             * habis — lalu terkunci total.
             */
            return redirect()
                ->route('admin.dashboard')
                ->with('warning', 'Anda masuk dengan kode pemulihan. Siapkan ulang 2FA sesegera mungkin.');
        }

        AuditLog::record(
            action: 'admin.2fa_failed',
            auditable: $admin,
            newValues: ['reason' => 'kode tidak cocok'],
        );

        return back()->withErrors(['code' => 'Kode tidak cocok.']);
    }

    /**
     * Terbitkan ulang kode pemulihan.
     */
    public function regenerateRecoveryCodes(Request $request): RedirectResponse
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');

        $kodePemulihan = $this->generateRecoveryCodes();

        $admin->forceFill([
            'two_factor_recovery_codes' => Crypt::encryptString(json_encode(
                array_map(static fn (string $kode): string => Hash::make($kode), $kodePemulihan),
                JSON_THROW_ON_ERROR,
            )),
        ])->save();

        AuditLog::record(action: 'admin.2fa_recovery_regenerated', auditable: $admin);

        return redirect()
            ->route('admin.two-factor.recovery-codes')
            ->with('kodePemulihan', $kodePemulihan);
    }

    // -------------------------------------------------------------------------

    private function tandaiTerverifikasi(Request $request): void
    {
        /*
         * Penanda disimpan di SESI, dengan cap waktu.
         *
         * Middleware EnsureAdminTwoFactorEnabled yang membacanya. Cap waktunya
         * ada supaya sesi yang sangat panjang tetap menuntut 2FA ulang — sesi
         * admin yang dibuka pagi dan masih terbuka malam hari sudah tidak lagi
         * membuktikan siapa yang memakainya.
         */
        $request->session()->put('admin_2fa_verified_at', now()->timestamp);
    }

    /**
     * @return array<int, string>
     */
    private function generateRecoveryCodes(): array
    {
        /*
         * Delapan kode, masing-masing dua kelompok lima karakter.
         *
         * Delapan dipilih karena kode pemulihan dipakai satu per satu dan
         * dibuang setelah terpakai: terlalu sedikit berarti orangnya terkunci
         * setelah beberapa kali ganti HP, terlalu banyak berarti daftarnya tidak
         * praktis dicetak dan disimpan.
         *
         * Alfabetnya membuang karakter yang ambigu saat ditulis tangan atau
         * dibacakan — kode pemulihan justru yang paling sering dicetak.
         */
        $alfabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $kode = [];

        for ($i = 0; $i < 8; $i++) {
            $bagian = [];

            for ($b = 0; $b < 2; $b++) {
                $potongan = '';

                for ($c = 0; $c < 5; $c++) {
                    $potongan .= $alfabet[random_int(0, strlen($alfabet) - 1)];
                }

                $bagian[] = $potongan;
            }

            $kode[] = implode('-', $bagian);
        }

        return $kode;
    }

    /**
     * Pakai satu kode pemulihan, lalu buang.
     */
    private function consumeRecoveryCode(Admin $admin, string $kode): bool
    {
        if ($admin->two_factor_recovery_codes === null) {
            return false;
        }

        try {
            $tersimpan = json_decode(
                Crypt::decryptString((string) $admin->two_factor_recovery_codes),
                associative: true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (\Throwable) {
            return false;
        }

        if (! is_array($tersimpan)) {
            return false;
        }

        foreach ($tersimpan as $indeks => $hash) {
            if (! Hash::check($kode, (string) $hash)) {
                continue;
            }

            /*
             * Kode yang terpakai DIBUANG, bukan ditandai.
             *
             * Kode pemulihan sekali pakai adalah seluruh gunanya: kalau
             * daftarnya bocor lewat foto atau catatan, kode yang sudah terpakai
             * tidak boleh bisa dipakai lagi.
             */
            unset($tersimpan[$indeks]);

            $admin->forceFill([
                'two_factor_recovery_codes' => Crypt::encryptString(
                    json_encode(array_values($tersimpan), JSON_THROW_ON_ERROR)
                ),
            ])->save();

            return true;
        }

        return false;
    }

    private function qrCodeSvg(Admin $admin, string $secret): string
    {
        $url = $this->google2fa->getQRCodeUrl(
            company: (string) config('antaride.brand.name', 'Antaride'),

            // Email dipakai sebagai label akun supaya staf yang punya beberapa
            // akun (misal di staging dan produksi) bisa membedakannya di daftar
            // aplikasi authenticator.
            holder: (string) $admin->email,

            secret: $secret,
        );

        $writer = new Writer(
            new ImageRenderer(new RendererStyle(220), new SvgImageBackEnd)
        );

        return $writer->writeString($url);
    }
}
