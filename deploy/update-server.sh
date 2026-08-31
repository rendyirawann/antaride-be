#!/usr/bin/env bash
#
# Memperbarui server yang sudah berjalan ke kode terbaru.
#
# ==============================================================================
#  KENAPA SKRIP INI ADA
# ==============================================================================
#  Langkah deploy di DEPLOY.md sudah lengkap, tapi panjang — dan urutannya
#  penting di satu tempat yang gejalanya menyesatkan: `.env` yang disunting
#  SETELAH `config:cache` tidak terbaca, dan yang terlihat bukan pesan galat
#  melainkan fitur yang diam-diam mati.
#
#  Menyalin belasan perintah dengan tangan adalah cara paling mudah melewatkan
#  satu di antaranya. Skrip ini menjalankan semuanya dalam urutan yang benar,
#  dan BERHENTI di kegagalan pertama.
# ==============================================================================
#
#  Pakai:
#      cd /var/www/antaride-be
#      sudo bash deploy/update-server.sh
#
set -euo pipefail

AKAR="/var/www/antaride-be"
JALANKAN="sudo -u www-data"

cd "$AKAR"

# ------------------------------------------------------------------------------
#  0. Periksa .env SEBELUM menyentuh apa pun
# ------------------------------------------------------------------------------
#  Diperiksa lebih dulu, bukan di akhir: menemukan kunci yang belum ada SETELAH
#  aplikasi dimatikan berarti server mati lebih lama daripada perlu.
#
#  Yang diperiksa hanya KEBERADAAN kuncinya. Nilainya tidak bisa diperiksa dari
#  sini — dan kunci yang ada dengan nilai kosong akan terbaca sebagai fitur yang
#  sengaja dimatikan, yang memang perilaku yang benar.
WAJIB=(
  ANTARIDE_AREA_LAT
  ANTARIDE_AREA_LNG
  ANTARIDE_AREA_RADIUS_KM
  ANTARIDE_DEMO_LOGIN
  API_DOCS_USERNAME
  API_DOCS_PASSWORD
)

KURANG=()

for kunci in "${WAJIB[@]}"; do
  if ! grep -qE "^${kunci}=" .env; then
    KURANG+=("$kunci")
  fi
done

if [ ${#KURANG[@]} -gt 0 ]; then
  echo "GAGAL: kunci berikut belum ada di .env:" >&2
  printf '  %s\n' "${KURANG[@]}" >&2
  echo >&2
  echo "Tambahkan dulu, lalu jalankan skrip ini lagi." >&2
  echo "Contoh nilainya ada di DEPLOY.md bagian 'Deploy area layanan'." >&2
  exit 1
fi

# NOMINATIM_ENABLED tidak wajib — pencarian alamat memang boleh mati. Tapi
# kalau dinyalakan, alamatnya harus ada.
if grep -qE '^NOMINATIM_ENABLED=(true|1)' .env; then
  if ! grep -qE '^NOMINATIM_URL=.' .env; then
    echo "GAGAL: NOMINATIM_ENABLED=true tapi NOMINATIM_URL kosong." >&2
    exit 1
  fi
fi

echo "==> .env lengkap."

# ------------------------------------------------------------------------------
#  1. Turunkan aplikasi
# ------------------------------------------------------------------------------
echo "==> Mode pemeliharaan"
$JALANKAN php artisan down --render="errors::503"

# Dipulihkan APA PUN yang terjadi setelah titik ini. Tanpa trap, satu kegagalan
# di tengah meninggalkan server dalam mode pemeliharaan sampai ada yang sadar.
pulihkan() {
  echo "==> Menghidupkan kembali aplikasi"
  $JALANKAN php artisan up || true
}
trap pulihkan EXIT

# ------------------------------------------------------------------------------
#  2. Kode dan dependensi
# ------------------------------------------------------------------------------
echo "==> git pull"
$JALANKAN git pull --ff-only

echo "==> composer install"
$JALANKAN composer install --no-dev --optimize-autoloader

# ------------------------------------------------------------------------------
#  3. Migrasi
# ------------------------------------------------------------------------------
#  Termasuk yang mengubah nama layanan menjadi "Antaride" dan "AntarExpress".
#
#  TIDAK ada `db:seed` di sini, dan itu disengaja: CatalogSeeder memakai
#  insertGetId, jadi menjalankannya di database yang sudah terisi membuat baris
#  layanan KEDUA dengan kode yang sama — dan sejak itu ongkos bisa dihitung dari
#  tarif yang mana saja.
echo "==> Migrasi"
$JALANKAN php artisan migrate --force

# ------------------------------------------------------------------------------
#  4. Akun demo
# ------------------------------------------------------------------------------
#  Aman dijalankan berulang: seeder-nya updateOrCreate berdasarkan nomor HP.
echo "==> Akun demo"
$JALANKAN php artisan db:seed --force --class=DemoAccountSeeder

# ------------------------------------------------------------------------------
#  5. Spesifikasi API
# ------------------------------------------------------------------------------
#  Tanpa ini, halaman dokumentasi membuatnya sendiri pada pemuatan pertama —
#  berhasil, tapi menjalankan analisis statis di dalam request web.
echo "==> Spesifikasi OpenAPI"
$JALANKAN php artisan scramble:export --path=docs/openapi/openapi.json

# ------------------------------------------------------------------------------
#  6. Cache
# ------------------------------------------------------------------------------
#  SETELAH .env sudah benar. `config:cache` membekukan nilai env(); menyunting
#  .env sesudah ini berarti perubahannya tidak terbaca sampai cache dibuat lagi.
echo "==> Cache"
$JALANKAN php artisan config:cache
$JALANKAN php artisan route:cache
$JALANKAN php artisan view:cache
$JALANKAN php artisan event:cache

# ------------------------------------------------------------------------------
#  7. Muat ulang layanan
# ------------------------------------------------------------------------------
echo "==> Reload Octane dan worker"
systemctl reload antaride-octane
systemctl restart antaride-queues.target

# `up` dijalankan trap di atas.
trap - EXIT
$JALANKAN php artisan up

# ------------------------------------------------------------------------------
#  8. Verifikasi
# ------------------------------------------------------------------------------
echo
echo "==> Verifikasi"

BASE="http://127.0.0.1:8000/api/v1"

periksa() {
  local nama="$1" url="$2" harap="$3"
  local kode
  kode=$(curl -sS -o /tmp/antaride-cek.json -w '%{http_code}' "$url" || echo 000)

  if [ "$kode" = "$harap" ]; then
    printf '  OK    %-28s HTTP %s\n' "$nama" "$kode"
  else
    printf '  GAGAL %-28s HTTP %s (harap %s)\n' "$nama" "$kode" "$harap"
  fi
}

periksa "ping" "$BASE/ping" 200
periksa "config (area layanan)" "$BASE/config" 200
periksa "service-types" "$BASE/service-types" 200

echo
echo "  Nama layanan:"
curl -sS "$BASE/service-types" \
  | python3 -c 'import sys,json;[print("   ",s["code"],"=>",s["name"]) for s in json.load(sys.stdin)["data"]]' \
  || echo "    (tidak bisa dibaca)"

echo
echo "  Area layanan dan pencarian alamat:"
curl -sS "$BASE/config" \
  | python3 -c '
import sys, json
d = json.load(sys.stdin)["data"]
a = d["area"]
print("    titik  :", a["lat"], a["lng"], "radius", a["radius_km"], "km")
print("    label  :", a["label"])
print("    alamat :", "menyala" if d["places_enabled"] else "MATI (Nominatim belum disetel)")
' || echo "    (tidak bisa dibaca)"

echo
echo "Selesai."
