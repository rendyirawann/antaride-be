@echo off
setlocal EnableExtensions EnableDelayedExpansion
title Antaride Backend - Dev Runner

REM ==========================================================================
REM  Antaride Backend - Development Runner
REM --------------------------------------------------------------------------
REM  Satu pintu masuk untuk menjalankan service dan perintah artisan.
REM
REM  Pilihan [1] membuka SATU window Windows Terminal dengan beberapa tab,
REM  masing-masing memegang satu service. Ini lebih baik daripada beberapa
REM  window terpisah karena semuanya mati bersamaan saat window ditutup, jadi
REM  tidak ada worker yatim yang masih memegang koneksi Postgres.
REM
REM  Catatan Horizon: Horizon TIDAK bisa jalan di Windows karena butuh
REM  ext-pcntl yang tidak ada di platform ini. Di dev kita pakai queue:work
REM  biasa dengan tiga proses terpisah per antrean; di produksi Linux baru
REM  Horizon dipakai supaya dapat dashboard monitoringnya.
REM ==========================================================================

cd /d "%~dp0"
set "ROOT=%CD%"

REM Pengaman: buang backslash di ujung ROOT kalau ada.
REM
REM Ini bukan kehati-hatian berlebihan. Argumen -d milik wt.exe menerima path
REM dalam tanda kutip, dan path yang berakhir backslash membuat rangkaian \"
REM terbaca sebagai tanda kutip yang di-escape. Akibatnya path menelan kutip
REM penutupnya, seluruh argumen setelahnya bergeser, wt jatuh ke profil default
REM dan menjalankan powershell.exe alih-alih perintah yang diminta. Gejalanya
REM membingungkan karena tab tetap terbuka, hanya isinya yang salah.
REM
REM %CD% normalnya tidak berakhir backslash, KECUALI kalau repo ada di akar
REM drive (misal D:\). Satu baris ini menutup kasus itu.
if "%ROOT:~-1%"=="\" set "ROOT=%ROOT:~0,-1%"

REM --- Port, disamakan dengan .env ---
set "APP_HOST=127.0.0.1"
set "APP_PORT=8000"
set "VITE_PORT=5173"
set "CENTRIFUGO_PORT=8100"
set "LOCATION_PORT=8200"

:MENU
cls
echo.
echo   ==================================================================
echo    ANTARIDE BACKEND - Dev Runner
echo   ==================================================================
echo    Root : %ROOT%
echo    API  : http://%APP_HOST%:%APP_PORT%
echo    Admin: http://%APP_HOST%:%APP_PORT%/admin
echo    Docs : http://%APP_HOST%:%APP_PORT%/docs/api
echo   ==================================================================
echo.
echo    SERVICE
echo      1.  Jalankan semua service (satu window, banyak tab)
echo      2.  Jalankan hanya API (Octane + RoadRunner)
echo      3.  Cek kesehatan environment
echo      4.  Hentikan semua service Antaride
echo.
echo    DATABASE
echo      5.  migrate
echo      6.  migrate:fresh --seed   (HAPUS semua data)
echo      7.  db:seed
echo      8.  Buka psql
echo.
echo    KODE
echo      9.  Bersihkan seluruh cache
echo     10.  Generate spec OpenAPI
echo     11.  Jalankan test
echo     12.  Pint (format kode)
echo     13.  Tinker
echo.
echo    PEMELIHARAAN
echo     14.  Agregasi metrik (isi dashboard)
echo     15.  Pangkas log lama (dry-run dulu)
echo     16.  Lihat jadwal terdaftar
echo.
echo      0.  Keluar
echo.
set "PILIH="
set /p "PILIH=   Pilihan: "

if "%PILIH%"=="1"  goto START_ALL
if "%PILIH%"=="2"  goto START_API
if "%PILIH%"=="3"  goto HEALTH
if "%PILIH%"=="4"  goto STOP_ALL
if "%PILIH%"=="5"  goto MIGRATE
if "%PILIH%"=="6"  goto FRESH
if "%PILIH%"=="7"  goto SEED
if "%PILIH%"=="8"  goto PSQL
if "%PILIH%"=="9"  goto CLEAR
if "%PILIH%"=="10" goto OPENAPI
if "%PILIH%"=="11" goto TEST
if "%PILIH%"=="12" goto PINT
if "%PILIH%"=="13" goto TINKER
if "%PILIH%"=="14" goto METRICS
if "%PILIH%"=="15" goto PRUNE
if "%PILIH%"=="16" goto SCHEDULE
if "%PILIH%"=="0"  goto END
goto MENU

REM ==========================================================================
REM  1. Semua service dalam satu window multi-tab
REM ==========================================================================
:START_ALL
cls
echo.
echo   Memeriksa prasyarat...
echo.

call :CHECK_PORT 5433 "PostgreSQL"
if errorlevel 1 goto PREREQ_FAIL
call :CHECK_PORT 6379 "Redis"
if errorlevel 1 goto PREREQ_FAIL

if not exist "%ROOT%\.env" (
    echo   [GAGAL] .env tidak ditemukan. Jalankan: copy .env.example .env
    goto PAUSE_MENU
)
if not exist "%ROOT%\rr.exe" (
    echo   [GAGAL] rr.exe tidak ditemukan. Jalankan: php artisan octane:install --server=roadrunner
    goto PAUSE_MENU
)

where wt.exe >nul 2>&1
if errorlevel 1 (
    echo   [INFO] Windows Terminal tidak ada, memakai window terpisah.
    goto START_ALL_FALLBACK
)

echo   Membuka Windows Terminal dengan beberapa tab...
echo.

REM Tab wajib: API, tiga antrean, scheduler, log, aset.
REM
REM Antrean dipisah tiga proses karena satu export 2 juta baris yang berbagi
REM antrean dengan notifikasi matching akan menahan semuanya, dan yang
REM dirasakan penumpang adalah driver tidak pernah muncul.
set "TABS=new-tab --title "api:8000" -d "%ROOT%" cmd /k php artisan octane:start --server=roadrunner --host=%APP_HOST% --port=%APP_PORT% --watch"
set "TABS=!TABS!; new-tab --title "queue:critical" -d "%ROOT%" cmd /k php artisan queue:work redis --queue=critical --tries=3 --timeout=60"
set "TABS=!TABS!; new-tab --title "queue:default" -d "%ROOT%" cmd /k php artisan queue:work redis --queue=default --tries=3 --timeout=120"
set "TABS=!TABS!; new-tab --title "queue:reports" -d "%ROOT%" cmd /k php artisan queue:work redis --queue=reports --tries=1 --timeout=3600"
set "TABS=!TABS!; new-tab --title "scheduler" -d "%ROOT%" cmd /k php artisan schedule:work"
set "TABS=!TABS!; new-tab --title "logs" -d "%ROOT%" cmd /k php artisan pail --timeout=0"

if exist "%ROOT%\node_modules" (
    set "TABS=!TABS!; new-tab --title "vite:%VITE_PORT%" -d "%ROOT%" cmd /k npm run dev"
) else (
    echo   [LEWAT] Tab Vite dilewati: node_modules belum ada. Jalankan npm install.
)

if exist "%ROOT%\services\centrifugo\centrifugo.exe" (
    set "TABS=!TABS!; new-tab --title "centrifugo:%CENTRIFUGO_PORT%" -d "%ROOT%\services\centrifugo" cmd /k centrifugo.exe --config config.json"
) else (
    echo   [LEWAT] Tab Centrifugo dilewati: binary belum diunduh.
)

if exist "%ROOT%\services\location-service\location-service.exe" (
    set "TABS=!TABS!; new-tab --title "location:%LOCATION_PORT%" -d "%ROOT%\services\location-service" cmd /k location-service.exe"
) else if exist "%ROOT%\services\location-service\main.go" (
    set "TABS=!TABS!; new-tab --title "location:%LOCATION_PORT%" -d "%ROOT%\services\location-service" cmd /k go run ."
) else (
    echo   [LEWAT] Tab location service dilewati: belum ada sumber kodenya.
)

REM wt.exe dipanggil LANGSUNG, tanpa "start".
REM
REM Ini bukan pilihan gaya. Perintah `start` memperlakukan titik koma sebagai
REM delimiter argumen, jadi seluruh tab setelah yang pertama akan terpotong
REM dan hilang tanpa satu pun pesan error. Sudah diuji: dengan `start` hanya
REM 1 dari 6 tab yang jalan; tanpa `start`, keenamnya jalan.
wt.exe -w antaride-dev !TABS!

echo.
echo   Tab terbuka. Window Windows Terminal bernama "antaride-dev".
echo   Menutup window itu mematikan seluruh service sekaligus.
goto PAUSE_MENU

:START_ALL_FALLBACK
start "antaride api" cmd /k "cd /d %ROOT% && php artisan octane:start --server=roadrunner --host=%APP_HOST% --port=%APP_PORT% --watch"
start "antaride queue critical" cmd /k "cd /d %ROOT% && php artisan queue:work redis --queue=critical --tries=3"
start "antaride queue default" cmd /k "cd /d %ROOT% && php artisan queue:work redis --queue=default --tries=3"
start "antaride queue reports" cmd /k "cd /d %ROOT% && php artisan queue:work redis --queue=reports --tries=1 --timeout=3600"
start "antaride scheduler" cmd /k "cd /d %ROOT% && php artisan schedule:work"
start "antaride logs" cmd /k "cd /d %ROOT% && php artisan pail --timeout=0"
echo   Service dijalankan di window terpisah.
goto PAUSE_MENU

:PREREQ_FAIL
echo.
echo   Prasyarat belum siap. Perbaiki dulu lalu coba lagi.
goto PAUSE_MENU

REM ==========================================================================
REM  2. Hanya API
REM ==========================================================================
:START_API
cls
echo   Menjalankan Octane pada http://%APP_HOST%:%APP_PORT%
echo   Tekan Ctrl+C untuk berhenti.
echo.
php artisan octane:start --server=roadrunner --host=%APP_HOST% --port=%APP_PORT% --watch
goto PAUSE_MENU

REM ==========================================================================
REM  3. Cek kesehatan environment
REM ==========================================================================
:HEALTH
cls
echo.
echo   ------------------------------------------------------------------
echo    CEK KESEHATAN ENVIRONMENT
echo   ------------------------------------------------------------------
echo.
echo   [Tooling]
for /f "tokens=*" %%v in ('php -r "echo 'PHP ' . PHP_VERSION . (ZEND_THREAD_SAFE ? ' (ZTS)' : ' (NTS)');" 2^>nul') do echo     %%v
for /f "tokens=*" %%v in ('node -v 2^>nul') do echo     Node %%v
for /f "tokens=*" %%v in ('composer -V --no-ansi 2^>nul') do echo     %%v
if exist "%ROOT%\rr.exe" (echo     RoadRunner: ada) else (echo     RoadRunner: TIDAK ADA)
echo.
echo   [Ekstensi PHP wajib]
call :CHECK_EXT pdo_pgsql
call :CHECK_EXT pgsql
call :CHECK_EXT sockets
call :CHECK_EXT bcmath
call :CHECK_EXT redis
echo.
echo   [Service]
call :CHECK_PORT 5433 "PostgreSQL"
call :CHECK_PORT 6379 "Redis"
echo.
echo   [Aplikasi]
php artisan antaride:health
echo.
goto PAUSE_MENU

REM ==========================================================================
REM  4. Hentikan semua
REM ==========================================================================
:STOP_ALL
cls
echo   Menghentikan service Antaride...
echo.
taskkill /f /im rr.exe >nul 2>&1 && echo     rr.exe dihentikan.
taskkill /f /im centrifugo.exe >nul 2>&1 && echo     centrifugo.exe dihentikan.
taskkill /f /im location-service.exe >nul 2>&1 && echo     location-service.exe dihentikan.
echo.
echo   Catatan: proses php.exe TIDAK dimatikan otomatis, karena bisa jadi
echo   ada pekerjaan lain milik Anda yang sedang jalan. Tutup tab-nya
echo   masing-masing, atau jalankan: taskkill /f /im php.exe
goto PAUSE_MENU

REM ==========================================================================
REM  Perintah database & kode
REM ==========================================================================
:MIGRATE
cls & php artisan migrate & goto PAUSE_MENU

:FRESH
cls
echo.
echo   PERINGATAN: ini MENGHAPUS SELURUH DATA di database "antaride".
echo.
set "KONFIRM="
set /p "KONFIRM=   Ketik HAPUS untuk melanjutkan: "
if /i not "!KONFIRM!"=="HAPUS" (
    echo   Dibatalkan.
    goto PAUSE_MENU
)
php artisan migrate:fresh --seed
goto PAUSE_MENU

:SEED
cls & php artisan db:seed & goto PAUSE_MENU

:PSQL
cls
set "PGBIN=C:\Program Files\PostgreSQL\18\bin\psql.exe"
if not exist "!PGBIN!" (
    echo   psql.exe tidak ditemukan di !PGBIN!
    goto PAUSE_MENU
)
echo   Menyambung ke database antaride pada port 5433...
"!PGBIN!" -h 127.0.0.1 -p 5433 -U postgres -d antaride
goto PAUSE_MENU

:CLEAR
cls
php artisan optimize:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
echo.
echo   Cache dibersihkan.
goto PAUSE_MENU

:OPENAPI
cls
php artisan scramble:export --path=docs/openapi/openapi.json
echo.
echo   Spec ditulis ke docs/openapi/openapi.json
echo   Ini file yang dipakai generator client Dart di repo frontend.
goto PAUSE_MENU

:TEST
cls & php artisan test & goto PAUSE_MENU

:PINT
cls & vendor\bin\pint & goto PAUSE_MENU

:TINKER
cls & php artisan tinker & goto PAUSE_MENU

REM ==========================================================================
REM  Subrutin
REM ==========================================================================
REM ==========================================================================
REM  14. Agregasi metrik
REM ==========================================================================
REM
REM  Dashboard backoffice membaca metrics_daily. Tanpa agregasi, grafik trennya
REM  menampilkan nol untuk setiap hari — dan grafik datar itu terbaca sebagai
REM  "tidak ada order", bukan sebagai job yang belum jalan.
REM
REM  Di produksi ini dijalankan scheduler otomatis (lihat routes/console.php).
REM  Menu ini untuk mengisi data lama setelah migrate:fresh --seed, atau untuk
REM  memeriksa hasilnya tanpa menunggu 15 menit.
REM
:METRICS
cls
echo.
echo   ------------------------------------------------------------------
echo    AGREGASI METRIK
echo   ------------------------------------------------------------------
echo.
echo   Mengisi metrics_daily dan driver_daily_metrics.
echo   Idempoten: aman dijalankan berapa kali pun.
echo.
set "HARI="
set /p "HARI=   Berapa hari terakhir yang diagregasi? [30]: "
if "%HARI%"=="" set "HARI=30"
echo.
echo   Hari ini:
php artisan antaride:aggregate-metrics --today
echo.
echo   %HARI% hari sebelumnya:
php artisan antaride:aggregate-metrics --days=%HARI%
echo.
goto PAUSE_MENU

REM ==========================================================================
REM  15. Pangkas log lama
REM ==========================================================================
REM
REM  SELALU dry-run lebih dulu, dan konfirmasinya diminta setelah angkanya
REM  terlihat. Perintah yang menghapus data tidak boleh dijalankan dari menu
REM  tanpa pemiliknya melihat dampaknya — dan log yang terhapus tidak bisa
REM  dikembalikan.
REM
:PRUNE
cls
echo.
echo   ------------------------------------------------------------------
echo    PANGKAS LOG LAMA
echo   ------------------------------------------------------------------
echo.
echo   Menghitung dulu (tidak menghapus apa pun):
echo.
php artisan antaride:prune-logs --dry-run
echo.
echo   ------------------------------------------------------------------
echo   Yang TIDAK pernah dipangkas: wallet_transactions, orders, audit_logs.
echo   ------------------------------------------------------------------
echo.
set "KONFIRM="
set /p "KONFIRM=   Ketik HAPUS untuk benar-benar memangkas: "
if /i not "%KONFIRM%"=="HAPUS" (
    echo.
    echo   Dibatalkan. Tidak ada yang dihapus.
    goto PAUSE_MENU
)
echo.
php artisan antaride:prune-logs
echo.
goto PAUSE_MENU

REM ==========================================================================
REM  16. Lihat jadwal terdaftar
REM ==========================================================================
REM
REM  Jam yang ditampilkan Laravel dalam UTC, sementara jadwalnya disetel di
REM  Asia/Jakarta. Jadi "18:30" di daftar berarti 01:30 WIB — dan itu benar.
REM  Perbedaan tujuh jam ini yang paling sering disalahpahami saat memeriksa
REM  jadwal, jadi disebutkan langsung di layar.
REM
:SCHEDULE
cls
echo.
echo   ------------------------------------------------------------------
echo    JADWAL TERDAFTAR
echo   ------------------------------------------------------------------
echo    Jam di bawah dalam UTC. WIB = UTC + 7.
echo    Contoh: 18:30 UTC = 01:30 WIB.
echo   ------------------------------------------------------------------
echo.
php artisan schedule:list
echo.
goto PAUSE_MENU


:CHECK_PORT
netstat -ano | findstr /r /c:":%~1 .*LISTENING" >nul 2>&1
if errorlevel 1 (
    echo     %~2 pada port %~1 : TIDAK JALAN
    exit /b 1
)
echo     %~2 pada port %~1 : jalan
exit /b 0

:CHECK_EXT
php -r "exit(extension_loaded('%~1') ? 0 : 1);" >nul 2>&1
if errorlevel 1 (
    echo     %~1 : tidak ada
) else (
    echo     %~1 : ada
)
exit /b 0

:PAUSE_MENU
echo.
pause
goto MENU

:END
endlocal
exit /b 0
