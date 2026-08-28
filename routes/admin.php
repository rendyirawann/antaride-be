<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\SurgeRule;
use App\Domain\Identity\Models\Admin;
use App\Domain\Merchant\Models\Merchant;
use App\Http\Controllers\Backend\AuditController;
use App\Http\Controllers\Backend\Auth\LoginController;
use App\Http\Controllers\Backend\Auth\TwoFactorController;
use App\Http\Controllers\Backend\DashboardController;
use App\Http\Controllers\Backend\DriverController;
use App\Http\Controllers\Backend\DriverVerificationController;
use App\Http\Controllers\Backend\FinanceController;
use App\Http\Controllers\Backend\LiveMapController;
use App\Http\Controllers\Backend\OrderController;
use App\Http\Controllers\Backend\PricingController;
use App\Http\Controllers\Backend\SettingsController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Panel Admin (Backoffice)
|------------------------------------------------------------------------------
|
| Dipakai tim ops, finance, CS, dan verifikator dokumen.
|
| Otorisasi ditegakkan DI SINI, di level route, dengan middleware `can:`.
| Menyembunyikan tombol di frontend itu semata soal kenyamanan; kalau
| penegakannya hanya di sana, endpointnya tetap terbuka bagi siapa pun yang
| tahu URL-nya. Itu pola bug yang paling sering muncul di panel admin yang
| dibangun cepat.
|
| Nama route sudah otomatis berprefix "admin." dari bootstrap/app.php.
|
*/

// -----------------------------------------------------------------------------
// Tamu — halaman masuk
// -----------------------------------------------------------------------------

Route::middleware('guest:admin')->group(function (): void {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');

    Route::post('/login', [LoginController::class, 'login'])
        ->middleware('throttle:admin-login')
        ->name('login.attempt');

    // Reset kata sandi belum diimplementasikan. Route-nya tetap ada karena
    // halaman login menautkannya, dan tautan mati lebih membingungkan daripada
    // halaman yang mengatakan fiturnya belum ada.
    Route::get('/forgot-password', fn () => view('backend.auth.password-placeholder'))
        ->name('password.request');
});

// -----------------------------------------------------------------------------
// Terautentikasi
// -----------------------------------------------------------------------------

Route::middleware('auth:admin')->group(function (): void {

    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    /*
    |--------------------------------------------------------------------------
    | Two Factor
    |--------------------------------------------------------------------------
    |
    | Route ini dikecualikan dari EnsureAdminTwoFactorEnabled — daftarnya ada di
    | konstanta EXEMPT_ROUTES middleware itu. Kalau tidak, admin yang belum
    | menyiapkan 2FA akan terkurung tanpa jalan keluar, termasuk tidak bisa
    | keluar untuk mencoba lagi.
    |
    | Setiap nama route baru di grup ini WAJIB ditambahkan ke daftar itu.
    |
    */
    Route::prefix('two-factor')->as('two-factor.')->group(function (): void {
        Route::get('/setup', [TwoFactorController::class, 'setup'])->name('setup');
        Route::post('/confirm', [TwoFactorController::class, 'confirm'])->name('confirm');

        Route::get('/challenge', [TwoFactorController::class, 'challenge'])->name('challenge');

        Route::post('/verify', [TwoFactorController::class, 'verify'])
            // Rate limit: kode 6 angka hanya punya sejuta kemungkinan, dan
            // tanpa batas percobaan itu bisa ditebak dalam hitungan jam.
            ->middleware('throttle:admin-login')
            ->name('verify');

        Route::get('/recovery-codes', [TwoFactorController::class, 'recoveryCodes'])
            ->name('recovery-codes');

        Route::post('/recovery-codes/regenerate', [TwoFactorController::class, 'regenerateRecoveryCodes'])
            ->name('recovery-codes.regenerate');
    });

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    */
    Route::get('/', [DashboardController::class, 'index'])
        ->middleware('can:dashboard.view')
        ->name('dashboard');

    /*
    |--------------------------------------------------------------------------
    | Order
    |--------------------------------------------------------------------------
    */
    Route::prefix('orders')->as('orders.')->middleware('can:orders.view')->group(function (): void {
        Route::get('/', [OrderController::class, 'index'])->name('index');

        // Endpoint data DataTables. Dipanggil sangat sering — setiap ketikan di
        // kolom pencarian — jadi tidak boleh menjalankan COUNT(*).
        Route::get('/data', [OrderController::class, 'data'])->name('data');

        Route::get('/{uuid}', [OrderController::class, 'show'])->name('show');

        /*
         * Intervensi order menuntut permission TERSENDIRI, bukan orders.view.
         *
         * Melihat order dan mengubahnya adalah dua hal yang sangat berbeda: CS
         * agent perlu yang pertama untuk menjawab telepon, dan tidak boleh punya
         * yang kedua. Menggabungkannya berarti setiap orang yang bisa membuka
         * daftar order juga bisa membatalkannya.
         */
        Route::post('/{uuid}/cancel', [OrderController::class, 'cancel'])
            ->middleware(['can:orders.cancel', 'not_impersonating'])
            ->name('cancel');

        Route::post('/{uuid}/force-assign', [OrderController::class, 'forceAssign'])
            ->middleware(['can:orders.force_assign', 'not_impersonating'])
            ->name('force-assign');

        Route::post('/{uuid}/retry-matching', [OrderController::class, 'retryMatching'])
            ->middleware(['can:orders.intervene', 'not_impersonating'])
            ->name('retry-matching');
    });

    /*
    |--------------------------------------------------------------------------
    | Live Map
    |--------------------------------------------------------------------------
    */
    Route::prefix('livemap')->as('livemap.')->middleware('can:orders.view')->group(function (): void {
        Route::get('/', [LiveMapController::class, 'index'])->name('index');
        Route::get('/data', [LiveMapController::class, 'data'])->name('data');
    });

    /*
    |--------------------------------------------------------------------------
    | Driver
    |--------------------------------------------------------------------------
    */
    Route::prefix('drivers')->as('drivers.')->group(function (): void {
        Route::get('/', [DriverController::class, 'index'])
            ->middleware('can:drivers.view')
            ->name('index');

        // Antrean verifikasi didaftarkan SEBELUM /{uuid}, kalau tidak
        // "verification" akan dibaca sebagai uuid.
        Route::get('/verification', [DriverVerificationController::class, 'index'])
            ->middleware('can:drivers.verify_document')
            ->name('verification');

        Route::get('/{uuid}', [DriverController::class, 'show'])
            ->middleware('can:drivers.view')
            ->name('show');

        Route::get('/{uuid}/verify', [DriverVerificationController::class, 'show'])
            ->middleware('can:drivers.verify_document')
            ->name('verify');

        Route::post('/{uuid}/suspend', [DriverController::class, 'suspend'])
            ->middleware(['can:drivers.suspend', 'not_impersonating'])
            ->name('suspend');

        Route::post('/{uuid}/reinstate', [DriverController::class, 'reinstate'])
            ->middleware(['can:drivers.suspend', 'not_impersonating'])
            ->name('reinstate');
    });

    Route::prefix('documents')->as('documents.')
        ->middleware('can:drivers.verify_document')
        ->group(function (): void {
            Route::post('/{document}/approve', [DriverVerificationController::class, 'approve'])
                ->middleware('not_impersonating')
                ->name('approve');

            Route::post('/{document}/reject', [DriverVerificationController::class, 'reject'])
                ->middleware('not_impersonating')
                ->name('reject');

            /*
             * Membuka berkas KYC menuntut permission TERPISAH.
             *
             * `drivers.verify_document` memberi hak menyetujui atau menolak;
             * `kyc.view_masked` memberi hak melihat berkasnya. Keduanya dipisah
             * karena sebagian staf perlu memeriksa kelengkapan tanpa perlu
             * melihat KTP-nya, dan setiap pembukaan berkas dicatat.
             */
            Route::get('/{document}/file', [DriverVerificationController::class, 'viewFile'])
                ->middleware('can:kyc.view_masked')
                ->name('file');
        });

    /*
    |--------------------------------------------------------------------------
    | Tarif
    |--------------------------------------------------------------------------
    */
    Route::prefix('pricing')->as('pricing.')->group(function (): void {
        Route::get('/', [PricingController::class, 'index'])
            ->middleware('can:pricing.view')
            ->name('index');

        /*
         * Simulator hanya butuh `pricing.view`, bukan `pricing.propose`.
         *
         * Menghitung dampak tarif tidak mengubah apa pun, dan justru harus bisa
         * dilakukan oleh orang yang TIDAK berwenang mengubah tarif — itu yang
         * membuat usulan perubahan bisa diperiksa sebelum diajukan.
         */
        Route::get('/simulator', [PricingController::class, 'simulator'])
            ->middleware('can:pricing.view')
            ->name('simulator');

        Route::get('/zones', [PricingController::class, 'zones'])
            ->middleware('can:pricing.manage_zones')
            ->name('zones');

        Route::get('/create', [PricingController::class, 'create'])
            ->middleware('can:pricing.propose')
            ->name('create');

        Route::post('/', [PricingController::class, 'store'])
            ->middleware(['can:pricing.propose', 'not_impersonating'])
            ->name('store');

        Route::post('/{id}/deactivate', [PricingController::class, 'deactivate'])
            ->middleware(['can:pricing.approve', 'not_impersonating'])
            ->name('deactivate');

        // Surge manual belum diimplementasikan; halamannya menunjukkan aturan
        // surge otomatis yang sedang berlaku.
        Route::get('/surge', fn () => view('backend.pricing.surge', [
            'aturan' => SurgeRule::query()
                ->with('zone')
                ->orderByDesc('is_active')
                ->get(),
        ]))->middleware('can:pricing.surge_manual')->name('surge');
    });

    /*
    |--------------------------------------------------------------------------
    | Keuangan
    |--------------------------------------------------------------------------
    */
    Route::prefix('finance')->as('finance.')->middleware('can:finance.view')->group(function (): void {
        Route::get('/withdrawals', [FinanceController::class, 'withdrawals'])
            ->middleware('can:finance.approve_withdrawal')
            ->name('withdrawals');

        Route::post('/withdrawals/{uuid}/approve', [FinanceController::class, 'approveWithdrawal'])
            ->middleware(['can:finance.approve_withdrawal', 'not_impersonating'])
            ->name('withdrawals.approve');

        Route::post('/withdrawals/{uuid}/reject', [FinanceController::class, 'rejectWithdrawal'])
            ->middleware(['can:finance.approve_withdrawal', 'not_impersonating'])
            ->name('withdrawals.reject');

        Route::get('/ledger', [FinanceController::class, 'ledger'])->name('ledger');

        Route::get('/reconciliation', [FinanceController::class, 'reconciliation'])
            ->middleware('can:finance.reconcile')
            ->name('reconciliation');
    });

    /*
    |--------------------------------------------------------------------------
    | Merchant
    |--------------------------------------------------------------------------
    |
    | Halaman daftar saja untuk sekarang. Pengelolaan menu dan komisi menunggu
    | vertikal food benar-benar dipakai — membangunnya sekarang berarti menebak
    | alur kerja yang belum ada.
    */
    Route::get('/merchants', fn () => view('backend.merchant.index', [
        'merchant' => Merchant::query()
            ->withCount('menuItems')
            ->orderBy('name')
            ->paginate(25),
    ]))->middleware('can:merchants.view')->name('merchants.index');

    /*
    |--------------------------------------------------------------------------
    | Sistem
    |--------------------------------------------------------------------------
    */
    Route::prefix('settings')->as('settings.')->group(function (): void {
        Route::get('/flags', [SettingsController::class, 'flags'])
            ->middleware('can:feature_flags.manage')
            ->name('flags');

        Route::patch('/flags/{key}', [SettingsController::class, 'toggleFlag'])
            ->middleware(['can:feature_flags.manage', 'not_impersonating'])
            ->name('flags.toggle');
    });

    Route::prefix('audit')->as('audit.')->middleware('can:audit.view')->group(function (): void {
        Route::get('/', [AuditController::class, 'index'])->name('index');
        Route::get('/{uuid}', [AuditController::class, 'show'])->name('show');
    });

    /*
    |--------------------------------------------------------------------------
    | Staf & Role
    |--------------------------------------------------------------------------
    */
    Route::get('/staff', fn () => view('backend.user_management.index', [
        'staf' => Admin::query()
            ->with('roles')
            ->orderBy('name')
            ->paginate(25),
    ]))->middleware('can:admin.manage')->name('staff.index');
});
