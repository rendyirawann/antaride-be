<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\DemoController;
use App\Http\Controllers\Api\V1\Auth\OtpController;
use App\Http\Controllers\Api\V1\ConfigController;
use App\Http\Controllers\Api\V1\Customer\OrderController as CustomerOrderController;
use App\Http\Controllers\Api\V1\Customer\ProfileController;
use App\Http\Controllers\Api\V1\Customer\QuoteController;
use App\Http\Controllers\Api\V1\Customer\WalletController;
use App\Http\Controllers\Api\V1\Driver\DocumentController as DriverDocumentController;
use App\Http\Controllers\Api\V1\Driver\OrderController as DriverOrderController;
use App\Http\Controllers\Api\V1\Driver\StatusController as DriverStatusController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PlaceController;
use App\Http\Responses\ApiResponse;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| API v1 — Mobile
|------------------------------------------------------------------------------
|
| Dipakai oleh tiga aplikasi Flutter: customer, driver, dan merchant.
|
| Prinsip yang dipegang di seluruh file ini: MOBILE TIDAK DIPERCAYA SAMA SEKALI.
| Semua yang datang dari HP dianggap bisa dipalsukan. Konsekuensi praktisnya:
|
|   - Harga tidak pernah dikirim client. Client mengirim quote_id, backend
|     membaca harganya dari Redis.
|   - Idempotency wajib untuk setiap endpoint yang membuat uang bergerak.
|   - Rate limit berbeda per endpoint, bukan satu angka global.
|   - Kepemilikan diperiksa di setiap pembacaan, bukan diandalkan pada UUID
|     yang sulit ditebak.
|
| Struktur route mengikuti struktur controller: Auth, Customer, Driver, Merchant.
|
*/

// -----------------------------------------------------------------------------
// Kesehatan sistem
// -----------------------------------------------------------------------------

Route::get('/ping', fn () => ApiResponse::success([
    'service' => 'antaride-api',
    'version' => 'v1',
    'time' => now()->toIso8601String(),
]))->name('ping');

// -----------------------------------------------------------------------------
// Auth — OTP nomor HP
// -----------------------------------------------------------------------------
//
// Rate limit di sini paling ketat di seluruh API. Endpoint OTP adalah pintu
// masuk favorit untuk SMS bombing (yang biayanya kita tanggung) dan enumerasi
// nomor terdaftar.
//
// Rate limit di route berlaku per IP. Jeda per NOMOR ditegakkan di dalam Action,
// karena berganti IP jauh lebih mudah daripada berganti nomor HP.
//
Route::prefix('auth')->as('auth.')->group(function (): void {
    Route::post('/otp/request', [OtpController::class, 'request'])
        ->middleware('throttle:otp-request')
        ->name('otp.request');

    Route::post('/otp/verify', [OtpController::class, 'verify'])
        ->middleware('throttle:otp-verify')
        ->name('otp.verify');

    /*
     * ========================================================================
     *  AKUN DEMO — MASUK TANPA OTP
     * ========================================================================
     *  Ada karena OTP di proyek ini TIDAK dikirim ke mana pun: satu-satunya
     *  pengirim yang terpasang menulis kodenya ke berkas log, dan di produksi
     *  kode itu pun disembunyikan. Tanpa jalur ini, server yang sudah ter-deploy
     *  tidak bisa dimasuki siapa pun.
     *
     *  MATI secara bawaan (`ANTARIDE_DEMO_LOGIN`), dan hanya melayani akun yang
     *  bertanda `demo_role`. Lihat `DemoLogin` untuk ketiga lapis penjagaannya.
     *
     *  Throttle-nya memakai `otp-verify`, bukan limiter baru: keduanya endpoint
     *  yang menerbitkan token tanpa sesi sebelumnya, jadi batasnya memang harus
     *  sama. Limiter terpisah berarti dua angka yang harus dijaga sepakat.
     * ========================================================================
     */
    Route::get('/demo/accounts', [DemoController::class, 'index'])
        ->name('demo.accounts');

    Route::post('/demo/login', [DemoController::class, 'login'])
        ->middleware('throttle:otp-verify')
        ->name('demo.login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/logout', [OtpController::class, 'logout'])->name('logout');

        // Dipisah dari logout biasa karena dampaknya jauh berbeda: ini yang
        // dipakai saat HP hilang, dan pengguna harus memilihnya secara sadar.
        Route::post('/logout-all', [OtpController::class, 'logoutAll'])->name('logout.all');
    });
});

// -----------------------------------------------------------------------------
// Katalog publik
// -----------------------------------------------------------------------------
//
// Tanpa autentikasi: daftar layanan adalah informasi publik, dan aplikasi
// membutuhkannya di layar pertama sebelum pengguna masuk.
//
Route::get('/service-types', [QuoteController::class, 'serviceTypes'])
    ->name('service-types.index');

/*
 * Konfigurasi aplikasi: area layanan dan sakelar fitur.
 *
 * Tanpa autentikasi dengan alasan yang sama seperti service-types — aplikasi
 * membutuhkannya sebelum ada sesi, dan isinya bukan rahasia.
 */
Route::get('/config', [ConfigController::class, 'show'])->name('config.show');

// -----------------------------------------------------------------------------
// Customer
// -----------------------------------------------------------------------------

Route::middleware('auth:sanctum')->group(function (): void {

    // --- Profil ---
    Route::prefix('me')->as('me.')->group(function (): void {
        Route::get('/', [ProfileController::class, 'show'])->name('show');
        Route::patch('/', [ProfileController::class, 'update'])->name('update');

        Route::delete('/', [ProfileController::class, 'requestDeletion'])
            ->middleware('not_impersonating')
            ->name('deletion.request');

        Route::post('/restore', [ProfileController::class, 'cancelDeletion'])
            ->name('deletion.cancel');
    });

    /*
     * --- Pencarian alamat ---
     *
     * Di balik login: yang dijaga bukan datanya (alamat bukan rahasia)
     * melainkan kuota geocoder di belakangnya. Endpoint terbuka yang
     * meneruskan ke layanan berkuota adalah cara termudah membuat instans
     * Nominatim sendiri diblokir oleh lalu lintas orang lain.
     */
    Route::prefix('places')
        ->as('places.')
        ->middleware('throttle:places')
        ->group(function (): void {
            Route::get('/search', [PlaceController::class, 'search'])->name('search');
            Route::get('/reverse', [PlaceController::class, 'reverse'])->name('reverse');
        });

    // --- Estimasi harga ---
    //
    // Rate limit lebih longgar dari OTP tapi tetap ada: setiap quote memanggil
    // OSRM dan menghitung tarif untuk seluruh layanan, jadi biayanya nyata.
    // Aplikasi juga memanggilnya setiap kali penumpang menggeser pin peta.
    Route::prefix('quotes')->as('quotes.')->group(function (): void {
        Route::post('/', [QuoteController::class, 'store'])
            ->middleware('throttle:quotes')
            ->name('store');

        Route::get('/{quoteId}', [QuoteController::class, 'show'])->name('show');
    });

    // --- Order ---
    Route::prefix('orders')->as('orders.')->group(function (): void {
        Route::get('/', [CustomerOrderController::class, 'index'])->name('index');
        Route::get('/active', [CustomerOrderController::class, 'active'])->name('active');

        Route::get('/cancellation-reasons', [CustomerOrderController::class, 'cancellationReasons'])
            ->name('cancellation-reasons');

        /*
         * Pembuatan order WAJIB idempotent.
         *
         * Ini endpoint yang paling sering ditekan dua kali: penumpang di jaringan
         * buruk menekan "Pesan", tidak melihat respons, lalu menekan lagi. Tanpa
         * middleware ini, dia mendapat dua order — dan pada pembayaran wallet,
         * dananya ditahan dua kali.
         */
        Route::post('/', [CustomerOrderController::class, 'store'])
            ->middleware(['idempotency', 'throttle:orders'])
            ->name('store');

        Route::get('/{uuid}', [CustomerOrderController::class, 'show'])->name('show');

        Route::post('/{uuid}/cancel', [CustomerOrderController::class, 'cancel'])
            ->name('cancel');

        /*
         * Penilaian driver.
         *
         * TANPA middleware idempotency: penilaian tidak memindahkan uang, dan
         * penilaian ganda sudah dicegah unique index di database. Percobaan
         * kedua harus mendapat jawaban "sudah dinilai" — bukan putaran ulang
         * response pertama, yang akan menyembunyikan kenyataan itu dari
         * penumpang.
         */
        Route::post('/{uuid}/rating', [CustomerOrderController::class, 'rate'])
            ->middleware('throttle:orders')
            ->name('rating');
    });

    /*
     * --- Notifikasi in-app ---
     *
     * ========================================================================
     *  SATU SET ENDPOINT UNTUK PENUMPANG DAN DRIVER
     * ========================================================================
     *  Dibedakan lewat query `?as=driver`, bukan lewat prefix route terpisah.
     *
     *  Alasannya: satu orang bisa jadi penumpang DAN driver dengan akun yang
     *  sama, dan keduanya punya notifikasi sendiri. Route terpisah di bawah
     *  prefix `/driver` akan menyiratkan bahwa aksesnya ditentukan peran akun —
     *  padahal yang menentukan adalah dari APLIKASI mana request-nya datang.
     * ========================================================================
     */
    Route::prefix('notifications')->as('notifications.')->group(function (): void {
        Route::get('/', [NotificationController::class, 'index'])->name('index');

        /*
         * Endpoint tersendiri untuk jumlah yang belum dibaca.
         *
         * Aplikasi memanggilnya jauh lebih sering daripada daftarnya: lencana di
         * beranda diperbarui setiap kali aplikasi kembali ke depan, sementara
         * daftarnya hanya dibuka kalau loncengnya ditekan.
         *
         * Didaftarkan SEBELUM `/{uuid}/read` supaya `unread-count` tidak
         * tertangkap sebagai uuid.
         */
        Route::get('/unread-count', [NotificationController::class, 'unreadCount'])
            ->name('unread-count');

        Route::post('/read-all', [NotificationController::class, 'markAllRead'])
            ->name('read-all');

        Route::post('/{uuid}/read', [NotificationController::class, 'markRead'])
            ->name('read');
    });

    // --- Dompet ---
    Route::prefix('wallet')->as('wallet.')->group(function (): void {
        Route::get('/', [WalletController::class, 'show'])->name('show');
        Route::get('/transactions', [WalletController::class, 'transactions'])->name('transactions');
    });

    // -------------------------------------------------------------------------
    // Driver
    // -------------------------------------------------------------------------
    //
    // Berada di dalam grup `auth:sanctum` yang sama karena driver adalah
    // pengguna biasa yang punya baris di tabel `drivers`. Yang memeriksa apakah
    // dia benar-benar driver adalah controller, lewat `driver()`.
    //
    // Kenapa TIDAK memakai guard terpisah: satu orang bisa jadi penumpang dan
    // driver sekaligus, dan itu wajar — driver memesan ojek saat kendaraannya
    // di bengkel. Guard terpisah akan memaksanya punya dua akun.
    //
    Route::prefix('driver')->as('driver.')->group(function (): void {

        // --- Status kerja ---
        Route::get('/status', [DriverStatusController::class, 'show'])->name('status');

        Route::post('/online', [DriverStatusController::class, 'goOnline'])
            ->middleware('throttle:driver-status')
            ->name('online');

        Route::post('/offline', [DriverStatusController::class, 'goOffline'])
            ->middleware('throttle:driver-status')
            ->name('offline');

        Route::get('/services', [DriverStatusController::class, 'services'])->name('services');

        /*
         * ====================================================================
         *  DOKUMEN KYC — TANPA INI TIDAK ADA DRIVER YANG BISA MULAI BEKERJA
         * ====================================================================
         *  Tabel, panel verifikasi admin, dan penolakan di `GoOnline` semuanya
         *  sudah ada sejak awal. Yang tidak ada: cara driver MENGIRIM dokumennya.
         *
         *  Sampai kedua route ini ada, satu-satunya jalan mendaftarkan driver
         *  adalah admin memasukkan barisnya langsung ke database.
         *
         *  TIDAK memakai middleware `idempotency`. Unggahan dokumen tidak
         *  memindahkan uang, dan jenis dokumennya unik per driver — unggahan yang
         *  terkirim dua kali karena koneksi buruk menghasilkan baris yang SAMA,
         *  bukan dua dokumen. Yang berubah hanya berkasnya, dan yang kedua
         *  memang yang dimaksud driver.
         * ====================================================================
         */
        Route::get('/documents', [DriverDocumentController::class, 'index'])
            ->name('documents.index');

        Route::post('/documents', [DriverDocumentController::class, 'store'])
            ->name('documents.store');

        Route::patch('/services/{code}', [DriverStatusController::class, 'toggleService'])
            ->name('services.toggle');

        // --- Order ---
        Route::prefix('orders')->as('orders.')->group(function (): void {
            Route::get('/offers', [DriverOrderController::class, 'offers'])->name('offers');
            Route::get('/active', [DriverOrderController::class, 'active'])->name('active');

            /*
             * Alasan pembatalan untuk DRIVER.
             *
             * Terpisah dari endpoint penumpang karena tabelnya disaring per
             * `actor_type`, dan validasi menolak kode milik aktor lain. Tanpa
             * endpoint ini, aplikasi driver harus menyimpan daftarnya sendiri —
             * dan daftar itu akan menyimpang dari tabel begitu admin menambah
             * satu alasan.
             *
             * DIDAFTARKAN SEBELUM route `/{uuid}/...` bukan karena urutan
             * berpengaruh di sini (prefiksnya berbeda), tapi supaya seluruh
             * endpoint yang tidak butuh uuid berkumpul di satu tempat.
             */
            Route::get('/cancellation-reasons', [DriverOrderController::class, 'cancellationReasons'])
                ->name('cancellation-reasons');

            /*
             * Accept TIDAK memakai middleware idempotency, dan itu disengaja.
             *
             * Idempotency menyimpan response request pertama dan memutarnya
             * ulang. Untuk accept, itu SALAH: driver yang menekan dua kali dan
             * kalah balapan di percobaan pertama harus mendapat 409, bukan
             * putaran ulang response sukses milik percobaan yang tidak pernah
             * berhasil.
             *
             * Yang menjaga accept dari eksekusi ganda adalah tiga lapis di
             * AcceptOrder — lock Redis, SELECT FOR UPDATE, dan partial unique
             * index — dan ketiganya memberi jawaban yang benar untuk setiap
             * percobaan, bukan jawaban yang diputar ulang.
             */
            Route::post('/{uuid}/accept', [DriverOrderController::class, 'accept'])
                ->middleware('throttle:driver-accept')
                ->name('accept');

            Route::post('/{uuid}/reject', [DriverOrderController::class, 'reject'])->name('reject');

            Route::patch('/{uuid}/status', [DriverOrderController::class, 'transition'])
                ->name('transition');

            Route::post('/{uuid}/start', [DriverOrderController::class, 'startTrip'])
                ->name('start');

            // Penyelesaian order memindahkan uang, jadi idempotent.
            Route::post('/{uuid}/complete', [DriverOrderController::class, 'complete'])
                ->middleware('idempotency')
                ->name('complete');

            Route::post('/{uuid}/cancel', [DriverOrderController::class, 'cancel'])
                ->name('cancel');
        });
    });
});
