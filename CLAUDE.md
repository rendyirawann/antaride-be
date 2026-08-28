# Antaride Backend

API mobile (Swagger) + panel backoffice untuk platform ride-hailing & delivery.
Blueprint sumber: `../blueprint-superapp-ride-hailing.md`, `../blueprint-admin-web.md`.

Prinsip utama yang mengatur seluruh kode ini: **mobile tidak dipercaya sama
sekali.** Semua yang datang dari HP dianggap bisa dipalsukan. Harga, jarak,
komisi, dan status order dihitung di sini, bukan di sana.

---

## Stack

| Lapisan | Pilihan | Catatan |
|---|---|---|
| Framework | Laravel 13.29, PHP 8.3 ZTS | |
| App server | Octane + **RoadRunner** | Swoole tidak punya build Windows; FrankenPHP masih eksperimental di sana. Produksi Linux boleh pindah ke FrankenPHP lewat `OCTANE_SERVER` |
| Database | **PostgreSQL 18** port 5433 | Bukan MySQL. Alasannya di bawah |
| Cache/queue/geo | Redis | Versi lokal 5.0.14 — lihat batasan di bawah |
| Realtime | Centrifugo (Go) | Laravel tidak memegang koneksi WebSocket |
| Location service | Go, port 8200 | Ping GPS tidak pernah menyentuh PHP |
| Routing/ETA | OSRM self-host, port 5000 | Google Places hanya untuk autocomplete |
| Panel admin | Blade + **Metronic 8** (Demo 11) + Bootstrap 5 + Alpine | Bukan Inertia/React |
| Tabel admin | Yajra DataTables (server-side) | |
| RBAC | Spatie Permission, guard `admin` | |
| API docs | Scramble → `/docs/api` | Spec diekspor ke `docs/openapi/openapi.json` |
| Mobile | Flutter (repo terpisah `antaride-fe`) | Client Dart di-generate dari spec di atas |

---

## Kenapa PostgreSQL, bukan MySQL seperti di blueprint

Empat hal yang tidak bisa dilakukan MySQL, dan ketiganya sudah diuji fungsional:

1. **Partial unique index.** Blueprint mengakui MySQL memaksa tabel bayangan
   `driver_active_orders` untuk menegakkan "satu driver, satu order berjalan".
   Di sini invariant itu satu baris index pada `orders` (lihat
   `orders_one_active_per_driver`), jadi tidak ada enam tempat yang harus ingat
   INSERT dan DELETE.
2. **Exclusion constraint.** `pricing_rules_no_overlap` mencegah dua tarif aktif
   dengan periode bertumpang tindih untuk pasangan (layanan, zona) yang sama.
   Ini yang membuat "berapa tarif pada tanggal X" selalu punya tepat satu
   jawaban saat ada sengketa ongkos.
3. **JSONB + GIN.** `metadata`, `evidence`, `score_breakdown`, `raw_callback`
   perlu difilter dari panel admin.
4. **Constraint trigger DEFERRABLE.** `wallet_transactions_balanced` diperiksa
   saat COMMIT, bukan per baris, jadi pembukuan berpasangan bisa ditegakkan
   database: satu peristiwa yang tidak berjumlah nol dibatalkan seluruhnya.
   MySQL tidak punya ini, dan menegakkannya di kode aplikasi berarti setiap
   jalur baru harus ingat memeriksanya.

**Tidak ada partisi tabel.** Keputusan proyek, sesuai permintaan: retensi
dijalankan dengan DELETE bertahap lewat `antaride:prune-logs`, bukan DROP
PARTITION. Tabel yang paling besar (`order_status_logs`, `order_offers`) punya
index yang mendukung penghapusan per rentang waktu.

Perlu dicatat: **tidak ada tabel `driver_locations`.** Ping GPS driver hanya
masuk Redis lewat GEOADD dan dibuang sendiri lewat TTL — tidak pernah menyentuh
Postgres. Itu keputusan arsitektur, bukan kelalaian.

Bonus: `pg_trgm` membuat `ILIKE '%budi%'` tetap terindeks, jadi pencarian CS
tidak butuh Meilisearch di Fase 1.

---

## Batasan environment lokal yang WAJIB diingat

Ketiganya pernah membuat sistem gagal tanpa satu pun pesan error.

**Redis 5.0.14 tidak punya `GEOSEARCH`** (baru ada di 6.2). Semua operasi GEO
harus lewat `GEORADIUS`. Diatur oleh `REDIS_GEO_COMMAND`, dan
`app/Infrastructure/Redis/Geo/` yang menyembunyikan perbedaannya. Jangan panggil
`GEOSEARCH` langsung dari kode domain.

**Koneksi Redis `shared` tidak boleh berprefix.** Location service Go menulis key
mentah `drv:loc:ride_bike`. Kalau kode PHP memakai koneksi `default` yang
berprefix, matching akan membaca set kosong sementara Go menulis ke key lain,
dan tidak ada error yang muncul untuk menjelaskannya. Untuk semua key bersama
(posisi driver, lock order, offer, quote): `Redis::connection('shared')`.

**Horizon tidak bisa jalan di Windows** — butuh `ext-pcntl` yang tidak ada di
platform ini. Development memakai `queue:work` dengan tiga proses terpisah;
Horizon hanya untuk produksi Linux. `composer.json` punya `config.platform`
agar `composer install` tetap berhasil di Windows.

Jalankan `php artisan antaride:health` untuk memeriksa ketiganya sekaligus.

---

## Struktur

```
app/
├── Domain/              Logika bisnis. TIDAK tahu apa pun soal HTTP, Blade,
│                        Redis, atau Postgres. Per modul: Actions/ Models/
│                        DTOs/ Enums/ Events/ Exceptions/
│   ├── Identity/ Driver/ Merchant/ Catalog/ Pricing/ Ordering/
│   ├── Matching/ Wallet/ Payment/ Promo/ Support/ Approval/ Metrics/
│   └── Shared/          Money, Coordinate, Polyline, casts, contracts
│
├── Infrastructure/      Adapter ke dunia luar. Satu-satunya lapisan yang
│                        tahu nama perintah Redis dan bentuk response OSRM.
│   ├── Redis/ Routing/ Realtime/ Payment/ Push/ Geo/ Storage/
│
├── Http/
│   ├── Controllers/Api/V1/{Auth,Customer,Driver,Merchant}/
│   ├── Controllers/Backend/                panel admin
│   ├── Controllers/Webhook/
│   └── Middleware/ Requests/ Resources/ Responses/
│
└── Providers/DomainServiceProvider.php     seam Domain ↔ Infrastructure
```

Aturan yang tidak boleh dilanggar: ketika admin memaksa assign driver ke sebuah
order, controller admin memanggil Action yang **sama** dengan alur normal,
bukan menulis UPDATE query sendiri. Itu sebabnya panel admin dan mobile tidak
pernah punya perilaku berbeda untuk hal yang sama.

---

## Entry point

Empat, dipisah karena karakter dan permukaan serangannya beda jauh:

| File route | Prefix lokal | Auth | Catatan |
|---|---|---|---|
| `routes/api_v1.php` | `/api/v1` | Sanctum token | Stateless, tanpa session |
| `routes/admin.php` | `/admin` | session, guard `admin` | CSRF, 2FA, allowlist IP, timeout sesi |
| `routes/webhook.php` | `/webhooks` | tanda tangan provider | Tanpa CSRF |
| `routes/web.php` | `/` | — | Hanya redirect |

Di produksi admin dan api pindah ke subdomain lewat `ADMIN_DOMAIN` / `API_DOMAIN`.

---

## Konvensi

**Uang.** `BIGINT` Rupiah utuh. Tidak ada FLOAT untuk uang, pernah, sama sekali.

**Identifier.** `id` bigint untuk relasi internal, `uuid` (v7, terurut waktu)
untuk API dan URL. Auto-increment tidak pernah bocor ke publik.

**Saldo.** Bukan angka yang di-UPDATE. Saldo adalah hasil akumulasi
`wallet_transactions` yang APPEND ONLY. `wallets.balance` hanya cache yang
diperbarui di transaksi DB yang sama.

**Enum.** PHP enum sebagai sumber kebenaran, ditambah CHECK constraint di
database sebagai jaring terakhir. Keduanya harus sinkron; ada test yang
membandingkannya.

**Status order.** Hanya berubah lewat state machine dengan transisi eksplisit.
Setiap transisi jalan dalam DB transaction, mencatat ke `order_status_logs`,
dan melempar event ke Centrifugo.

**Idempotency.** Wajib untuk setiap endpoint yang membuat uang bergerak
(`middleware('idempotency')`). Client mengirim header `Idempotency-Key`.

**Harga.** Tidak pernah dari client. Client mengirim `quote_id`, backend membaca
harganya dari Redis.

**Otorisasi admin.** Ditegakkan di route dengan `can:`, bukan hanya dengan
menyembunyikan tombol di Blade.

**Query panel admin.** Cursor pagination, bukan offset. Tidak ada `COUNT(*)`
pada tabel order.

**Notifikasi.** Push notification (FCM) **ditunda** atas keputusan proyek. Yang
menggantikannya ada dua, dan keduanya bekerja dengan cara yang berbeda:

| | Mekanisme |
|---|---|
| Mobile (penumpang + driver) | Baris di tabel `notifications`, dibaca lewat `/api/v1/notifications`. Dibuat `SendNotification`, dipanggil dari `OrderStateMachine::beriTahu()` **di luar** transaksi. |
| Backoffice admin | `BuildAdminAlerts` — **diturunkan dari keadaan sekarang**, bukan disimpan sebagai baris. |

Perbedaan itu bukan inkonsistensi. Notifikasi yang disimpan bisa **basi**: baris
"2 approval menunggu" yang dibuat kemarin tetap berbunyi begitu walaupun keduanya
sudah disetujui, dan tim ops akan mengejar pekerjaan yang sudah selesai. Yang
diturunkan dari keadaan tidak bisa basi. Karena itu `notifications.recipient_type`
sengaja **tidak punya** nilai `admin`.

Tiga aturan yang tidak boleh dilanggar di sisi ini:

1. **`SendNotification` tidak pernah melempar,** dan menelan exception saja tidak
   cukup. Insert-nya dibungkus `DB::transaction()` untuk mendapat SAVEPOINT —
   PostgreSQL membatalkan SELURUH transaksi begitu satu pernyataan gagal
   (SQLSTATE 25P02), jadi notifikasi yang gagal akan meracuni transaksi
   pemanggilnya walaupun exception-nya sudah ditangkap. Yang gagal berikutnya
   adalah pekerjaan yang sebenarnya: penyimpanan status order, pembukuan dompet.

2. **Tawaran order TIDAK lewat notifikasi.** Tawaran hanya berlaku 15 detik, dan
   notifikasi yang baru terbaca saat aplikasi dibuka akan selalu sudah
   kadaluarsa. Tawaran tetap dijemput aplikasi driver lewat penarikan berkala.

3. **`?as=` yang menentukan notifikasi siapa, bukan akunnya.** Satu orang bisa
   jadi penumpang DAN driver dengan akun yang sama — driver memesan ojek saat
   kendaraannya di bengkel. Menyimpulkannya dari "apakah akun ini punya baris di
   tabel `drivers`" berarti setiap driver yang memesan ojek melihat notifikasi
   drivernya di aplikasi penumpang, dan tidak pernah melihat notifikasi
   penumpangnya.

**Lonceng admin ada di SETIAP halaman panel,** dan `route()` melempar pada nama
route yang tidak terdaftar. Jadi satu nama yang salah ketik di `BuildAdminAlerts`
membuat seluruh backoffice tidak bisa dibuka — bukan hanya loncengnya yang
kosong. Karena itu setiap URL di sana dijaga `Route::has()`, dan ada test yang
memastikan penjaga itu bekerja untuk setiap sumber alert.

---

## Perintah

```
dev.bat                            menu: service multi-tab, migrasi, cache, tes,
                                   pemeliharaan (agregasi metrik, pangkas log)
php artisan antaride:health        cek Postgres, Redis, GEO, prefix, OSRM, Centrifugo
php artisan octane:start --server=roadrunner --host=127.0.0.1 --port=8000 --watch
php artisan scramble:export --path=docs/openapi/openapi.json
vendor\bin\pint                    format kode (wajib sebelum commit)
```

### Pemeliharaan

```
php artisan antaride:aggregate-metrics --today       isi metrics_daily hari ini
php artisan antaride:aggregate-metrics --days=30     isi 30 hari ke belakang
php artisan antaride:prune-logs --dry-run            hitung log yang akan dibuang
php artisan antaride:prune-logs                      buang log lewat masa retensi
php artisan schedule:list                            lihat jadwal (jamnya UTC)
```

Keduanya dijalankan otomatis scheduler — lihat `routes/console.php`. Yang di atas
untuk mengisi data lama setelah `migrate:fresh --seed`, atau memeriksa hasilnya
tanpa menunggu.

**Dashboard backoffice membaca `metrics_daily`.** Tanpa agregasi, grafik trennya
menampilkan nol untuk setiap hari — dan grafik datar itu terbaca sebagai "tidak
ada order", bukan sebagai job yang belum jalan. Untuk panel yang baru disiapkan,
jalankan `--days=30` sekali.

**Jadwal disetel di Asia/Jakarta, `schedule:list` menampilkannya dalam UTC.**
`18:30` di daftar berarti 01:30 WIB. Perbedaan tujuh jam ini yang paling sering
disalahpahami saat memeriksa jadwal.

Yang **tidak pernah** dipangkas: `wallet_transactions` (buku besar, append-only),
`orders` (riwayat penumpang dan dasar sengketa), `audit_logs` (justru yang paling
dibutuhkan saat investigasi). Ada test yang menjaganya tetap begitu.

**Unggahan berkas.** Satu-satunya lapisan yang menulis berkas dari luar ke disk
kita adalah `app/Infrastructure/Storage/ImageStore.php`, dan tiga aturannya tidak
boleh dilanggar:

1. **Nama berkas dari client tidak pernah dipakai.** Yang dipakai `uuid7` beserta
   ekstensi dari PENGENDUSAN isi berkas. Dua driver yang mengunggah `ktp.jpg`
   akan saling menimpa dokumennya, dan verifikator melihat KTP driver A sebagai
   KTP driver B — tanpa satu pun galat.

2. **Tipe MIME dari isi berkas, bukan dari header client.** `getClientMimeType()`
   tidak pernah dipakai; nilainya ditulis client dan bisa berisi apa pun. Tipe
   sebenarnya masuk LOG, bukan response — memberi tahu pengunggah tipe apa yang
   terdeteksi membantu orang yang sedang mencari tipe yang tidak diperiksa.

3. **Berkas disimpan di luar transaksi, barisnya di dalam.** Penulisan disk tidak
   bisa di-rollback. Kalau barisnya gagal ditulis, berkas yang baru dibuang
   secara eksplisit — kalau tidak, setiap kegagalan meninggalkan satu foto KTP
   yang tidak ditunjuk baris mana pun: tidak bisa ditemukan, tidak bisa dihapus
   atas permintaan, dan tidak diketahui ada.

Disknya `kyc` — privat, diakses hanya lewat signed URL berumur lima menit. `.env`
produksi WAJIB memakai disk yang bukan `public`; menaruh dokumen identitas di
sana adalah kebocoran yang menunggu terjadi.

**Dua tempat yang menjawab "apakah driver boleh online" harus sepakat.**
`GoOnline` dan `GET /driver/documents` keduanya memutuskannya, dan keduanya harus
memperhitungkan dokumen `approved` yang `expires_at`-nya sudah lewat. Kalau
tidak: layar driver menyatakan "dokumen lengkap" lalu tombol online ditolak, dan
dia tidak punya satu pun petunjuk karena layarnya sendiri menyatakan dia siap.
Dijaga `tests/Feature/Api/Driver/DriverDocumentUploadTest.php`.

Database lokal: `postgres@127.0.0.1:5433/antaride`.

---

## Yang masih perlu dipasang

- **PostGIS 3.6.2** (versi pertama yang mendukung PostgreSQL 18) lewat
  StackBuilder → Spatial Extensions. Sampai terpasang, set
  `GEO_ZONE_DRIVER=native` atau `antaride:health` akan menandai GAGAL.
- **OSRM** dengan data OSM Indonesia, port 5000.
- **Centrifugo**, port 8100.
- **php_redis** untuk produksi (dev memakai predis).

---

## Layanan lokasi Go

`services/location-service`, port 8200. Ping GPS driver **tidak pernah menyentuh
PHP** — itu keputusan arsitektur, bukan optimasi belakangan: seratus driver
online berarti sekitar 20 ping per detik, dan tiap satunya hanya menulis satu
GEOADD.

Alurnya:

1. Driver memanggil `POST /api/v1/driver/online`.
2. Laravel membalas `location: {url, ticket}`. Tiketnya ditandatangani
   HMAC-SHA256 (`LocationTicket`), memuat id driver dan daftar layanan aktifnya,
   dan punya masa berlaku.
3. Aplikasi driver mengirim posisi langsung ke layanan Go dengan tiket itu.
4. Go menulis `GEOADD drv:loc:{service}` dengan member `driver:{id}` plus
   `HSET drv:meta:{id}`, TTL 60 detik.

**`GET /api/v1/driver/status` JUGA mengirim tiket** untuk driver yang sesinya
masih terbuka, dan itu bukan duplikasi. Aplikasi driver ditutup Android secara
rutin — kehabisan memori, atau ditutup driver sendiri di antara order. Saat
dibuka lagi, sesinya masih terbuka, jadi `online` TIDAK dipanggil: yang jalan
hanya `status`.

Tanpa tiket di sana, proses baru itu tidak punya tiket dan tidak punya cara
mendapatkannya. Tidak ada satu pun posisi yang terkirim, TTL 60 detik habis, dan
driver keluar dari indeks ketersediaan — sementara layarnya menyatakan dia
online. Satu-satunya jalan keluar sebelumnya adalah menekan offline lalu online,
yang menutup sesinya dan memotong catatan jam kerjanya.

Null saat driver offline, dan itu bukan kelalaian: tiket untuk driver yang tidak
bekerja adalah kemampuan mencatat posisinya sebagai tersedia setelah dia pulang.
Dijaga `tests/Feature/Api/Driver/DriverLocationTicketTest.php`.

Di sisi aplikasi, ping dikirim dari **foreground service Android** — bukan timer
di dalam aplikasi. Android menghentikan pembacaan lokasi untuk aplikasi yang
tidak terlihat, dan driver bekerja dengan layar mati.

Dua hal yang gagal tanpa suara kalau salah:

**Urutan koordinat GEOADD adalah `lng` DULU, lalu `lat`.** Tertukar, driver di
Medan akan muncul di Samudra Hindia — dan `findNearby` tetap mengembalikan hasil,
hanya selalu kosong. Sudah diuji end-to-end: Go menulis posisi, lalu
`DriverLocationIndex` di PHP membacanya kembali di koordinat yang persis sama.

**Rahasia HMAC-nya harus sama di kedua sisi.** Dari
`antaride.location_service.shared_secret`; kalau kosong, keduanya menurunkannya
dari `APP_KEY` dengan cara yang sama. Rahasia yang berbeda berarti SETIAP ping
ditolak 401 — dan yang terlihat di aplikasi driver hanya peringatan "lokasi belum
terkirim" setelah tiga kegagalan.

Layanan ini juga **hanya bisa mempersempit** daftar layanan di tiketnya, tidak
menambah. Driver yang hanya aktif untuk `ride_bike` tidak bisa memasukkan dirinya
ke antrean `ride_car` dengan mengubah request body.
