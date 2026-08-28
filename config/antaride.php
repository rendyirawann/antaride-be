<?php

/*
|------------------------------------------------------------------------------
| Parameter Bisnis Antaride
|------------------------------------------------------------------------------
|
| Semua angka yang menentukan perilaku bisnis tinggal di sini, bukan tersebar
| sebagai literal di dalam Action dan Job. Alasannya praktis: saat tim ops minta
| radius matching diperlebar jam 5 pagi, yang diubah satu baris env, bukan
| berburu angka 2000 di enam file.
|
| Yang TIDAK boleh ada di sini: tarif. Tarif hidup di tabel pricing_rules dengan
| effective_from / effective_until, karena order lama harus tetap bisa
| dijelaskan tiga bulan kemudian. Config di-deploy dan menimpa; tabel tarif
| menyimpan sejarah.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Identitas Aplikasi
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | Zona Waktu Bisnis
    |--------------------------------------------------------------------------
    |
    | TERPISAH dari app.timezone, dan itu disengaja.
    |
    | app.timezone tetap UTC supaya seluruh timestamp yang disimpan tidak
    | bergantung pada setelan server. Tapi keputusan bisnis tidak dibuat dalam
    | UTC: "jam pulang kerja" berarti 17:00 WIB, "warung tutup jam 2 pagi"
    | berarti 02:00 WIB, dan "pelanggaran hari ini" berarti sejak tengah malam
    | WIB.
    |
    | Sebelum pemisahan ini ada, perbandingan jam dilakukan pada waktu UTC, dan
    | akibatnya sudah dibuktikan: aturan surge berjadwal 17:00-19:30 TIDAK
    | PERNAH menyala pada jam pulang kerja sungguhan, karena 17:30 WIB adalah
    | 10:30 UTC. Tidak ada error apa pun yang muncul.
    |
    | Yang membaca nilai ini: App\Domain\Shared\Support\BusinessClock. Jangan
    | membandingkan jam bisnis tanpa melewatinya.
    |
    */

    'timezone' => env('BUSINESS_TIMEZONE', 'Asia/Jakarta'),

    'brand' => [
        'name' => env('APP_NAME', 'Antaride'),
        'order_number_prefix' => env('ORDER_NUMBER_PREFIX', 'RD'),
        'support_phone' => env('SUPPORT_PHONE', '+628000000000'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Routing Entry Point
    |--------------------------------------------------------------------------
    |
    | Produksi memisahkan admin dan API ke subdomain berbeda supaya Nginx bisa
    | memberi lapisan tambahan pada admin: allowlist IP di level web server,
    | basic auth untuk staging, dan header keamanan yang lebih ketat.
    |
    | Di lokal keduanya null. Ketika admin_domain null, panel hidup di prefix
    | /admin. Ketika terisi, prefix dikosongkan supaya URL-nya bersih.
    |
    */

    'routing' => [
        'api_domain' => env('API_DOMAIN') ?: null,
        'admin_domain' => env('ADMIN_DOMAIN') ?: null,
        'admin_prefix' => env('ADMIN_DOMAIN') ? '' : env('ADMIN_PREFIX', 'admin'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Keamanan Panel Admin (blueprint admin bagian 3)
    |--------------------------------------------------------------------------
    |
    | Lapisan-lapisan ini merepotkan, dan itu memang tujuannya. Panel admin
    | adalah target bernilai tinggi: satu akun ops yang bobol bisa mengubah
    | tarif seluruh kota atau menyetujui penarikan fiktif.
    |
    */

    'security' => [
        // Admin tanpa 2FA aktif tidak bisa mengakses apa pun selain halaman
        // setup 2FA. Untuk finance dan superadmin tidak ada pengecualian,
        // apa pun nilai flag ini.
        'two_factor_required' => (bool) env('ADMIN_2FA_REQUIRED', true),

        // Finance dan superadmin hanya boleh login dari IP kantor atau VPN.
        'ip_allowlist_enabled' => (bool) env('ADMIN_IP_ALLOWLIST_ENABLED', false),
        'ip_allowlist_roles' => ['super-admin', 'finance'],

        // Sesi pendek. Kalau staf lupa logout di warnet, kerusakannya terbatas.
        'session_idle_minutes' => (int) env('ADMIN_SESSION_IDLE_MINUTES', 120),
        'session_absolute_minutes' => (int) env('ADMIN_SESSION_ABSOLUTE_MINUTES', 720),

        // Rate limit login yang agresif, plus notifikasi ke pemilik akun
        // setiap kali ada login dari device baru.
        /*
         * Berapa lama verifikasi 2FA berlaku dalam satu sesi.
         *
         * Sesi admin bisa hidup berjam-jam. Verifikasi yang berlaku selama sesi
         * berarti komputer ops yang ditinggalkan terbuka pagi hari masih bisa
         * dipakai siapa pun sore hari.
         *
         * 12 jam dipilih supaya satu shift kerja tidak menuntut kode dua kali,
         * dan sesi yang menyeberang hari tetap menuntutnya lagi.
         */
        'two_factor_ttl_minutes' => (int) env('ADMIN_2FA_TTL_MINUTES', 720),

        'login_max_attempts' => (int) env('ADMIN_LOGIN_MAX_ATTEMPTS', 5),
        'login_lockout_minutes' => (int) env('ADMIN_LOGIN_LOCKOUT_MINUTES', 15),
        'notify_new_device_login' => true,

        // Alasan wajib untuk tindakan destruktif. Friksi di tempat yang tepat
        // adalah fitur, bukan gangguan.
        'destructive_reason_min_length' => (int) env('ADMIN_REASON_MIN_LENGTH', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | OTP
    |--------------------------------------------------------------------------
    |
    | sandbox_code hanya dipakai saat APP_ENV bukan production. Ini yang
    | membuat pengembangan dan test tidak bergantung pada gateway SMS.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | SMS
    |--------------------------------------------------------------------------
    |
    | Provider sungguhan belum dipilih. Sampai ada, `log` yang dipakai: seluruh
    | alur autentikasi tetap lengkap dan bisa diuji, dan kodenya muncul di
    | storage/logs/sms.log untuk pengembangan.
    |
    | Di produksi driver `log` HARUS diganti. `antaride:health` menandainya.
    |
    */

    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        'sender_id' => env('SMS_SENDER_ID', 'ANTARIDE'),
    ],

    'otp' => [
        'length' => (int) env('OTP_LENGTH', 4),
        'ttl_seconds' => (int) env('OTP_TTL_SECONDS', 300),
        'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),
        'sandbox_code' => env('OTP_SANDBOX_CODE', '1234'),
        'resend_cooldown_seconds' => (int) env('OTP_RESEND_COOLDOWN', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Quote (estimasi harga)
    |--------------------------------------------------------------------------
    |
    | Harga TIDAK PERNAH dikirim dari client. Client menerima quote_id, backend
    | membaca harganya dari Redis. Kalau quote kadaluarsa, client wajib minta
    | quote baru. Ini menutup celah yang paling sering dieksploitasi.
    |
    */

    'quote' => [
        'ttl_seconds' => (int) env('QUOTE_TTL_SECONDS', 300),
        'max_stops' => (int) env('QUOTE_MAX_STOPS', 5),
        // Selisih jarak aktual vs estimasi di atas ambang ini menandai order
        // untuk direview, tidak di-settle otomatis.
        'distance_variance_review_percent' => (float) env('QUOTE_VARIANCE_REVIEW', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Matching (blueprint bagian 4.3)
    |--------------------------------------------------------------------------
    |
    | Pola hybrid: gelombang 1 broadcast ke 3 driver terbaik, kalau 15 detik
    | tidak ada yang ambil, gelombang berikutnya ke 5 driver dengan radius
    | diperluas, maksimal 4 gelombang lalu order jadi no_driver.
    |
    */

    'matching' => [
        'initial_radius_m' => (int) env('MATCHING_INITIAL_RADIUS_M', 2000),
        'max_radius_m' => (int) env('MATCHING_MAX_RADIUS_M', 8000),
        'radius_multiplier' => (float) env('MATCHING_RADIUS_MULTIPLIER', 1.6),
        'max_waves' => (int) env('MATCHING_MAX_WAVES', 4),
        'offer_ttl_seconds' => (int) env('MATCHING_OFFER_TTL_SECONDS', 15),

        'candidates' => [
            'first_wave' => (int) env('MATCHING_WAVE1_CANDIDATES', 3),
            'next_waves' => (int) env('MATCHING_WAVE_N_CANDIDATES', 5),
        ],

        // Bobot skoring kandidat. Jumlahnya sengaja 1.00 supaya skor selalu
        // berada di rentang yang bisa dibandingkan antar zona.
        //
        // 'idle' adalah bobot keadilan: driver yang sudah lama menganggur
        // dinaikkan. Tanpa ini, driver dengan rating tinggi di lokasi bagus
        // memonopoli order dan driver baru tidak pernah dapat kesempatan.
        'weights' => [
            'distance' => (float) env('MATCHING_WEIGHT_DISTANCE', 0.45),
            'rating' => (float) env('MATCHING_WEIGHT_RATING', 0.15),
            'acceptance' => (float) env('MATCHING_WEIGHT_ACCEPTANCE', 0.15),
            'idle' => (float) env('MATCHING_WEIGHT_IDLE', 0.20),
            'cancellation' => (float) env('MATCHING_WEIGHT_CANCELLATION', 0.05),
        ],

        // Batas atas idle yang masih dihitung sebagai bonus keadilan (15 menit).
        'idle_cap_seconds' => (int) env('MATCHING_IDLE_CAP_SECONDS', 900),

        // Driver yang ping terakhirnya lebih lama dari ini dianggap tidak
        // hadir, walaupun statusnya masih online di Redis.
        'stale_ping_seconds' => (int) env('MATCHING_STALE_PING_SECONDS', 30),

        // Lock Redis saat driver menekan accept. Lebih pendek dari ini berisiko
        // dua driver lolos; lebih panjang menahan order kalau proses mati.
        'accept_lock_seconds' => (int) env('MATCHING_ACCEPT_LOCK_SECONDS', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Geo
    |--------------------------------------------------------------------------
    |
    | zone_driver menentukan cara titik pickup dipetakan ke zona operasional,
    | yang menentukan tarif dan surge:
    |
    |   postgis  ST_Contains dengan index GiST. Eksak dan cepat. Dipakai produksi.
    |   native   ray-casting di PHP, polygon zona di-cache di Redis. Ada supaya
    |            pengembangan dan test tidak terhalang saat PostGIS belum
    |            terpasang.
    |
    | geo_command menyesuaikan perintah GEO Redis dengan versi servernya.
    | GEOSEARCH baru ada di Redis 6.2; build Windows yang umum masih 5.0, jadi
    | harus turun ke GEORADIUS.
    |
    */

    'geo' => [
        'zone_driver' => env('GEO_ZONE_DRIVER', 'postgis'),
        'redis_command' => env('REDIS_GEO_COMMAND', 'georadius'),

        // Polygon zona jarang berubah, jadi boleh di-cache agak lama. Setiap
        // perubahan zona lewat panel admin membersihkan cache ini, jadi TTL
        // panjang tidak membuat tarif tertinggal.
        'zone_cache_seconds' => (int) env('GEO_ZONE_CACHE_SECONDS', 900),
    ],

    /*
    |--------------------------------------------------------------------------
    | GPS & Anti Fake Location (blueprint bagian 5)
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | Layanan Lokasi (Go, port 8200)
    |--------------------------------------------------------------------------
    |
    | Ping GPS driver TIDAK pernah menyentuh PHP. Layanan Go menerimanya dan
    | menulis langsung ke Redis dengan GEOADD.
    |
    | Alasannya beban: seribu driver dengan ping tiap empat detik adalah 250
    | permintaan per detik yang isinya hanya dua angka. Melewatkannya lewat
    | Laravel berarti 250 boot framework per detik untuk pekerjaan yang tidak
    | membutuhkan satu pun fiturnya.
    |
    | Laravel tetap yang MENENTUKAN siapa yang boleh mengirim: dia menerbitkan
    | tiket bertanda tangan HMAC saat driver online, dan Go hanya memverifikasi
    | tanda tangannya — tanpa database, tanpa jaringan.
    |
    */

    'location_service' => [
        'url' => (string) env('LOCATION_SERVICE_URL', 'http://127.0.0.1:8200'),

        /*
         * Rahasia bersama untuk menandatangani tiket.
         *
         * WAJIB diset di produksi, dan HARUS sama dengan
         * `ANTARIDE_LOCATION_SECRET` di layanan Go. Kalau berbeda, setiap ping
         * ditolak 401 — dan gejalanya bukan galat di aplikasi driver, tapi
         * driver yang online dan tidak pernah muncul sebagai kandidat matching.
         *
         * Dibiarkan kosong di pengembangan: `LocationTicket` menurunkannya dari
         * APP_KEY, dan layanan Go menurunkannya dengan cara yang sama.
         */
        'shared_secret' => (string) env('LOCATION_SERVICE_SECRET', ''),
    ],

    'gps' => [
        // Interval ping yang diminta ke app driver, per keadaan.
        'ping_interval_seconds' => [
            'idle' => (int) env('GPS_PING_IDLE', 10),
            'on_order' => (int) env('GPS_PING_ON_ORDER', 4),
            'low_battery' => (int) env('GPS_PING_LOW_BATTERY', 15),
        ],
        'low_battery_threshold_percent' => (int) env('GPS_LOW_BATTERY_PERCENT', 15),

        // Ping lebih rapat dari ini dibuang oleh location service.
        'min_interval_seconds' => (float) env('GPS_MIN_INTERVAL_SECONDS', 2),

        // Akurasi di atas ini tidak layak untuk konfirmasi geofence, tapi tetap
        // disimpan dengan tanda low_quality.
        'max_accuracy_m' => (int) env('GPS_MAX_ACCURACY_M', 100),

        // Lompatan posisi yang menghasilkan kecepatan di atas ini ditolak.
        'max_speed_kmh' => (int) env('GPS_MAX_SPEED_KMH', 150),

        // Radius auto transisi ke driver_arrived.
        'geofence_arrival_m' => (int) env('GPS_GEOFENCE_ARRIVAL_M', 100),

        // Radius yang wajib dipenuhi driver saat menekan selesai. Di luar itu
        // butuh alasan wajib dan order ditandai untuk audit.
        'geofence_completion_m' => (int) env('GPS_GEOFENCE_COMPLETION_M', 300),

        // Jumlah ping mock dalam sehari yang memicu auto suspend.
        'mock_suspend_threshold' => (int) env('GPS_MOCK_SUSPEND_THRESHOLD', 5),

        // Bounding box Indonesia. Koordinat di luar ini ditolak mentah-mentah.
        'bounds' => [
            'min_lat' => -11.5,
            'max_lat' => 6.5,
            'min_lng' => 94.5,
            'max_lng' => 141.5,
        ],

        // Toleransi penyederhanaan Douglas-Peucker saat menyimpan polyline
        // trip ke Postgres. Dalam derajat; 0.00005 kira-kira 5 meter.
        'polyline_simplify_tolerance' => (float) env('GPS_SIMPLIFY_TOLERANCE', 0.00005),

        /*
         * Batas penyimpangan jarak aktual dari estimasi, dalam persen.
         *
         * Di atas ini, order tetap SELESAI tapi pembagian uangnya menunggu
         * review manusia. Ongkos dibekukan saat order dibuat, jadi jarak yang
         * jauh berbeda berarti salah satu dari tiga hal terjadi: penumpang
         * mengubah tujuan di tengah jalan, driver mengambil jalan yang jauh
         * berbeda, atau GPS-nya kacau. Ketiganya butuh dilihat manusia.
         *
         * 30% dipilih supaya tidak terlalu sensitif: rute alternatif karena
         * jalan ditutup atau macet bisa menambah 10-20% dengan wajar, dan
         * mengirim semua order seperti itu ke antrean review akan membuat
         * antreannya diabaikan seluruhnya.
         */
        'fare_review_deviation_percent' => (float) env('GPS_FARE_REVIEW_DEVIATION', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Order
    |--------------------------------------------------------------------------
    */

    'order' => [
        'pickup_code_length' => 4,

        // Batas waktu pencarian driver sebelum order jadi no_driver.
        'search_timeout_seconds' => (int) env('ORDER_SEARCH_TIMEOUT', 90),

        // Setelah driver accept, user masih boleh batal gratis selama ini.
        'free_cancel_window_seconds' => (int) env('ORDER_FREE_CANCEL_WINDOW', 180),

        // Satu user hanya boleh punya satu order aktif sekaligus.
        'max_active_per_user' => (int) env('ORDER_MAX_ACTIVE_PER_USER', 1),

        // Timer tunggu driver di titik jemput sebelum boleh batal berbayar.
        'driver_wait_minutes' => (int) env('ORDER_DRIVER_WAIT_MINUTES', 5),

        /*
         * Biaya pembatalan yang ditagih ke penumpang.
         *
         * Nominalnya kecil dan sengaja begitu. Yang dicegah bukan pembatalan
         * itu sendiri — orang berhak berubah pikiran — tapi kebiasaan memesan
         * lalu membatalkan berulang kali, yang membuat driver berkendara ke
         * titik jemput tanpa mendapat apa pun.
         *
         * Yang diterima driver adalah SELURUH nominal ini, bukan sebagian.
         * Platform tidak mengambil komisi dari biaya pembatalan: uang ini
         * mengganti bensin dan waktu yang sudah dikeluarkan driver, dan
         * memotongnya berarti platform mendapat pendapatan dari kejadian yang
         * merugikan kedua pihak.
         */
        'cancellation_fee' => (int) env('ORDER_CANCELLATION_FEE', 5_000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Wallet & Settlement
    |--------------------------------------------------------------------------
    |
    | Semua nominal BIGINT dalam Rupiah utuh. Tidak ada float, tidak pernah.
    |
    */

    'wallet' => [
        // Driver yang menerima order tunai memegang uang platform, jadi wajib
        // punya saldo deposit minimum. Di bawah ambang ini dia hanya boleh
        // menerima order non-tunai. Dicek di filter matching.
        'driver_cash_deposit_minimum' => (int) env('WALLET_DRIVER_CASH_MINIMUM', 20000),

        'topup' => [
            'min_amount' => (int) env('WALLET_TOPUP_MIN', 10000),
            'max_amount' => (int) env('WALLET_TOPUP_MAX', 2000000),
            'expires_minutes' => (int) env('WALLET_TOPUP_EXPIRES_MINUTES', 60),
        ],

        'withdrawal' => [
            'min_amount' => (int) env('WALLET_WITHDRAWAL_MIN', 50000),
            'max_amount_per_day' => (int) env('WALLET_WITHDRAWAL_MAX_DAILY', 5000000),
            'fee' => (int) env('WALLET_WITHDRAWAL_FEE', 2500),
            // Sisa saldo yang harus tetap tertinggal setelah penarikan.
            'keep_minimum' => (int) env('WALLET_WITHDRAWAL_KEEP_MINIMUM', 20000),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    |
    | `lock_ttl_seconds` adalah batas atas lama sebuah request boleh berjalan.
    | Klaim yang lebih tua dari itu dianggap mati dan boleh diambil alih oleh
    | percobaan berikutnya. Angkanya harus LEBIH BESAR dari request terlambat
    | yang wajar, dan lebih kecil dari kesabaran pengguna:
    |
    |   terlalu kecil -> request lambat yang masih hidup diambil alih, dan
    |                    eksekusinya jalan dua kali. Ini yang justru dicegah
    |                    seluruh mekanisme ini.
    |   terlalu besar -> pengguna terkunci lama saat ada proses yang mati.
    |
    | 60 detik dipilih karena tidak ada endpoint pemindah uang yang sah
    | berjalan selebih itu; kalau ada, yang perlu diperbaiki endpoint-nya.
    |
    */

    'idempotency' => [
        'lock_ttl_seconds' => (int) env('IDEMPOTENCY_LOCK_TTL', 60),
        'retention_hours' => (int) env('IDEMPOTENCY_RETENTION_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Approval Dua Tahap (blueprint admin bagian 7)
    |--------------------------------------------------------------------------
    |
    | Ambang default. Nilai sebenarnya dibaca dari tabel approval_thresholds
    | supaya bisa diubah tim finance tanpa deploy; yang di sini hanya nilai awal
    | untuk seeder dan fallback kalau tabelnya kosong.
    |
    */

    'approval' => [
        'request_ttl_hours' => (int) env('APPROVAL_TTL_HOURS', 72),
        'reason_min_length' => (int) env('APPROVAL_REASON_MIN_LENGTH', 20),

        /*
         * Rentangnya [min, max) — batas atas EKSKLUSIF, sama seperti
         * int8range(min_amount, max_amount, '[)') yang menjaga tabel
         * approval_thresholds. Penarikan tepat Rp 500.000 masuk baris KEDUA,
         * bukan yang pertama, jadi tetap butuh satu penyetuju.
         *
         * `min` wajib ditulis walaupun terlihat bisa disimpulkan dari baris
         * sebelumnya. Tanpa dia, hasil pencocokan bergantung pada urutan array,
         * dan menukar dua baris di sini akan mengubah kebijakan approval
         * seluruh platform lewat diff yang terlihat seperti penataan ulang.
         */
        'withdrawal_thresholds' => [
            // < 500rb otomatis, tanpa approval
            ['min' => 0, 'max' => 500_000, 'approvers' => 0, 'role' => null],
            // 500rb sampai < 5jt: satu approver finance
            ['min' => 500_000, 'max' => 5_000_000, 'approvers' => 1, 'role' => 'finance'],
            // >= 5jt: dua approver, salah satunya superadmin
            ['min' => 5_000_000, 'max' => null, 'approvers' => 2, 'role' => 'super-admin'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Impersonation (blueprint admin bagian 9)
    |--------------------------------------------------------------------------
    |
    | Read-only, berbatas waktu, dan pengguna yang diimpersonasi selalu diberi
    | tahu. Bagian terakhir itu yang membuat staf berpikir dua kali sebelum
    | membuka akun yang bukan urusannya.
    |
    */

    'impersonation' => [
        'max_minutes' => (int) env('IMPERSONATION_MAX_MINUTES', 15),
        'read_only' => true,
        'notify_user' => true,
        'require_ticket_reference' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Live Map (blueprint admin bagian 6)
    |--------------------------------------------------------------------------
    |
    | Admin tidak subscribe ping mentah. Dia mengirim bounding box, server
    | mengirim snapshot berkala untuk area itu saja. Di atas batas marker,
    | yang dikembalikan agregat cluster per grid, bukan marker individual.
    |
    */

    'live_map' => [
        'max_markers' => (int) env('LIVE_MAP_MAX_MARKERS', 500),
        'cluster_grid_m' => (int) env('LIVE_MAP_CLUSTER_GRID_M', 500),
        'geohash_precision' => (int) env('LIVE_MAP_GEOHASH_PRECISION', 5),
        // Location service mengumpulkan ping dalam buffer ini lalu mem-publish
        // satu snapshot per geohash. Ops tidak butuh presisi sub-detik.
        'publish_buffer_seconds' => (int) env('LIVE_MAP_BUFFER_SECONDS', 3),
        // Order yang mencari driver lebih lama dari ini disorot untuk ops.
        'stuck_order_highlight_seconds' => (int) env('LIVE_MAP_STUCK_SECONDS', 60),

        /*
         * Titik dan zoom awal peta.
         *
         * Lapangan Merdeka Medan. Diambil dari config, bukan dari zona pertama
         * menurut abjad — zona itu bisa berada di pinggir kota, dan peta yang
         * terbuka di pinggir menuntut setiap staf menggesernya setiap kali
         * membuka halaman.
         */
        'center_lat' => (float) env('LIVE_MAP_CENTER_LAT', 3.5952),
        'center_lng' => (float) env('LIVE_MAP_CENTER_LNG', 98.6722),
        'default_zoom' => (int) env('LIVE_MAP_DEFAULT_ZOOM', 12),

        /*
         * Ukuran kotak pengelompokan, dalam DERAJAT.
         *
         * 0,01 derajat sekitar 1,1 km di lintang Medan. Memakai derajat membuat
         * pengelompokannya bisa dihitung dengan pembulatan sederhana tanpa
         * proyeksi, dan untuk menampilkan kepadatan di peta, ketepatan proyeksi
         * tidak menambah apa pun.
         *
         * `cluster_grid_m` di atas dipakai location service Go yang punya
         * perhitungannya sendiri; yang ini untuk pengelompokan di sisi PHP.
         */
        'cluster_grid_degrees' => (float) env('LIVE_MAP_CLUSTER_GRID_DEG', 0.01),

        // Seberapa sering peta menarik data baru.
        'refresh_interval_ms' => (int) env('LIVE_MAP_REFRESH_MS', 5000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Export (blueprint admin bagian 10)
    |--------------------------------------------------------------------------
    |
    | Export selalu asinkron, tanpa pengecualian, termasuk yang "cuma seribu
    | baris". Seribu baris hari ini adalah dua juta baris tahun depan, dan tidak
    | ada yang akan ingat mengubahnya.
    |
    */

    'export' => [
        'chunk_size' => (int) env('EXPORT_CHUNK_SIZE', 1000),
        'signed_url_hours' => (int) env('EXPORT_SIGNED_URL_HOURS', 24),
        'retention_days' => (int) env('EXPORT_RETENTION_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Penyimpanan
    |--------------------------------------------------------------------------
    |
    | Disk untuk dokumen KYC. Wajib disk PRIVAT: KTP, SIM, STNK, dan foto selfie
    | hanya boleh diakses lewat signed URL berumur pendek yang diterbitkan
    | setelah pemeriksaan permission.
    |
    | Bagian ini sebelumnya tidak ada, padahal DriverDocument::temporaryUrl()
    | sudah membacanya. Karena ada nilai default di pemanggilnya, tidak ada
    | error yang muncul; yang terjadi adalah KYC_DISK di .env tidak berpengaruh
    | apa pun. Ditemukan dengan mencocokkan seluruh pemanggilan config() ke
    | isi config, dan sekarang dijaga test.
    |
    */

    'storage' => [
        'kyc_disk' => env('KYC_DISK', 'kyc'),
        'export_disk' => env('EXPORT_DISK', 'exports'),
        'public_disk' => env('PUBLIC_DISK', 'public'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Masking Data Pribadi (blueprint admin bagian 3)
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | KYC Driver
    |--------------------------------------------------------------------------
    */

    'kyc' => [
        /*
         * Dokumen yang WAJIB disetujui sebelum driver bisa aktif.
         *
         * Daftarnya di config, bukan di kode, supaya bisa berubah tanpa deploy —
         * misalnya kalau nanti ada peraturan daerah yang menuntut dokumen
         * tambahan.
         *
         * SKCK dan sertifikat vaksin TIDAK termasuk wajib. Keduanya boleh
         * diunggah dan diverifikasi, tapi menuntutnya berarti menunda driver
         * baru berminggu-minggu untuk dokumen yang pengurusannya di luar kendali
         * mereka.
         */
        'required_documents' => ['ktp', 'sim', 'stnk', 'selfie'],

        /*
         * Dokumen yang punya masa berlaku.
         *
         * Verifikator WAJIB mengisi tanggal kadaluarsa untuk jenis-jenis ini.
         * Tanpa tanggalnya, `GoOnline` tidak punya cara mengetahui bahwa SIM
         * seorang driver sudah habis — dan dia tetap mengambil order.
         */
        'expiring_documents' => ['sim', 'stnk', 'skck', 'vaccine'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Masking Data Pribadi (blueprint admin bagian 3)
    |--------------------------------------------------------------------------
    */

    'masking' => [
        'nik' => ['prefix' => 4, 'suffix' => 4],
        'bank_account' => ['prefix' => 0, 'suffix' => 4],
        'phone' => ['prefix' => 4, 'suffix' => 3],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retensi Data
    |--------------------------------------------------------------------------
    |
    | UU PDP mensyaratkan retensi terbatas untuk data lokasi. Angka di bawah
    | ini perlu dikonfirmasi ke konsultan hukum sebelum go-live.
    |
    */

    'retention' => [
        'gps_track_days' => (int) env('RETENTION_GPS_TRACK_DAYS', 90),
        'order_chat_days' => (int) env('RETENTION_ORDER_CHAT_DAYS', 180),
        'otp_request_days' => (int) env('RETENTION_OTP_DAYS', 30),
        'webhook_log_days' => (int) env('RETENTION_WEBHOOK_LOG_DAYS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Privasi
    |--------------------------------------------------------------------------
    */

    'privacy' => [
        /*
         * Masa tunggu sebelum akun benar-benar dihapus.
         *
         * Penghapusan ditunda, bukan langsung dijalankan. Tiga alasannya ada di
         * ProfileController::requestDeletion(); yang paling penting: pengajuan
         * yang tidak sengaja bisa dibatalkan cukup dengan masuk kembali.
         *
         * 30 hari mengikuti kebiasaan yang dipakai layanan sejenis dan cukup
         * lama untuk mencakup orang yang jarang membuka aplikasinya.
         */
        'deletion_grace_days' => (int) env('PRIVACY_DELETION_GRACE_DAYS', 30),

        /*
         * Yang TIDAK ikut terhapus: wallet_transactions.
         *
         * Riwayat keuangan wajib disimpan untuk kewajiban pelaporan, dan
         * tabelnya append-only yang ditegakkan trigger database. Yang dihapus
         * adalah data pribadi — nama, nomor HP, email, alamat tersimpan — bukan
         * jejak uangnya. Baris ledger yang tertinggal menunjuk ke dompet tanpa
         * pemilik yang bisa diidentifikasi, dan itu memang bentuk yang benar.
         */
        'anonymize_instead_of_delete' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Retensi Log Operasional
    |--------------------------------------------------------------------------
    |
    | Dipakai `antaride:prune-logs`. Angkanya kebijakan, bukan detail teknis —
    | karena itu di config dan bisa diubah lewat env tanpa menyentuh kode.
    |
    | Yang TIDAK ada di sini, dan tidak boleh ditambahkan:
    |
    |   wallet_transactions   append-only, ditegakkan trigger database. Ini buku
    |                         besar keuangan.
    |   orders                riwayat penumpang dan dasar sengketa.
    |   audit_logs            justru yang paling dibutuhkan saat ada investigasi.
    |
    | Umurnya berbeda per tabel karena nilainya turun dengan laju yang berbeda.
    | Yang paling pendek adalah idempotency_keys: kuncinya hanya berguna selama
    | percobaan ulang masih mungkin, yaitu hitungan menit.
    |
    */

    'retention' => [
        'order_status_logs_days' => (int) env('RETENTION_ORDER_STATUS_LOGS_DAYS', 90),
        'order_offers_days' => (int) env('RETENTION_ORDER_OFFERS_DAYS', 60),

        // Lebih lama dari yang lain: keduanya dibaca saat ada investigasi
        // keamanan atau rekonsiliasi gateway, dan keduanya selalu tentang
        // kejadian yang sudah lewat berminggu-minggu.
        'admin_login_attempts_days' => (int) env('RETENTION_ADMIN_LOGIN_ATTEMPTS_DAYS', 180),
        'payment_webhook_logs_days' => (int) env('RETENTION_PAYMENT_WEBHOOK_LOGS_DAYS', 180),

        'idempotency_keys_days' => (int) env('RETENTION_IDEMPOTENCY_KEYS_DAYS', 7),
    ],

];
