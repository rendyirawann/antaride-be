<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Driver\Models\Driver;
use App\Domain\Driver\Models\Vehicle;
use App\Domain\Identity\Models\User;
use App\Domain\Matching\Actions\DispatchOfferWave;
use App\Domain\Matching\Contracts\DriverLocationIndex;
use App\Domain\Ordering\Models\Order;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Support\Actions\SendNotification;
use App\Domain\Support\Models\Notification;
use App\Domain\Wallet\Actions\PostLedgerEntries;
use App\Domain\Wallet\DTOs\LedgerEntry;
use App\Domain\Wallet\Models\Wallet;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\SystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ============================================================================
 *  MENULIS FIXTURE KONTRAK UNTUK TEST FLUTTER
 * ============================================================================
 *  Test ini memanggil endpoint sungguhan lalu MENULIS response-nya ke
 *  `../antaride-fe/test_fixtures/`. Berkas itu dipakai test Dart sebagai
 *  masukan `fromJson`.
 *
 *  Kenapa begitu, dan bukan menulis JSON contoh di sisi Dart:
 *
 *    Fixture yang ditulis tangan hanya membuktikan bahwa parser Dart konsisten
 *    dengan apa yang PENULISNYA yakini soal bentuk API. Itu tepat jenis
 *    kesalahan yang sudah terjadi sekali di proyek ini: model Dart dibuat dengan
 *    asumsi `fare.total` sebagai objek Money bersarang, padahal endpoint quote
 *    mengirimnya rata sebagai `total_fare` dan `total_formatted`. Analyzer tidak
 *    bisa melihatnya, dan test dengan fixture tulisan tangan akan lulus.
 *
 *  Dengan fixture yang DIHASILKAN backend, perubahan bentuk response akan
 *  membuat test Dart gagal pada test run berikutnya — bukan pada penumpang.
 *
 *  Test ini juga tetap memeriksa isinya sendiri: fixture yang kosong atau
 *  kehilangan field kunci lebih buruk daripada tidak ada fixture, karena test
 *  Dart akan lulus tanpa menguji apa pun.
 * ============================================================================
 */
class ContractFixtureTest extends TestCase
{
    use RefreshDatabase;

    private const PICKUP = ['lat' => 3.5952, 'lng' => 98.6722];

    private const DEST = ['lat' => 3.6000, 'lng' => 98.6800];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSeeder::class);
        $this->seed(CatalogSeeder::class);

        Http::fake([
            '*/table/*' => Http::response(['code' => 'Ok', 'durations' => [[240.0]]], 200),
            '*/route/*' => Http::response([
                'code' => 'Ok',
                'routes' => [[
                    'distance' => 8000.0,
                    'duration' => 1200.0,
                    'geometry' => '_p~iF~ps|U_ulLnnqC_mqNvxq`@',
                ]],
            ], 200),
        ]);
    }

    public function test_menulis_fixture_quote(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/quotes', [
            'pickup' => self::PICKUP,
            'destination' => self::DEST,
            'service_codes' => ['ride_bike', 'ride_car'],
        ]);

        $response->assertOk();

        $data = $response->json('data');

        // Field yang dipakai `Quote.fromJson` di Flutter. Kalau salah satu
        // hilang, model Dart akan mengurainya sebagai nilai bawaan tanpa
        // memberi tahu — dan yang terlihat di layar adalah harga Rp 0.
        $this->assertArrayHasKey('quote_id', $data);
        $this->assertArrayHasKey('expires_at', $data);
        $this->assertArrayHasKey('route', $data);
        $this->assertArrayHasKey('services', $data);
        $this->assertNotEmpty($data['services']);

        $layanan = $data['services'][0];

        $this->assertArrayHasKey('service_code', $layanan);
        $this->assertArrayHasKey('orderable', $layanan);
        $this->assertArrayHasKey('fare', $layanan);

        /*
         * Dua field ini yang pernah salah dibaca aplikasi.
         *
         * Endpoint quote mengirim ongkos RATA — `total_fare` sebagai integer dan
         * `total_formatted` sebagai string. Endpoint order mengirimnya BERSARANG
         * sebagai objek Money. Aplikasi sempat memakai bentuk order untuk quote,
         * dan hasilnya harga Rp 0 di seluruh pilihan layanan.
         */
        $this->assertArrayHasKey('total_fare', $layanan['fare']);
        $this->assertArrayHasKey('total_formatted', $layanan['fare']);
        $this->assertArrayHasKey('lines', $layanan['fare']);

        $this->tulisFixture('quote.json', $data);
    }

    public function test_menulis_fixture_order_penumpang(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $quoteId = $this->postJson('/api/v1/quotes', [
            'pickup' => self::PICKUP,
            'destination' => self::DEST,
            'service_codes' => ['ride_bike'],
        ])->json('data.quote_id');

        $this->assertNotNull($quoteId);

        $response = $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson('/api/v1/orders', [
                'quote_id' => $quoteId,
                'service_code' => 'ride_bike',
                'payment_method' => 'cash',
                'pickup_address' => 'Jl. Gatot Subroto No. 12, Medan',
                'destination_address' => 'Jl. Iskandar Muda No. 4, Medan',
                'pickup_note' => 'Pagar hitam, sebelah warung',
            ]);

        $response->assertCreated();

        $data = $response->json('data');

        $this->assertArrayHasKey('uuid', $data);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('status_label', $data);
        $this->assertArrayHasKey('can_cancel', $data);

        // `can_rate` ditentukan backend, bukan disimpulkan aplikasi. Aplikasi
        // hanya bisa memeriksa statusnya — dia tidak tahu apakah order sudah
        // dinilai dari perangkat lain atau di sesi sebelumnya.
        $this->assertArrayHasKey('can_rate', $data);
        $this->assertArrayHasKey('pickup', $data);
        $this->assertArrayHasKey('fare', $data);

        // Di order, `total` BERSARANG sebagai objek Money — berbeda dari quote.
        // Perbedaan itu disengaja, dan kedua fixture ini yang menjaga aplikasi
        // tetap menanganinya masing-masing dengan benar.
        $this->assertArrayHasKey('total', $data['fare']);
        $this->assertArrayHasKey('amount', $data['fare']['total']);
        $this->assertArrayHasKey('formatted', $data['fare']['total']);

        $this->tulisFixture('order_customer.json', $data);
    }

    public function test_menulis_fixture_status_driver(): void
    {
        $driver = $this->driverSiapKerja();

        Sanctum::actingAs($driver->user);

        $response = $this->getJson('/api/v1/driver/status');

        $response->assertOk();

        $data = $response->json('data');

        $this->assertArrayHasKey('driver', $data);
        $this->assertArrayHasKey('online', $data);
        $this->assertArrayHasKey('wallet', $data);
        $this->assertArrayHasKey('today', $data);
        $this->assertArrayHasKey('can_take_cash_orders', $data['wallet']);

        $this->tulisFixture('driver_status.json', $data);
    }

    public function test_menulis_fixture_alasan_pembatalan_driver(): void
    {
        $driver = Driver::factory()->create();

        Sanctum::actingAs($driver->user);

        $response = $this->getJson('/api/v1/driver/orders/cancellation-reasons');

        $response->assertOk();

        $data = $response->json('data');

        $this->assertNotEmpty($data);
        $this->assertArrayHasKey('lowers_score', $data[0]);

        $this->tulisFixture('driver_cancellation_reasons.json', $data);
    }

    /**
     * Fixture tawaran dan order berjalan milik driver.
     *
     * ==========================================================================
     *  DUA BENTUK YANG PALING DIANDALKAN APLIKASI DRIVER
     * ==========================================================================
     *  `DriverOffer` dan `DriverOrder` adalah bentuk yang dibaca layar paling
     *  kritis di seluruh aplikasi driver — kartu tawaran dan layar order
     *  berjalan. Keduanya juga yang paling berbeda dari bentuk milik penumpang:
     *  driver hanya melihat PENDAPATANNYA, bukan total ongkos penumpang.
     *
     *  Keduanya dihasilkan dalam satu test karena order berjalan hanya bisa
     *  dibuat dengan MENERIMA tawaran — jadi urutannya memang berantai.
     * ==========================================================================
     */
    public function test_menulis_fixture_tawaran_dan_order_driver(): void
    {
        $driver = $this->driverSiapKerja();

        /*
         * Driver diberi saldo lebih dulu.
         *
         * Order tunai menuntut saldo di atas deposit minimum: komisi platform
         * dipotong dari saldo driver, dan saldo nol berarti komisi tidak bisa
         * ditagih. Tanpa saldo, mesin pencocokan MENYARING driver ini keluar dan
         * gelombang penawaran menghasilkan daftar kosong.
         *
         * Kegagalannya tidak menyebut saldo sama sekali — yang terlihat hanya
         * "tidak ada tawaran", yang mudah dibaca sebagai mesin pencocokan yang
         * rusak.
         */
        $this->beriSaldoDriver($driver, 50_000);

        Sanctum::actingAs($driver->user);

        $this->postJson('/api/v1/driver/online', self::PICKUP)->assertOk();

        $order = Order::factory()->forDistance(4_000)->create([
            'pickup_lat' => self::PICKUP['lat'],
            'pickup_lng' => self::PICKUP['lng'],
            'zone_id' => (int) DB::table('zones')->value('id'),
        ]);

        app(DispatchOfferWave::class)->handle($order, 1);

        // --- Tawaran ---
        $tawaran = $this->getJson('/api/v1/driver/orders/offers');

        $tawaran->assertOk();

        $daftar = $tawaran->json('data');

        $this->assertNotEmpty(
            $daftar,
            'Gelombang penawaran tidak menghasilkan tawaran. Tanpa ini, fixture '
            .'kartu tawaran tidak bisa dibuat — dan test Flutter untuk layar '
            .'yang paling kritis di aplikasi driver akan lulus tanpa data.',
        );

        $satu = $daftar[0];

        // Field yang dibaca `DriverOffer.fromJson`. `driver_earning` khususnya:
        // driver TIDAK menerima total ongkos penumpang, dan aplikasi yang
        // membaca kunci yang salah akan menampilkan Rp 0 sebagai pendapatan.
        $this->assertArrayHasKey('order_uuid', $satu);
        $this->assertArrayHasKey('driver_earning', $satu);
        $this->assertArrayHasKey('distance_to_pickup_m', $satu);
        $this->assertArrayHasKey('payment_method', $satu);

        /*
         * ======================================================================
         *  `expires_at` WAJIB ISO 8601 DENGAN OFFSET ZONA
         * ======================================================================
         *  Nilainya berasal dari alias SELECT, jadi Eloquent tidak meng-cast-nya
         *  ke Carbon — dan tanpa konversi eksplisit, yang keluar adalah string
         *  mentah Postgres "2026-08-28 05:47:29" tanpa penanda zona.
         *
         *  `DateTime.tryParse` di Dart memperlakukan string tanpa penanda zona
         *  sebagai waktu LOKAL. Nilainya UTC dan WIB adalah UTC+7, jadi tawaran
         *  yang masih berlaku 15 detik terbaca sebagai kadaluarsa tujuh jam yang
         *  lalu — dan SETIAP kartu tawaran disaring keluar di aplikasi driver.
         *
         *  Bug itu pernah ada di sini, dan tidak menghasilkan satu pun galat di
         *  kedua sisi. Assertion ini yang menjaganya tidak kembali.
         * ======================================================================
         */
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/',
            (string) $satu['expires_at'],
            'expires_at harus ISO 8601 dengan offset zona. Nilai sekarang: '
            .var_export($satu['expires_at'], true).'. String tanpa penanda zona '
            .'membuat setiap tawaran terlihat kadaluarsa di aplikasi driver.',
        );

        $this->tulisFixture('driver_offers.json', $daftar);

        // --- Order berjalan, setelah tawaran diterima ---
        $diterima = $this->postJson("/api/v1/driver/orders/{$order->uuid}/accept");

        $diterima->assertOk();

        $data = $diterima->json('data');

        $this->assertArrayHasKey('uuid', $data);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('earning', $data);
        $this->assertArrayHasKey('payment_method', $data);

        /*
         * `allowed_transitions` adalah yang membangun tombol aksi di layar
         * driver. Kalau kosong atau hilang, layar order berjalan tidak punya
         * satu pun tombol — dan driver tidak bisa melanjutkan perjalanannya.
         */
        $this->assertArrayHasKey('allowed_transitions', $data);
        $this->assertNotEmpty($data['allowed_transitions']);

        /*
         * `collect_from_passenger` HANYA terisi pada order tunai, dan null pada
         * order non-tunai. Perbedaan itu yang dipakai layar untuk memutuskan
         * apakah menampilkan nominal yang harus ditagih.
         */
        $this->assertArrayHasKey('collect_from_passenger', $data);

        $this->tulisFixture('driver_active_order.json', $data);
    }

    public function test_menulis_fixture_dompet(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/wallet');

        $response->assertOk();

        $data = $response->json('data');

        $this->assertArrayHasKey('balance', $data);
        $this->assertArrayHasKey('held', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('is_frozen', $data);

        $this->tulisFixture('wallet.json', $data);
    }

    /**
     * Fixture notifikasi.
     *
     * ========================================================================
     *  YANG DITULIS SELURUH ENVELOPE, BUKAN HANYA `data`
     * ========================================================================
     *  Fixture lain menyimpan `data` saja karena model Dart-nya hanya membaca
     *  itu. `NotificationPage` di Flutter membaca KEDUANYA: daftarnya dari
     *  `data`, dan `unread_count` beserta `next_cursor` dari `meta`.
     *
     *  Kalau fixture-nya hanya `data`, test Dart tidak akan pernah memeriksa
     *  pembacaan `meta` — dan `unread_count` yang berpindah tempat di backend
     *  akan lolos sampai ke lencana yang selalu menunjukkan nol.
     * ========================================================================
     */
    public function test_menulis_fixture_notifikasi(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $order = Order::factory()->for($user)->create();

        /*
         * Dua notifikasi: satu BELUM dibaca, satu SUDAH.
         *
         * Keduanya perlu ada di fixture. `is_read` yang bernilai sama di setiap
         * baris membuat test Dart lulus walaupun parser-nya mengembalikan nilai
         * tetap — dan itu tepat jenis kesalahan yang fixture ini ada untuk
         * menangkapnya.
         */
        $kirim = app(SendNotification::class);

        $kirim->handle(
            recipientType: 'user',
            recipientId: $user->id,
            type: Notification::ANNOUNCEMENT,
            title: 'Selamat datang di Antaride',
            body: 'Nikmati potongan ongkos untuk perjalanan pertama Anda.',
        );

        DB::table('notifications')
            ->where('type', Notification::ANNOUNCEMENT)
            ->update(['read_at' => now()]);

        // Dibuat SETELAH yang di atas ditandai dibaca, supaya notifikasi yang
        // belum dibaca berada di puncak daftar — urutannya menurun berdasarkan
        // `created_at`.
        $kirim->forOrder(
            recipientType: 'user',
            recipientId: $user->id,
            type: Notification::ORDER_ACCEPTED,
            title: 'Driver menuju lokasi Anda',
            body: 'Budi Santoso akan tiba dalam beberapa menit.',
            orderUuid: (string) $order->uuid,
        );

        $response = $this->getJson('/api/v1/notifications?as=user&per_page=20');

        $response->assertOk();

        $badan = $response->json();

        $this->assertArrayHasKey('data', $badan);
        $this->assertArrayHasKey('meta', $badan);

        $this->assertCount(2, $badan['data'], 'Fixture harus memuat dua notifikasi.');

        // `meta` yang dibaca `NotificationPage` di Flutter.
        $this->assertArrayHasKey('unread_count', $badan['meta']);
        $this->assertArrayHasKey('has_more', $badan['meta']);
        $this->assertArrayHasKey('next_cursor', $badan['meta']);

        $this->assertSame(
            1,
            $badan['meta']['unread_count'],
            'Satu notifikasi belum dibaca, satu sudah. Kalau angkanya 2, '
            .'`read_at` tidak diperhitungkan — dan lencana di aplikasi tidak '
            .'akan pernah turun.',
        );

        // Field yang dibaca `AppNotification.fromJson`.
        foreach ($badan['data'] as $satu) {
            $this->assertArrayHasKey('uuid', $satu);
            $this->assertArrayHasKey('type', $satu);
            $this->assertArrayHasKey('title', $satu);
            $this->assertArrayHasKey('body', $satu);
            $this->assertArrayHasKey('is_read', $satu);
            $this->assertArrayHasKey('created_at', $satu);
        }

        $this->assertTrue(
            $badan['data'][0]['is_read'] !== $badan['data'][1]['is_read'],
            'Kedua notifikasi punya status baca yang sama. Fixture seperti itu '
            .'akan meloloskan parser Dart yang mengembalikan nilai tetap.',
        );

        /*
         * `action` pada notifikasi order harus memuat `order_uuid`, dan namanya
         * harus PERSIS itu.
         *
         * Ini yang dipakai `AppNotification.orderUuid` untuk memutuskan ke mana
         * notifikasi membuka. Nama yang berbeda — `orderUuid`, `order_id` —
         * membuat notifikasi terbuka ke layar kosong tanpa satu pun galat.
         */
        $notifOrder = collect($badan['data'])->firstWhere(
            'type',
            Notification::ORDER_ACCEPTED,
        );

        $this->assertNotNull($notifOrder, 'Notifikasi order tidak ada di response.');
        $this->assertIsArray($notifOrder['action']);
        $this->assertSame('order', $notifOrder['action']['screen']);
        $this->assertSame((string) $order->uuid, $notifOrder['action']['order_uuid']);

        /*
         * `created_at` harus ISO 8601 BERZONA.
         *
         * Sama seperti `expires_at` pada tawaran driver — dan itu bug yang sudah
         * pernah terjadi di proyek ini. String tanpa penanda zona diurai Dart
         * sebagai waktu LOKAL, jadi notifikasi yang baru dibuat akan tampil
         * sebagai "7 jam lalu" di WIB.
         */
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/',
            (string) $badan['data'][0]['created_at'],
            'created_at harus ISO 8601 dengan offset zona. Nilai sekarang: '
            .var_export($badan['data'][0]['created_at'], true).'. Tanpa penanda '
            .'zona, notifikasi yang baru dibuat tampil sebagai "7 jam lalu".',
        );

        $this->tulisFixture('notifications.json', $badan);
    }

    /**
     * Fixture dokumen KYC driver.
     *
     * ========================================================================
     *  TIGA STATUS SEKALIGUS, KARENA LAYARNYA MENGGAMBAR KETIGANYA BERBEDA
     * ========================================================================
     *  Fixture yang isinya satu dokumen `pending` saja akan meloloskan parser
     *  yang mengembalikan `pending` untuk apa pun — dan yang paling merugikan
     *  justru dua yang lain:
     *
     *    `rejected`   membawa `reject_reason`. Itu satu-satunya cara driver
     *                 mengetahui apa yang salah dengan dokumennya. Kalau tidak
     *                 terbaca, dia mengunggah foto yang sama berulang kali.
     *
     *    `approved`   yang tanggalnya SUDAH LEWAT. Statusnya `approved` dan
     *                 `is_expired` true sekaligus — keduanya benar, dan yang
     *                 perlu ditampilkan adalah yang kedua.
     * ========================================================================
     */
    public function test_menulis_fixture_dokumen_driver(): void
    {
        $driver = Driver::factory()->create();

        Sanctum::actingAs($driver->user);

        // Tiga dokumen dengan tiga keadaan berbeda.
        $ini = [
            ['ktp', 'approved', null, null],
            ['sim', 'approved', '2020-01-01', null],
            ['stnk', 'rejected', null, 'Foto terlalu gelap, nomor rangka tidak terbaca.'],
            ['selfie', 'pending', null, null],
        ];

        foreach ($ini as [$type, $status, $expires, $alasan]) {
            DB::table('driver_documents')->insert([
                'uuid' => (string) Str::uuid7(),
                'driver_id' => $driver->id,
                'type' => $type,
                'file_path' => 'driver/'.$driver->id.'/'.Str::uuid7().'.jpg',
                'file_hash' => str_repeat('a', 64),
                'status' => $status,
                'expires_at' => $expires,
                'reject_reason' => $alasan,
                'reviewed_at' => $status === 'pending' ? null : now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $response = $this->getJson('/api/v1/driver/documents');

        $response->assertOk();

        $data = $response->json('data');

        // Field yang dibaca `DriverDocumentState.fromJson` di Flutter.
        $this->assertArrayHasKey('documents', $data);
        $this->assertArrayHasKey('required', $data);
        $this->assertArrayHasKey('missing', $data);
        $this->assertArrayHasKey('can_go_online', $data);

        $this->assertNotEmpty($data['required'], 'Daftar wajib kosong — layar tidak akan menampilkan apa pun.');

        foreach ($data['documents'] as $satu) {
            $this->assertArrayHasKey('uuid', $satu);
            $this->assertArrayHasKey('type', $satu);
            $this->assertArrayHasKey('label', $satu);
            $this->assertArrayHasKey('status', $satu);
            $this->assertArrayHasKey('needs_expiry', $satu);
            $this->assertArrayHasKey('is_expired', $satu);
        }

        /*
         * `file_path` TIDAK BOLEH ada di response.
         *
         * Path mentah tidak berguna bagi aplikasi — disk KYC privat. Tapi berguna
         * bagi orang yang sedang mencari cara menebak path dokumen driver lain.
         *
         * Diperiksa di sini juga, bukan hanya di test unggah: fixture yang memuat
         * path akan MENULISKANNYA ke repo frontend, tempat dia akan hidup di
         * riwayat git selamanya.
         */
        $this->assertStringNotContainsString(
            'file_path',
            $response->content(),
            'Path berkas ikut terkirim, dan akan tertulis ke fixture di repo '
            .'frontend.',
        );

        /*
         * Dokumen yang KADALUARSA harus terlihat sebagai kadaluarsa, walaupun
         * statusnya `approved`.
         *
         * Kalau `is_expired` tidak terisi, driver yang SIM-nya habis akan melihat
         * "Disetujui" lalu ditolak saat menekan online — tanpa satu pun petunjuk
         * kenapa.
         */
        $sim = collect($data['documents'])->firstWhere('type', 'sim');

        $this->assertNotNull($sim);
        $this->assertTrue(
            $sim['is_expired'],
            'Dokumen yang tanggalnya sudah lewat tidak ditandai kadaluarsa.',
        );

        // Dan yang ditolak membawa alasannya.
        $stnk = collect($data['documents'])->firstWhere('type', 'stnk');

        $this->assertNotNull($stnk);
        $this->assertNotEmpty(
            $stnk['reject_reason'],
            'Alasan penolakan tidak terkirim. Driver akan mengunggah foto yang '
            .'sama berulang kali.',
        );

        $this->tulisFixture('driver_documents.json', $data);
    }

    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>|list<mixed>  $data
     */
    private function tulisFixture(string $nama, array $data): void
    {
        $direktori = base_path('../antaride-fe/test_fixtures');

        /*
         * Repo FE mungkin tidak ada — misalnya saat CI hanya meng-clone BE.
         *
         * Test-nya TIDAK digagalkan karena itu: yang diuji di atas (bentuk
         * response-nya) tetap valid tanpa penulisan berkas. Menggagalkannya akan
         * membuat suite BE bergantung pada keberadaan repo lain.
         */
        if (! is_dir(dirname($direktori))) {
            $this->addToAssertionCount(1);

            return;
        }

        if (! is_dir($direktori)) {
            mkdir($direktori, 0o755, true);
        }

        file_put_contents(
            $direktori.'/'.$nama,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
        );

        $this->assertFileExists($direktori.'/'.$nama);
    }

    /**
     * Menambah saldo dompet driver lewat pembukuan berpasangan.
     *
     * Tidak dengan UPDATE langsung ke `wallets.balance`: saldo adalah hasil
     * akumulasi `wallet_transactions`, dan trigger `wallet_transactions_balanced`
     * di database menolak peristiwa yang tidak berjumlah nol. Jadi dananya harus
     * datang DARI suatu tempat — di sini dompet settlement platform.
     */
    private function beriSaldoDriver(Driver $driver, int $jumlah): void
    {
        $dompetDriver = Wallet::forOwner('driver', (int) $driver->getKey());
        $settlement = Wallet::platform(Wallet::PLATFORM_SETTLEMENT);

        app(PostLedgerEntries::class)->handle([
            LedgerEntry::debit(
                walletId: (int) $settlement->getKey(),
                type: 'topup',
                amount: Money::of($jumlah),
                referenceType: 'topup',
                referenceId: (int) $driver->getKey(),
                description: 'Fixture: dana masuk',
            ),
            LedgerEntry::credit(
                walletId: (int) $dompetDriver->getKey(),
                type: 'topup',
                amount: Money::of($jumlah),
                referenceType: 'topup',
                referenceId: (int) $driver->getKey(),
                description: 'Fixture: dana masuk',
            ),
        ]);
    }

    private function driverSiapKerja(): Driver
    {
        $driver = Driver::factory()->create();

        Vehicle::factory()->create(['driver_id' => $driver->id]);

        $rideBikeId = (int) DB::table('service_types')->where('code', 'ride_bike')->value('id');

        DB::table('driver_service_eligibility')->insert([
            'driver_id' => $driver->id,
            'service_type_id' => $rideBikeId,
            'is_enabled' => true,
            'enabled_by_driver' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $driver->fresh()->load('user');
    }

    protected function tearDown(): void
    {
        foreach (Driver::query()->pluck('id') as $driverId) {
            app(DriverLocationIndex::class)->forget((int) $driverId);
            app(DriverLocationIndex::class)->markUnavailableEverywhere((int) $driverId);
        }

        parent::tearDown();
    }
}
