# Deploy Antaride Backend

Panduan ini menargetkan **satu VPS Ubuntu 22.04/24.04**, aplikasi di
**subfolder** `https://domain-anda.id/antaride/`, dan tetap bisa dijalankan di
lokal `http://127.0.0.1:8000/` **tanpa mengubah satu baris kode**.

Berkas pendukungnya ada di [deploy/](deploy/):

```
deploy/
├── env.production.example        # .env produksi, dengan penjelasan tiap nilai
├── nginx/
│   ├── antaride-subfolder.conf   # domain.com/antaride/   ← panduan ini
│   └── antaride-subdomain.conf   # api.domain.com/        ← lebih disarankan
├── apache/
│   └── antaride-subfolder.conf   # kalau server sudah pakai Apache
├── systemd/
│   ├── antaride-octane.service
│   ├── antaride-queue@.service   # unit template: @matching, @payments, @default
│   ├── antaride-queues.target
│   ├── antaride-scheduler.service
│   ├── antaride-scheduler.timer
│   ├── antaride-location.service
│   └── redis-override.conf
└── redis/
    └── antaride.conf
```

---

## Yang paling penting di seluruh dokumen ini

**Subfolder-nya hilang kalau satu hal terlewat.**

Octane mendengarkan di `127.0.0.1:8000` dan melayani dari **akar**. Nginx yang
memotong `/antaride` sebelum meneruskan — jadi Laravel melihat `/admin/login`,
bukan `/antaride/admin/login`.

Akibatnya kalau tidak ditangani: setiap `route()`, `url()`, dan `asset()`
menghasilkan tautan **tanpa** subfolder. Halaman pertamanya terbuka — Anda
mengetik URL-nya sendiri — lalu setiap link di dalamnya 404. Termasuk form login,
yang `action`-nya menunjuk ke luar subfolder.

Yang menyelesaikannya **satu header**, dan dia butuh dua sisi:

| Sisi | Yang harus ada |
|---|---|
| Nginx | `proxy_set_header X-Forwarded-Prefix /antaride;` |
| Laravel | `Request::HEADER_X_FORWARDED_PREFIX` di `trustProxies` — sudah ada di `bootstrap/app.php` |

Baris Laravel-nya **bukan bawaan framework**. Laravel tidak memasukkan
`X-Forwarded-Prefix` ke daftar header proxy bawaannya, jadi itu tambahan manual —
dan tambahan manual yang tidak diuji akan hilang pada merge berikutnya.

Karena itu ada `tests/Feature/Http/SubfolderDeploymentTest.php` (9 test). Jalankan
setelah setiap perubahan pada `bootstrap/app.php`:

```bash
php artisan test tests/Feature/Http/SubfolderDeploymentTest.php
```

**Kegagalannya tidak terlihat di lokal sama sekali.** Di lokal tidak ada proxy dan
tidak ada subfolder; seluruh test lain lulus. Yang pertama menemukannya adalah
orang yang membuka panel admin di server — dan yang dia lihat 404, bukan pesan
yang menyebut header apa pun.

---

## Pertimbangkan subdomain sebelum melanjutkan

Subfolder bekerja, dan panduan ini membuatnya bekerja. Tapi kalau Anda bisa
memilih, **subdomain lebih sederhana**:

|  | Subfolder | Subdomain |
|---|---|---|
| `X-Forwarded-Prefix` | wajib, dan satu-satunya penjaganya adalah test | tidak perlu |
| `rewrite` di Nginx | wajib | tidak perlu |
| Symlink untuk aset statis | wajib | tidak perlu |
| `SESSION_PATH` | harus subfolder, kalau tidak cookie tabrakan dengan aplikasi lain | `/` |
| Lapisan khusus admin (allowlist IP di Nginx, basic auth staging) | satu server block untuk semuanya | bisa dipisah |

Kalau memilih subdomain: pakai `deploy/nginx/antaride-subdomain.conf`, dan di
`.env` set `APP_URL=https://api.domain-anda.id` tanpa subfolder,
`SESSION_PATH=/`. Sisa panduan ini tetap berlaku.

---

## 1. Paket sistem

```bash
sudo apt update
sudo apt install -y \
    nginx \
    postgresql postgresql-contrib \
    redis-server \
    php8.3-cli php8.3-fpm php8.3-pgsql php8.3-redis php8.3-mbstring \
    php8.3-xml php8.3-curl php8.3-zip php8.3-bcmath php8.3-intl php8.3-gd \
    git unzip curl
```

**`php8.3-gd` tidak opsional.** Validasi `image` dan `dimensions` pada unggahan
dokumen memanggil `getimagesize()`. Tanpa GD, setiap unggahan dokumen driver
ditolak dengan galat validasi yang tidak menyebut GD — dan tidak ada satu pun
driver yang bisa mendaftar.

**`php8.3-redis` juga tidak opsional di produksi.** `.env` produksi memakai
`REDIS_CLIENT=phpredis`; tanpa ekstensinya aplikasi gagal boot.

Composer:

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

Go (untuk layanan lokasi):

```bash
sudo apt install -y golang-go
go version   # minimal 1.22
```

---

## 2. PostgreSQL

```bash
sudo -u postgres createuser --pwprompt antaride
sudo -u postgres createdb --owner=antaride antaride
```

**Jangan memakai user `postgres` untuk aplikasi.** Superuser bisa `DROP
DATABASE`, membaca seluruh database lain di instance yang sama, dan menjalankan
`COPY FROM PROGRAM` — yang berarti eksekusi perintah shell. Satu SQL injection
pada koneksi superuser adalah seluruh server.

Ekstensi yang dibutuhkan (jalankan sebagai `postgres`, bukan sebagai `antaride` —
membuat ekstensi menuntut superuser):

```bash
sudo -u postgres psql -d antaride -c 'CREATE EXTENSION IF NOT EXISTS pg_trgm;'
sudo -u postgres psql -d antaride -c 'CREATE EXTENSION IF NOT EXISTS postgis;'
```

PostGIS butuh paketnya lebih dulu:

```bash
sudo apt install -y postgresql-16-postgis-3
```

Kalau PostGIS tidak dipasang, set `GEO_ZONE_DRIVER=native` di `.env`. Salah di
sini **tidak** menghasilkan galat saat boot — `php artisan antaride:health` yang
menandainya GAGAL.

---

## 3. Redis

```bash
sudo cp deploy/redis/antaride.conf /etc/redis/redis-antaride.conf
sudo chown redis:redis /etc/redis/redis-antaride.conf
sudo chmod 640 /etc/redis/redis-antaride.conf
```

Ganti `requirepass` di berkas itu dengan hasil `openssl rand -hex 32`, lalu
tambahkan di **akhir** `/etc/redis/redis.conf`:

```
include /etc/redis/redis-antaride.conf
```

Baris `include` harus di akhir: Redis memakai nilai **terakhir** untuk direktif
yang muncul dua kali. Include di awal berarti seluruh isinya ditimpa.

Override systemd:

```bash
sudo mkdir -p /etc/systemd/system/redis-server.service.d
sudo cp deploy/systemd/redis-override.conf \
    /etc/systemd/system/redis-server.service.d/antaride.conf
sudo systemctl daemon-reload
sudo systemctl restart redis-server
```

Periksa yang paling mudah salah:

```bash
redis-cli -a 'PASSWORD' CONFIG GET maxmemory-policy
# harus: volatile-lru
```

**`allkeys-lru` salah untuk Antaride**, walaupun itu yang paling sering
disarankan. Redis di sini bukan hanya cache — dia memuat posisi driver, quote,
lock order, dan **antrean job**. `allkeys-lru` membuang kunci apa pun saat memori
penuh, termasuk antrean job berisi pembukuan dompet, dan tidak ada satu pun galat
yang muncul. Penjelasan lengkapnya di `deploy/redis/antaride.conf`.

Catat versi Redis-nya:

```bash
redis-cli -a 'PASSWORD' INFO server | grep redis_version
```

Redis 6.2+ → `REDIS_GEO_COMMAND=geosearch`. Redis 5/6.0 → `georadius`. Salah di
sini membuat **pencocokan driver** gagal: order masuk lalu langsung `no_driver`.

---

## 4. Kode

```bash
sudo mkdir -p /var/www
sudo chown www-data:www-data /var/www
sudo -u www-data git clone https://github.com/rendyirawann/antaride-be.git \
    /var/www/antaride-be
cd /var/www/antaride-be

sudo -u www-data composer install --no-dev --optimize-autoloader
```

`--no-dev` bukan sekadar penghematan: paket dev memuat Ignition, yang menampilkan
halaman galat berisi seluruh isi `.env`.

**Tidak ada langkah `npm run build`, dan itu disengaja.** Panel admin memakai aset
Metronic yang sudah jadi di `public/assets` — bukan hasil bundling. Tidak ada satu
pun Blade yang memanggil `@vite`.

Toolchain Vite tetap ada di repo untuk saat dibutuhkan. Kalau nanti ada Blade yang
memakai `@vite`, tambahkan langkah ini ke bagian deploy ulang juga:

```bash
sudo -u www-data npm ci && sudo -u www-data npm run build
```

`/public/build` di-gitignore, jadi tanpa langkah itu `@vite` akan melempar
"Unable to locate file in Vite manifest" — halaman 500, bukan halaman tanpa gaya.

RoadRunner (binernya **tidak** ikut di repo — 61 MB, dan biner Windows tidak bisa
dijalankan di Linux):

```bash
sudo -u www-data ./vendor/bin/rr get-binary
sudo -u www-data chmod +x rr
```

### `.env`

```bash
sudo -u www-data cp deploy/env.production.example .env
sudo -u www-data php artisan key:generate
sudo chmod 600 .env
```

Lalu isi seluruh yang bertanda `GANTI`. Yang paling menentukan:

| Kunci | Kenapa |
|---|---|
| `APP_URL=https://domain-anda.id/antaride` | Menentukan URL bertanda tangan dokumen KYC dan callback payment gateway. Subfolder yang hilang di sini membuat pratinjau dokumen 404 dan callback pembayaran tidak pernah sampai. Tanpa garis miring di akhir. |
| `TRUSTED_PROXIES=127.0.0.1,::1` | Tanpa ini, `X-Forwarded-Prefix` diabaikan dan subfolder hilang. **Jangan** diganti `*`. |
| `SESSION_PATH=/antaride` | Cookie dengan path `/` tabrakan dengan aplikasi Laravel lain di domain yang sama; gejalanya staf yang keluar sendiri saat berpindah aplikasi. |
| `APP_DEBUG=false` | `true` menampilkan seluruh isi `.env` — termasuk `APP_KEY` — kepada siapa pun yang memicu galat 500. |
| `LOCATION_SERVICE_SECRET` | Harus **sama** dengan `ANTARIDE_LOCATION_SECRET` di layanan Go. Beda = setiap ping GPS ditolak 401, tanpa galat di mana pun. Nama kuncinya `_SECRET`, bukan `_TOKEN`. |
| `OCTANE_HTTPS=true` | Nginx yang memegang TLS; tanpa ini URL yang dihasilkan di dalam worker berskema `http://`. |

### Migrasi dan seed

```bash
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan db:seed --class=SystemSeeder --force
sudo -u www-data php artisan db:seed --class=CatalogSeeder --force
```

`--force` wajib di `APP_ENV=production` — Laravel menolak menjalankan migrasi
tanpa konfirmasi di sana, dan tidak ada TTY untuk mengonfirmasinya.

### Izin

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

**Jangan** `chmod 777`. Itu memberi izin tulis kepada setiap pengguna di server,
termasuk proses lain yang mungkin sudah ditembus.

### Cache

```bash
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache
sudo -u www-data php artisan event:cache
```

`config:cache` membuat `env()` **berhenti bekerja** di luar berkas config. Itu
perilaku Laravel yang disengaja, dan kode di repo ini sudah memakai `config()` di
mana-mana — tapi kalau nanti ada `env()` yang menyelip di controller, dia akan
mengembalikan null di produksi dan tetap benar di lokal.

### Symlink untuk subfolder

```bash
sudo ln -s /var/www/antaride-be/public /var/www/html/antaride
```

Ini yang membuat Nginx bisa melayani 1.677 berkas aset Metronic langsung, tanpa
`alias` — kombinasi `alias` + `try_files` punya sejarah panjang menghasilkan path
yang salah, dan salahnya senyap: 404 pada berkas yang ada.

Disk publik Laravel:

```bash
sudo -u www-data php artisan storage:link
```

---

## 5. Nginx

```bash
sudo cp deploy/nginx/antaride-subfolder.conf /etc/nginx/sites-available/antaride
sudo nano /etc/nginx/sites-available/antaride     # ganti domain & subfolder
sudo ln -s /etc/nginx/sites-available/antaride /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

Sertifikat:

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d domain-anda.id
```

---

## 6. systemd

```bash
sudo cp deploy/systemd/*.service deploy/systemd/*.timer deploy/systemd/*.target \
    /etc/systemd/system/
sudo systemctl daemon-reload

sudo systemctl enable --now antaride-octane
sudo systemctl enable --now antaride-queue@matching
sudo systemctl enable --now antaride-queue@payments
sudo systemctl enable --now antaride-queue@default
sudo systemctl enable --now antaride-scheduler.timer
sudo systemctl enable antaride-queues.target
```

**Tiga worker queue terpisah, bukan satu.** Job pencocokan driver punya batas
waktu yang nyata — penumpang menunggu, dan tawaran hanya berlaku 15 detik. Satu
worker untuk semuanya berarti satu retry webhook payment gateway yang timeout 30
detik menahan seluruh antrean pencocokan di belakangnya. Yang dilihat penumpang:
"mencari driver" yang tidak pernah selesai, padahal drivernya ada.

### Layanan lokasi (Go)

```bash
sudo useradd --system --no-create-home --shell /usr/sbin/nologin antaride-loc
cd /var/www/antaride-be/services/location-service
sudo go build -o /usr/local/bin/antaride-location .

sudo mkdir -p /etc/antaride
sudo install -m 0600 -o root -g root /dev/null /etc/antaride/location.env
sudo tee /etc/antaride/location.env >/dev/null <<'EOF'
ANTARIDE_LOCATION_ADDR=127.0.0.1:8200
ANTARIDE_LOCATION_SECRET=<sama dengan LOCATION_SERVICE_SECRET di .env>
ANTARIDE_REDIS_ADDR=127.0.0.1:6379
ANTARIDE_REDIS_PASSWORD=<sama dengan REDIS_PASSWORD di .env>
ANTARIDE_REDIS_DB=0
EOF

sudo systemctl enable --now antaride-location
```

Rahasianya disimpan di berkas terpisah ber-permission 0600, **bukan** di unit
systemd: unit systemd bisa dibaca semua pengguna lewat `systemctl cat`.

Layanan ini harus bisa dijangkau dari internet karena aplikasi driver
mengirim ping langsung ke sana. Tambahkan server block Nginx sendiri untuk
`loc.domain-anda.id` yang memproksi ke `127.0.0.1:8200`, dan set
`LOCATION_SERVICE_URL` ke domain itu.

---

## 7. Verifikasi

Jalankan berurutan. Setiap langkah punya kegagalan yang khas.

```bash
sudo -u www-data php artisan antaride:health
```

Memeriksa Postgres, Redis, perintah GEO, prefix Redis, OSRM, dan Centrifugo
sekaligus.

```bash
curl -sS -o /dev/null -w '%{http_code}\n' https://domain-anda.id/antaride/up
# harus: 200
```

**Subfolder benar-benar ikut di URL yang dihasilkan** — ini yang paling penting:

```bash
curl -sS https://domain-anda.id/antaride/admin/login | grep -o 'action="[^"]*"'
# harus memuat /antaride/ di dalamnya
```

Kalau `action` tidak memuat `/antaride/`, berarti `X-Forwarded-Prefix` tidak
sampai. Periksa dua hal: baris `proxy_set_header X-Forwarded-Prefix` di Nginx, dan
`TRUSTED_PROXIES` di `.env`.

**Aset Metronic dilayani Nginx, bukan Octane:**

```bash
curl -sS -o /dev/null -w '%{http_code} %{content_type}\n' \
    https://domain-anda.id/antaride/assets/css/style.bundle.css
# harus: 200 text/css
```

**IP asli pengguna terbaca:**

```bash
sudo -u www-data php artisan tinker --execute="echo request()->ip();"
```

Di CLI ini akan menampilkan `127.0.0.1` — yang benar. Yang perlu diperiksa dari
sisi HTTP: coba login admin dari IP yang salah dan pastikan
`admin_login_attempts` mencatat IP Anda, bukan `127.0.0.1`. Kalau tercatat
`127.0.0.1`, **rate limit OTP menjadi rate limit global** — satu orang yang
meminta OTP berulang memblokir seluruh pengguna.

```bash
systemctl status antaride-octane antaride-location
systemctl list-timers antaride-scheduler
journalctl -u antaride-octane -n 50 --no-pager
```

**Jadwal disetel Asia/Jakarta, `schedule:list` menampilkannya UTC.** `18:30` di
daftar berarti 01:30 WIB. Perbedaan tujuh jam ini yang paling sering
disalahpahami saat memeriksa apakah jadwalnya benar.

---

## 7b. Dokumentasi API (Swagger UI)

Swagger UI di **`/api/documentation`**, dijaga HTTP Basic auth.

Tambahkan ke `.env` produksi:

```
API_DOCS_USERNAME=itds
API_DOCS_PASSWORD=itds123
```

**Kosong berarti halamannya DITUTUP (404), bukan terbuka.** Arah gagal itu
disengaja: `.env` yang lupa memuatnya tidak boleh menerbitkan seluruh permukaan
API — setiap endpoint, setiap nama field, setiap aturan validasi — tanpa ada yang
menyadarinya.

Buat spesifikasinya, lalu periksa:

```bash
sudo -u www-data php artisan scramble:export --path=docs/openapi/openapi.json

curl -sS -o /dev/null -w '%{http_code}\n' \
    https://beoulve-dev.biz.id/antaride-be/api/documentation
# harus: 401   (tanpa kredensial)

curl -sS -o /dev/null -w '%{http_code}\n' -u 'itds:itds123' \
    https://beoulve-dev.biz.id/antaride-be/api/documentation
# harus: 200
```

### Kalau halamannya putih polos

Swagger UI menggambar seluruh halaman dari JavaScript, jadi kegagalan apa pun
berakhir sebagai layar kosong. Halaman ini sudah memuat pesan cadangan yang
tergambar di HTML — kalau yang Anda lihat benar-benar putih tanpa teks sama
sekali, berarti HTML-nya sendiri tidak sampai.

Tiga penyebab, berurutan dari yang paling sering:

| Gejala | Penyebab | Perbaikan |
|---|---|---|
| Kotak merah "Dokumentasi belum tergambar", detail menyebut `swagger-ui-bundle.js` | `public/vendor/swagger-ui/` tidak ikut ter-deploy | `git pull` lalu pastikan direktori itu ada |
| Kotak merah, detail menyebut spesifikasi gagal dimuat | `docs/openapi/openapi.json` belum dibuat | `php artisan scramble:export --path=docs/openapi/openapi.json` |
| Putih total, tanpa teks apa pun | 500 dari Laravel, bukan masalah Swagger | `tail -50 storage/logs/laravel-*.log` |

Periksa asetnya langsung:

```bash
curl -sS -o /dev/null -w '%{http_code} %{content_type}\n' \
    https://beoulve-dev.biz.id/antaride-be/vendor/swagger-ui/swagger-ui-bundle.js
# harus: 200 application/javascript
```

**Aset dilayani sendiri, bukan dari CDN.** Itu disengaja: server tanpa akses
internet keluar — atau jaringan yang memblokir CDN — menghasilkan halaman putih
yang tidak menyebut penyebabnya sama sekali.

---

## 8. Deploy ulang

```bash
cd /var/www/antaride-be

sudo -u www-data php artisan down --render="errors::503"

sudo -u www-data git pull
sudo -u www-data composer install --no-dev --optimize-autoloader
sudo -u www-data php artisan migrate --force

sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache
sudo -u www-data php artisan event:cache

sudo systemctl reload antaride-octane
sudo systemctl restart antaride-queues.target

sudo -u www-data php artisan up
```

**`reload`, bukan `restart`, untuk Octane.** `octane:reload` memberi worker
kesempatan menyelesaikan request yang sedang berjalan sebelum diganti. `restart`
memutusnya — termasuk penyelesaian order yang sedang memindahkan uang, di tengah
transaksi database.

**Worker queue harus `restart`, bukan reload.** Worker memegang kode yang di-load
saat dia start; setelah deploy, worker lama tetap menjalankan kode lama. Job yang
gagal karenanya tidak menyebut penyebabnya. `antaride-queues.target` yang membuat
ketiganya ikut, supaya tidak ada yang terlupa.

---

## 9. Setelah panel siap: isi metrik

Dashboard backoffice membaca `metrics_daily`. Tanpa agregasi, grafik trennya
menampilkan **nol untuk setiap hari** — dan grafik datar itu terbaca sebagai
"tidak ada order", bukan sebagai job yang belum jalan.

```bash
sudo -u www-data php artisan antaride:aggregate-metrics --days=30
```

---

## 10. Arahkan aplikasi Flutter ke server

Di repo `antaride-fe`:

```bash
melos run apk:universal:all
```

Perintah itu menanam `https://beoulve-dev.biz.id/antaride-be/api/v1` dan
menyalin tiga APK universal ke `Desktop/Antaride-APK/`. Untuk server lain:

```bash
export ANTARIDE_API_URL="https://domain-anda.id/antaride/api/v1"
melos run apk:all
```

`ANTARIDE_API_URL` **harus memuat subfolder**, dan berakhir di `/api/v1`.

Garis miring di akhir **aman** — Dio meruntuhkan garis miring ganda, jadi
`.../api/v1/` + `/driver/status` tetap menghasilkan satu garis miring. Itu
diverifikasi, bukan diasumsikan: lihat
`packages/antaride_api/test/base_url_test.dart` di repo `antaride-fe` (6 test),
yang menyatakan perilaku penggabungan URL yang kita andalkan — termasuk untuk
unggahan multipart, yang memakai jalur `Options` tersendiri.

Yang **tidak** aman: menghilangkan subfolder-nya. Server menjawab 404 HTML,
`ApiClient` menguraikannya sebagai response yang bukan JSON, dan yang muncul di
layar adalah "Terjadi gangguan. Coba lagi." pada **setiap** layar — tanpa satu
pun petunjuk bahwa masalahnya alamat.

---

## Yang belum dicakup panduan ini

| Hal | Keadaan |
|---|---|
| **OSRM** | Wajib ada — tanpa jarak tidak ada harga. Butuh data OSM Indonesia dan RAM yang cukup untuk pra-prosesnya; pemasangannya berdiri sendiri. |
| **Centrifugo** | Belum terpasang. Aplikasi jatuh ke penarikan berkala, yang bekerja tapi menunda pembaruan status beberapa detik. |
| **Backup** | Belum ada. Yang paling penting: `pg_dump` harian, dan `storage/app/private/kyc` — dokumen identitas tidak bisa dibuat ulang. |
| **Log rotation** | `LOG_STACK=daily` + `LOG_DAILY_DAYS=14` menangani log Laravel. Log Nginx ditangani logrotate bawaan paket. |
| **Aset Metronic di repo publik** | Metronic template berbayar, dan repo ini publik — lisensinya melarang redistribusi source-nya. Keputusan sadar; kalau berubah pikiran, `gh repo edit rendyirawann/antaride-be --visibility private`. |
| **Firewall** | Belum disetel. Yang harus tertutup dari internet: 5432 (Postgres), 6379 (Redis), 8000 (Octane), 8200 (layanan lokasi, kalau diproksi Nginx). |

---

## Deploy PERUBAHAN ke server yang sudah jalan

Untuk server yang sudah di-deploy dari panduan di atas, ini langkah **tambahan**
yang dibutuhkan perubahan Swagger UI. Selebihnya sudah tercakup bagian 8.

```bash
cd /var/www/antaride-be

sudo -u www-data php artisan down --render="errors::503"

sudo -u www-data git pull
sudo -u www-data composer install --no-dev --optimize-autoloader
sudo -u www-data php artisan migrate --force

# BARU: buat spesifikasi OpenAPI.
#
# Tanpa langkah ini halaman dokumentasi mencoba membuatnya sendiri pada
# pemuatan pertama — yang berhasil, tapi memakan beberapa detik dan
# menjalankan analisis statis di dalam request web.
sudo -u www-data php artisan scramble:export --path=docs/openapi/openapi.json

sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache
sudo -u www-data php artisan event:cache

sudo systemctl reload antaride-octane
sudo systemctl restart antaride-queues.target

sudo -u www-data php artisan up
```

Sebelum `config:cache`, pastikan `.env` sudah memuat:

```
API_DOCS_USERNAME=itds
API_DOCS_PASSWORD=itds123
```

`config:cache` membekukan nilai `env()`. Menambahkannya SETELAH cache dibuat
berarti nilainya tidak terbaca, dan halaman dokumentasi menjawab 404 — yang
terlihat seperti route yang tidak terdaftar, bukan seperti konfigurasi yang
kurang.

Verifikasi setelah `up`:

```bash
curl -sS -o /dev/null -w 'tanpa auth : %{http_code}\n' \
    https://beoulve-dev.biz.id/antaride-be/api/documentation

curl -sS -o /dev/null -w 'dengan auth: %{http_code}\n' -u 'itds:itds123' \
    https://beoulve-dev.biz.id/antaride-be/api/documentation

curl -sS -u 'itds:itds123' \
    https://beoulve-dev.biz.id/antaride-be/api/documentation/openapi.json \
    | head -c 120
```

Harus berturut-turut: `401`, `200`, dan JSON yang dimulai `{"openapi":"3.1.0"`.

---

## Deploy akun demo

Ini deploy **terpisah** dari yang di atas, dan disengaja terpisah: yang ini
menyalakan endpoint yang **menerbitkan token tanpa OTP**. Membacanya sebagai
satu bagian tersendiri lebih baik daripada menyembunyikannya di antara langkah
rutin.

### Kenapa ini dibutuhkan sama sekali

OTP di proyek ini **tidak dikirim ke mana pun**. Satu-satunya pengirim yang
terpasang, `LogSmsSender`, menulis kodenya ke berkas log — dan di produksi
`app()->isProduction()` menyembunyikan kode itu juga dari balasan API.

Akibatnya di server ini: **tidak ada seorang pun yang bisa masuk**. Bukan
sulit — tidak bisa. Sampai gateway SMS sungguhan terpasang, akun demo satu-
satunya cara aplikasi bisa diuji di server sama sekali.

### Yang membatasinya

Tiga hal, dan ketiganya harus dilewati sekaligus:

1. **Mati kecuali `ANTARIDE_DEMO_LOGIN=true`.** Server yang lupa menyetelnya
   MENOLAK — kelalaian berakhir tertutup, bukan terbuka.
2. **Hanya akun bertanda `demo_role`.** Akun sungguhan tidak bisa dimasuki
   lewat endpoint ini bahkan kalau uuid-nya diketahui.
3. **Setiap pemakaian dicatat** ke `storage/logs/demo-*.log` beserta IP-nya.

### Langkah

```bash
cd /var/www/antaride-be

sudo -u www-data php artisan down --render="errors::503"

sudo -u www-data git pull
sudo -u www-data composer install --no-dev --optimize-autoloader
sudo -u www-data php artisan migrate --force

# Membuat tiga akun demo: penumpang, driver, merchant.
#
# Aman dijalankan ulang — seeder-nya updateOrCreate berdasarkan nomor HP,
# bukan create. Menjalankannya dua kali tidak menghasilkan enam akun.
sudo -u www-data php artisan db:seed --force --class=DemoAccountSeeder

sudo -u www-data php artisan scramble:export --path=docs/openapi/openapi.json

sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache
sudo -u www-data php artisan event:cache

sudo systemctl reload antaride-octane
sudo systemctl restart antaride-queues.target

sudo -u www-data php artisan up
```

**Sebelum `config:cache`**, `.env` harus memuat:

```
ANTARIDE_DEMO_LOGIN=true
```

Alasannya sama dengan `API_DOCS_*` di bagian sebelumnya: `config:cache`
membekukan nilai `env()`. Menambahkannya setelah cache dibuat berarti fiturnya
tetap mati, dan yang terlihat di aplikasi bukan pesan galat melainkan **layar
masuk tanpa daftar akun demo sama sekali** — karena widget-nya memang
menyembunyikan diri saat fiturnya mati. Gejalanya terbaca seperti aplikasi
lama yang belum ter-update.

### Verifikasi

```bash
curl -sS https://beoulve-dev.biz.id/antaride-be/api/v1/auth/demo/accounts | head -c 400
```

Harus mengembalikan `"enabled":true` dan tiga akun. Kalau `"enabled":false`,
`.env`-nya belum terbaca — ulangi `config:cache`. Kalau `enabled:true` tapi
daftarnya kosong, seeder-nya belum jalan.

Lalu buktikan tokennya benar-benar terbit:

```bash
UUID=$(curl -sS https://beoulve-dev.biz.id/antaride-be/api/v1/auth/demo/accounts \
    | python3 -c 'import sys,json; print(json.load(sys.stdin)["data"]["accounts"][0]["uuid"])')

curl -sS -X POST https://beoulve-dev.biz.id/antaride-be/api/v1/auth/demo/login \
    -H 'Content-Type: application/json' \
    -d "{\"uuid\":\"$UUID\"}" | head -c 200
```

### Driver demo sengaja diberi saldo Rp 100.000

Ini bukan angka hiasan. `onlyWithCashDeposit` **menyaring keluar** driver yang
saldonya di bawah batas deposit tunai, dan penyaringan itu terjadi tanpa satu
pun galat: driver-nya online, posisinya tercatat di Redis, tapi tidak pernah
ditawari order apa pun.

Ditemukan lewat UAT sebelumnya, dan gejalanya persis terbaca seperti "matching
rusak". Kalau seeder-nya diubah, saldo ini harus tetap di atas
`antaride.wallet.driver_cash_deposit_minimum`.

### Mematikannya nanti

Saat gateway SMS sungguhan sudah terpasang:

```bash
# .env
ANTARIDE_DEMO_LOGIN=false
```

lalu `php artisan config:cache` dan reload Octane. Akun-akunnya boleh
ditinggal — tanpa flag itu, endpoint-nya menolak semuanya.

---

## Deploy area layanan + pencarian alamat

Tiga perubahan sekaligus: nama layanan jadi nama merek, area layanan pindah ke
`.env`, dan pencarian alamat (autocomplete) lewat Nominatim.

### 1. Kode dan migrasi

```bash
cd /var/www/antaride-be

sudo -u www-data php artisan down --render="errors::503"

sudo -u www-data git pull
sudo -u www-data composer install --no-dev --optimize-autoloader

# Mengubah nama layanan: "Antar Motor" -> "Antaride",
# "Antar Barang" -> "AntarExpress".
#
# Lewat migrasi, BUKAN `db:seed`. CatalogSeeder memakai insertGetId, jadi
# menjalankannya lagi akan membuat baris layanan KEDUA dengan kode yang sama —
# dan sejak itu quote bisa menunjuk ke salah satu dari dua baris dengan tarif
# yang berbeda.
sudo -u www-data php artisan migrate --force

sudo -u www-data php artisan scramble:export --path=docs/openapi/openapi.json
```

### 2. `.env` — area layanan

Ditambahkan **sebelum** `config:cache`:

```
ANTARIDE_AREA_LAT=3.5697
ANTARIDE_AREA_LNG=98.7748
ANTARIDE_AREA_RADIUS_KM=35
ANTARIDE_AREA_LABEL="Medan dan Lubuk Pakam"
ANTARIDE_AREA_ZOOM=12
```

Nilai di atas titik tengah antara Medan dan Lubuk Pakam, radius 35 km — cukup
melingkupi keduanya (jaraknya sekitar 23 km) beserta sekitarnya.

**Angka ini harus cocok dengan cakupan OSRM Anda.** Kalau OSRM hanya memuat
sekitar Lubuk Pakam, sempitkan areanya — kalau tidak, aplikasi membuka peta di
wilayah yang OSRM-nya tidak bisa menghitung rute, dan yang dilihat pengguna
bukan pesan galat melainkan ongkos yang tidak pernah muncul.

Untuk hanya Lubuk Pakam:

```
ANTARIDE_AREA_LAT=3.5497
ANTARIDE_AREA_LNG=98.8756
ANTARIDE_AREA_RADIUS_KM=15
ANTARIDE_AREA_LABEL="Lubuk Pakam"
```

Aplikasi membacanya lewat `GET /api/v1/config` **setiap kali dibuka**, jadi
menggesernya tidak menuntut membangun ulang APK.

### 3. Nominatim (pencarian alamat)

Tanpa ini, kolom pencarian alamat **tidak muncul sama sekali** di aplikasi —
bukan muncul lalu gagal. Pemesanan tetap bekerja penuh lewat geser peta.

Cara paling ringan adalah image resmi. Ukuran datanya mengikuti wilayah yang
diimpor; Sumatera Utara sekitar 200 MB dan impornya memakan belasan menit.

```bash
# Ambil ekstrak OSM yang SAMA dengan yang dipakai OSRM Anda. Memakai ekstrak
# berbeda berarti alamat yang bisa dicari tidak sama dengan jalan yang bisa
# dirutekan — dan selisih itu muncul sebagai alamat yang ditemukan tapi
# ongkosnya gagal dihitung.
cd /srv
sudo mkdir -p nominatim && cd nominatim
sudo wget https://download.geofabrik.de/asia/indonesia/sumatera-utara-latest.osm.pbf

sudo docker run -d --name nominatim \
    -e PBF_PATH=/data/sumatera-utara-latest.osm.pbf \
    -e IMPORT_WIKIPEDIA=false \
    -e NOMINATIM_PASSWORD="$(openssl rand -hex 16)" \
    -v /srv/nominatim:/data \
    -p 127.0.0.1:8080:8080 \
    --shm-size=1g \
    --restart unless-stopped \
    mediagis/nominatim:4.4

# Impor berjalan di latar. Pantau sampai selesai:
sudo docker logs -f nominatim
```

`-p 127.0.0.1:8080` mengikatnya ke localhost saja — Nominatim tidak punya
autentikasi, dan instans yang terbuka ke internet akan dipakai orang lain.

Lalu di `.env`:

```
NOMINATIM_ENABLED=true
NOMINATIM_URL=http://127.0.0.1:8080
NOMINATIM_EMAIL=bayuapriansah10@gmail.com
NOMINATIM_CACHE_HOURS=72
```

`NOMINATIM_ENABLED` sengaja terpisah dari url-nya. Sakelar yang disimpulkan
dari alamat ("masih localhost berarti belum terpasang") salah dua kali: server
yang MEMANG memasang Nominatim di localhost akan dianggap belum, dan
pemeriksaannya menuntut `env()` dibaca di luar berkas config — yang
mengembalikan null tepat setelah `config:cache`.

### 4. Selesaikan deploy

```bash
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache
sudo -u www-data php artisan event:cache

sudo systemctl reload antaride-octane
sudo systemctl restart antaride-queues.target

sudo -u www-data php artisan up
```

### Verifikasi

```bash
curl -sS https://beoulve-dev.biz.id/antaride-be/api/v1/config
```

Harus memuat `area` dengan koordinat yang Anda setel, dan
`"places_enabled":true`. Kalau `false`, `.env`-nya belum terbaca — ulangi
`config:cache`.

Nama layanan:

```bash
curl -sS https://beoulve-dev.biz.id/antaride-be/api/v1/service-types \
    | python3 -m json.tool | grep -E '"name"|"code"'
```

Harus menampilkan `Antaride` untuk `ride_bike` dan `AntarExpress` untuk `send`.

Pencarian alamat (butuh token — pakai akun demo):

```bash
UUID=$(curl -sS https://beoulve-dev.biz.id/antaride-be/api/v1/auth/demo/accounts \
    | python3 -c 'import sys,json; print(json.load(sys.stdin)["data"]["accounts"][0]["uuid"])')

TOKEN=$(curl -sS -X POST https://beoulve-dev.biz.id/antaride-be/api/v1/auth/demo/login \
    -H 'Content-Type: application/json' -d "{\"uuid\":\"$UUID\"}" \
    | python3 -c 'import sys,json; print(json.load(sys.stdin)["data"]["token"])')

curl -sS -H "Authorization: Bearer $TOKEN" \
    'https://beoulve-dev.biz.id/antaride-be/api/v1/places/search?q=lubuk%20pakam'
```

Daftar kosong berarti Nominatim belum bisa dihubungi — periksa
`storage/logs/laravel-*.log`, pesannya "Nominatim tidak bisa dihubungi".

### Kalau memakai Nominatim publik untuk uji coba

Bisa, dan hanya untuk uji coba:

```
NOMINATIM_URL=https://nominatim.openstreetmap.org
```

Kebijakan penggunaannya membatasi **1 permintaan per detik** dan **melarang
pemakaian autocomplete** — setiap ketikan adalah satu permintaan, dan pemakaian
seperti itu diblokir per IP. Jangan dipakai untuk pengguna sungguhan.
