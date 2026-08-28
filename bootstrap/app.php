<?php

use App\Http\Middleware\EnforceAdminSessionTimeout;
use App\Http\Middleware\EnsureAdminIpAllowed;
use App\Http\Middleware\EnsureAdminTwoFactorEnabled;
use App\Http\Middleware\EnsureIdempotency;
use App\Http\Middleware\EnsureNotImpersonating;
use App\Http\Middleware\LogAdminActivity;
use App\Http\Middleware\ResolveApiLocale;
use App\Http\Responses\ApiExceptionRenderer;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))

    /*
    |--------------------------------------------------------------------------
    | Routing
    |--------------------------------------------------------------------------
    |
    | Empat entry point yang dipisah sengaja, karena karakter dan permukaan
    | serangannya berbeda jauh:
    |
    |   api_v1.php    API mobile. Stateless, token Sanctum, tanpa session.
    |   admin.php     panel backoffice. Session cookie, guard 'admin', CSRF.
    |   webhook.php   callback payment gateway. Tanpa CSRF, verifikasi tanda
    |                 tangan, allowlist IP provider.
    |   web.php       hanya redirect ke panel admin.
    |
    | Di produksi admin dan api hidup di subdomain terpisah (ADMIN_DOMAIN /
    | API_DOMAIN). Di lokal keduanya null, jadi admin memakai prefix /admin.
    | Pemisahan subdomain memungkinkan Nginx memberi lapisan tambahan pada
    | admin yang tidak dipakai API publik: allowlist IP di level web server,
    | basic auth untuk staging, dan header keamanan yang lebih ketat.
    |
    */
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // --- API mobile ---
            Route::middleware('api')
                ->domain(config('antaride.routing.api_domain'))
                ->prefix('api/v1')
                ->as('api.v1.')
                ->group(base_path('routes/api_v1.php'));

            // --- Webhook payment gateway ---
            Route::middleware('webhook')
                ->prefix('webhooks')
                ->as('webhooks.')
                ->group(base_path('routes/webhook.php'));

            // --- Panel admin ---
            Route::middleware('admin')
                ->domain(config('antaride.routing.admin_domain'))
                ->prefix(config('antaride.routing.admin_prefix'))
                ->as('admin.')
                ->group(base_path('routes/admin.php'));

            // --- Web publik (redirect saja) ---
            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        },
    )

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    */
    ->withMiddleware(function (Middleware $middleware): void {

        /*
        |----------------------------------------------------------------------
        | Proxy tepercaya — INI YANG MENJAGA SUBFOLDER TIDAK HILANG
        |----------------------------------------------------------------------
        |
        | Di produksi aplikasi ini berjalan di belakang Nginx, dan bisa berada di
        | SUBFOLDER: `https://domain.com/antaride/`. Octane sendiri mendengarkan
        | di `127.0.0.1:8000` tanpa tahu apa pun soal subfolder itu.
        |
        | Yang terjadi tanpa konfigurasi ini, dan ketiganya senyap:
        |
        |   SUBFOLDER HILANG
        |     Nginx meneruskan `/antaride/admin/login` sebagai `/admin/login`.
        |     Laravel melihat path tanpa prefix, jadi `route('admin.login')`
        |     menghasilkan `/admin/login` — tanpa subfolder. Setiap link, setiap
        |     redirect, setiap `asset()` menunjuk ke luar subfolder, dan yang
        |     ditemukan pengguna adalah 404 dari web server.
        |
        |     Yang menyelesaikannya `X-Forwarded-Prefix`. Nginx mengirimkannya,
        |     dan Symfony memakainya sebagai base URL — PER REQUEST, jadi aman
        |     untuk worker Octane yang hidup lama. `URL::forceRootUrl()` juga
        |     bekerja, tapi dia state global: satu request yang menyetelnya akan
        |     mempengaruhi request berikutnya di worker yang sama.
        |
        |   HTTPS TERBACA SEBAGAI HTTP
        |     Nginx yang memegang TLS; ke Octane dia bicara HTTP biasa. Tanpa
        |     `X-Forwarded-Proto`, `url()` menghasilkan `http://` di server yang
        |     HTTPS. Browser modern memblokir sebagian isinya sebagai mixed
        |     content, dan yang paling terlihat: form login yang tidak bisa
        |     dikirim.
        |
        |   SETIAP PENGGUNA BER-IP 127.0.0.1
        |     Rate limit OTP, allowlist IP admin, dan `admin_login_attempts`
        |     semuanya membaca `$request->ip()`. Tanpa `X-Forwarded-For`,
        |     semuanya melihat IP Nginx. Akibatnya: rate limit OTP menjadi rate
        |     limit GLOBAL — satu orang yang meminta OTP berulang memblokir
        |     SELURUH pengguna, dan allowlist IP admin memblokir semua orang atau
        |     tidak memblokir siapa pun.
        |
        |----------------------------------------------------------------------
        | KENAPA BUKAN `at: '*'`
        |----------------------------------------------------------------------
        |
        | Header di atas semuanya BISA DIPALSUKAN client. Mempercayai proxy mana
        | pun berarti siapa saja bisa:
        |
        |   * mengirim `X-Forwarded-For` palsu untuk melewati rate limit dan
        |     allowlist IP admin,
        |   * mengirim `X-Forwarded-Host` palsu sehingga tautan reset password
        |     yang dikirim ke email korban menunjuk ke domain penyerang.
        |
        | Nginx berada di host yang SAMA dengan Octane, jadi bawaannya
        | `127.0.0.1,::1` — dan hanya itu. Kalau nanti ada load balancer atau
        | Cloudflare di depannya, IP-nya ditambahkan lewat `TRUSTED_PROXIES` di
        | `.env`, bukan diganti `*`.
        |
        */
        $middleware->trustProxies(
            at: array_filter(array_map(
                'trim',
                explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1,::1')),
            )),

            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO

                /*
                 * Yang TIDAK ada di bawaan Laravel, dan yang paling penting di
                 * sini. Tanpa baris ini seluruh URL kehilangan subfolder-nya —
                 * lihat penjelasan di atas.
                 */
                | Request::HEADER_X_FORWARDED_PREFIX,
        );

        /*
        |----------------------------------------------------------------------
        | Grup 'admin'
        |----------------------------------------------------------------------
        |
        | Session, CSRF, dan tiga lapisan khusus admin yang tidak dimiliki
        | grup lain. Urutannya penting: timeout sesi dicek sebelum apa pun,
        | lalu allowlist IP, lalu 2FA, dan pencatatan aktivitas paling akhir
        | supaya yang tercatat adalah request yang benar-benar lolos.
        |
        */
        $middleware->group('admin', [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            AuthenticateSession::class,
            ShareErrorsFromSession::class,
            ValidateCsrfToken::class,
            SubstituteBindings::class,
            EnforceAdminSessionTimeout::class,
            EnsureAdminIpAllowed::class,
            EnsureAdminTwoFactorEnabled::class,
            LogAdminActivity::class,
        ]);

        /*
        |----------------------------------------------------------------------
        | Ke mana tamu diarahkan
        |----------------------------------------------------------------------
        |
        | Laravel secara bawaan mengarahkan request tak terautentikasi ke
        | `route('login')` — nama route yang TIDAK ada di proyek ini. Route panel
        | admin bernama `admin.login`, dan API tidak punya halaman login sama
        | sekali karena dia stateless.
        |
        | Tanpa ini, setiap request admin tanpa sesi gagal dengan
        | RouteNotFoundException — halaman 500 alih-alih halaman masuk. Yang
        | membuatnya mudah terlewat: selama sesi masih hidup, panelnya bekerja
        | sempurna. Yang melihat error itu adalah orang yang sesinya baru habis.
        |
        | Request yang mengharapkan JSON dikembalikan null supaya
        | ApiExceptionRenderer yang menanganinya menjadi 401 berbentuk JSON,
        | bukan redirect ke halaman HTML.
        |
        */
        $middleware->redirectGuestsTo(function (Request $request): ?string {
            if ($request->expectsJson() || $request->is('api/*')) {
                return null;
            }

            return route('admin.login');
        });

        /*
        |----------------------------------------------------------------------
        | Grup 'webhook'
        |----------------------------------------------------------------------
        |
        | Tanpa session dan tanpa CSRF. Verifikasi tanda tangan dilakukan di
        | controller masing-masing provider, karena tiap provider punya skema
        | tanda tangan sendiri dan tidak ada gunanya diseragamkan paksa.
        |
        */
        $middleware->group('webhook', [
            SubstituteBindings::class,
        ]);

        /*
        |----------------------------------------------------------------------
        | Grup 'api'
        |----------------------------------------------------------------------
        */
        $middleware->group('api', [
            SubstituteBindings::class,
            ResolveApiLocale::class,
        ]);

        /*
        |----------------------------------------------------------------------
        | Alias
        |----------------------------------------------------------------------
        */
        $middleware->alias([
            // Spatie RBAC (guard admin)
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,

            // Sanctum ability: menandai token diterbitkan untuk app mana
            // (customer / driver / merchant), supaya token app customer tidak
            // bisa dipakai memanggil endpoint driver.
            'ability' => CheckAbilities::class,
            'ability_any' => CheckForAnyAbility::class,

            // Idempotency untuk semua endpoint yang membuat uang bergerak.
            'idempotency' => EnsureIdempotency::class,

            // Sesi impersonasi bersifat read-only. Middleware ini menolak
            // request yang mengubah data selama impersonasi berjalan.
            'not_impersonating' => EnsureNotImpersonating::class,
        ]);

        // Webhook tidak boleh kena CSRF. Route-nya sudah di grup sendiri tanpa
        // ValidateCsrfToken, tapi ini jaring kedua kalau nanti ada yang
        // memindahkan route ke grup 'web' tanpa sadar.
        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
        ]);
    })

    /*
    |--------------------------------------------------------------------------
    | Exception
    |--------------------------------------------------------------------------
    |
    | Rendering ditangani oleh App\Http\Responses\ApiExceptionRenderer supaya
    | seluruh error API keluar dalam satu bentuk yang sama, lengkap dengan
    | error.code yang mesin-readable. App tidak boleh mencocokkan pesan teks
    | untuk membedakan kasus.
    |
    */
    ->withExceptions(function (Exceptions $exceptions): void {
        ApiExceptionRenderer::register($exceptions);
    })

    ->create();
