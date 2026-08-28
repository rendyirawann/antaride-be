package main

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/json"
	"strings"
	"testing"
	"time"
)

// ============================================================================
//  VERIFIKASI TIKET ADALAH SATU-SATUNYA HAL YANG MENJAGA LAYANAN INI
// ============================================================================
//  Tidak ada lapisan lain: tidak ada session, tidak ada database, tidak ada
//  pemeriksaan lain. Kalau verifikasi ini bisa dilewati, siapa pun bisa
//  memalsukan posisi driver mana pun — dan driver palsu yang mengaku dekat
//  dengan penumpang akan mendapat order yang seharusnya bukan miliknya.
//
//  Karena itu yang diuji di sini bukan hanya "tiket sah diterima", tapi setiap
//  cara tiket tidak sah bisa lolos.
// ============================================================================

var rahasiaUji = []byte("rahasia-uji-antaride")

func buatTiket(t *testing.T, driverID int64, services []string, exp time.Time) string {
	t.Helper()

	payload, err := json.Marshal(Ticket{
		DriverID: driverID,
		Services: services,
		Exp:      exp.Unix(),
	})
	if err != nil {
		t.Fatalf("gagal menyusun payload: %v", err)
	}

	encoded := base64.RawURLEncoding.EncodeToString(payload)

	return encoded + "." + signTicket(encoded, rahasiaUji)
}

func TestTiketSahDiterima(t *testing.T) {
	raw := buatTiket(t, 42, []string{"ride_bike", "ride_car"}, time.Now().Add(time.Hour))

	tiket, err := VerifyTicket(raw, rahasiaUji)
	if err != nil {
		t.Fatalf("tiket sah ditolak: %v", err)
	}

	if tiket.DriverID != 42 {
		t.Errorf("driver id = %d, harusnya 42", tiket.DriverID)
	}

	if len(tiket.Services) != 2 {
		t.Errorf("services = %v, harusnya dua", tiket.Services)
	}
}

// Tiket yang payload-nya diubah HARUS ditolak.
//
// Ini serangan yang paling jelas: ambil tiket sendiri, ganti `driver_id` jadi
// milik orang lain, kirim ulang. Tanda tangan yang tidak diverifikasi berarti
// setiap driver bisa memalsukan posisi seluruh driver lain.
func TestPayloadYangDiubahDitolak(t *testing.T) {
	asli := buatTiket(t, 42, []string{"ride_bike"}, time.Now().Add(time.Hour))

	// Payload diganti dengan driver lain, tanda tangan LAMA dipertahankan.
	palsu, err := json.Marshal(Ticket{
		DriverID: 999,
		Services: []string{"ride_bike"},
		Exp:      time.Now().Add(time.Hour).Unix(),
	})
	if err != nil {
		t.Fatal(err)
	}

	tandaTanganLama := strings.SplitN(asli, ".", 2)[1]
	diubah := base64.RawURLEncoding.EncodeToString(palsu) + "." + tandaTanganLama

	if _, err := VerifyTicket(diubah, rahasiaUji); err == nil {
		t.Fatal(
			"tiket dengan payload diubah DITERIMA. Setiap driver bisa " +
				"memalsukan posisi driver lain.",
		)
	}
}

func TestTandaTanganPalsuDitolak(t *testing.T) {
	raw := buatTiket(t, 42, []string{"ride_bike"}, time.Now().Add(time.Hour))
	encoded := strings.SplitN(raw, ".", 2)[0]

	kasus := map[string]string{
		"tanda tangan acak":   encoded + ".AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA",
		"tanda tangan kosong": encoded + ".",
		"tanpa tanda tangan":  encoded,
		"hanya titik":         ".",
		"kosong":              "",
	}

	for nama, tiket := range kasus {
		if _, err := VerifyTicket(tiket, rahasiaUji); err == nil {
			t.Errorf("%s: DITERIMA, harusnya ditolak", nama)
		}
	}
}

// Rahasia yang berbeda harus menolak tiket.
//
// Ini yang terjadi kalau `LOCATION_SERVICE_SECRET` di Laravel dan
// `ANTARIDE_LOCATION_SECRET` di Go tidak sama. Gejalanya bukan galat yang
// menyebut rahasia — hanya 401 di setiap ping, dan driver yang online tanpa
// pernah muncul sebagai kandidat matching.
func TestRahasiaBerbedaMenolak(t *testing.T) {
	raw := buatTiket(t, 42, []string{"ride_bike"}, time.Now().Add(time.Hour))

	if _, err := VerifyTicket(raw, []byte("rahasia-yang-lain")); err == nil {
		t.Fatal("tiket diterima dengan rahasia yang berbeda")
	}
}

func TestTiketKadaluarsaDitolak(t *testing.T) {
	raw := buatTiket(t, 42, []string{"ride_bike"}, time.Now().Add(-time.Minute))

	_, err := VerifyTicket(raw, rahasiaUji)

	if err == nil {
		t.Fatal("tiket kadaluarsa diterima")
	}

	if err != ErrTicketExpired {
		t.Errorf("galatnya %v, harusnya ErrTicketExpired", err)
	}
}

func TestDriverIdNolDitolak(t *testing.T) {
	// driver_id nol berarti tiket yang payload-nya kosong atau rusak. Menerimanya
	// akan menulis posisi ke `driver:0` — anggota yang tidak menunjuk siapa pun,
	// dan yang akan ikut muncul di setiap pencarian di sekitarnya.
	raw := buatTiket(t, 0, []string{"ride_bike"}, time.Now().Add(time.Hour))

	if _, err := VerifyTicket(raw, rahasiaUji); err == nil {
		t.Fatal("tiket dengan driver_id 0 diterima")
	}
}

// Tiket dengan titik tambahan harus ditolak, bukan dipotong.
//
// `strings.Split` biasa akan mengambil dua bagian pertama dan mengabaikan
// sisanya — jadi `payload.tandatangan.apapun` akan lolos. Payload yang
// menyelipkan titik tambahan adalah percobaan, bukan kesalahan.
func TestTiketDenganTitikTambahanDitolak(t *testing.T) {
	raw := buatTiket(t, 42, []string{"ride_bike"}, time.Now().Add(time.Hour))

	if _, err := VerifyTicket(raw+".tambahan", rahasiaUji); err == nil {
		t.Fatal("tiket dengan titik tambahan diterima")
	}
}

// ============================================================================
//
//	RUMUS TURUNAN RAHASIA HARUS SAMA DENGAN PHP
//
// ============================================================================
//
//	`LocationTicket::secret()` di PHP:
//
//	    hash_hmac('sha256', 'antaride-location-service', APP_KEY)
//
//	Yaitu HMAC dengan APP_KEY sebagai KUNCI dan string tetap sebagai PESAN,
//	hasilnya hex. Urutan yang tertukar — string sebagai kunci, APP_KEY sebagai
//	pesan — menghasilkan nilai yang sama sekali berbeda, dan setiap ping ditolak
//	401 tanpa petunjuk apa pun soal penyebabnya.
//
// ============================================================================
func TestTurunanRahasiaCocokDenganPhp(t *testing.T) {
	const appKey = "base64:contoh-app-key-untuk-uji"

	t.Setenv("ANTARIDE_LOCATION_SECRET", "")
	t.Setenv("ANTARIDE_APP_KEY", appKey)

	dariGo := string(loadSecret())

	// Nilai acuan dihitung dengan rumus PHP, ditulis ulang di sini secara
	// eksplisit — bukan memanggil `loadSecret()` lagi, yang hanya akan
	// membuktikan kodenya konsisten dengan dirinya sendiri.
	mac := hmac.New(sha256.New, []byte(appKey))
	mac.Write([]byte("antaride-location-service"))
	acuan := hexEncode(mac.Sum(nil))

	if dariGo != acuan {
		t.Fatalf("turunan rahasia tidak cocok:\n  go   = %s\n  acuan = %s", dariGo, acuan)
	}

	// Panjangnya harus 64 karakter hex — sama dengan keluaran `hash_hmac` PHP
	// tanpa argumen binary.
	if len(dariGo) != 64 {
		t.Errorf("panjang rahasia %d, harusnya 64 karakter hex", len(dariGo))
	}
}

func TestRahasiaEksplisitDipakaiApaAdanya(t *testing.T) {
	t.Setenv("ANTARIDE_LOCATION_SECRET", "rahasia-produksi")
	t.Setenv("ANTARIDE_APP_KEY", "diabaikan")

	if got := string(loadSecret()); got != "rahasia-produksi" {
		t.Fatalf("rahasia = %q, harusnya dipakai apa adanya", got)
	}
}

// ============================================================================
//  VALIDASI KOORDINAT
// ============================================================================

func TestValidasiKoordinat(t *testing.T) {
	akurasi := func(v float64) *float64 { return &v }

	kasus := []struct {
		nama    string
		req     PingRequest
		ditolak bool
	}{
		{"Medan, akurasi baik", PingRequest{Lat: 3.5952, Lng: 98.6722, Accuracy: akurasi(12)}, false},
		{"tanpa akurasi", PingRequest{Lat: 3.5952, Lng: 98.6722}, false},
		{"Jakarta (lintang negatif)", PingRequest{Lat: -6.2088, Lng: 106.8456}, false},

		{"nol-nol", PingRequest{Lat: 0, Lng: 0}, true},
		{"lintang 91", PingRequest{Lat: 91, Lng: 98.67}, true},
		{"lintang -91", PingRequest{Lat: -91, Lng: 98.67}, true},
		{"bujur 181", PingRequest{Lat: 3.59, Lng: 181}, true},
		{"bujur -181", PingRequest{Lat: 3.59, Lng: -181}, true},
		{"akurasi 2 km", PingRequest{Lat: 3.59, Lng: 98.67, Accuracy: akurasi(2000)}, true},

		// Tepat di batas akurasi HARUS lolos. Perbandingannya `>`, bukan `>=`.
		{"akurasi tepat 100", PingRequest{Lat: 3.59, Lng: 98.67, Accuracy: akurasi(100)}, false},
	}

	for _, k := range kasus {
		alasan := validasiKoordinat(k.req, 100)

		if k.ditolak && alasan == "" {
			t.Errorf("%s: DITERIMA, harusnya ditolak", k.nama)
		}

		if !k.ditolak && alasan != "" {
			t.Errorf("%s: ditolak (%s), harusnya diterima", k.nama, alasan)
		}
	}
}

// ============================================================================
//  PEMBATASAN LAYANAN
// ============================================================================
//  Badan permintaan datang dari aplikasi dan bisa diubah siapa pun yang memegang
//  tiketnya. Kalau daftar layanan diambil dari badan tanpa dibatasi tiket, driver
//  yang hanya berhak atas `ride_bike` bisa menulis posisinya ke `drv:loc:ride_car`
//  — dan mendapat tawaran order taksi dengan sepeda motor.
// ============================================================================

func TestPersempitLayanan(t *testing.T) {
	kasus := []struct {
		nama    string
		diTiket []string
		diminta []string
		mau     []string
	}{
		{
			nama:    "tanpa permintaan, pakai seluruh tiket",
			diTiket: []string{"ride_bike", "ride_car"},
			diminta: nil,
			mau:     []string{"ride_bike", "ride_car"},
		},
		{
			nama:    "mempersempit ke sebagian",
			diTiket: []string{"ride_bike", "ride_car"},
			diminta: []string{"ride_bike"},
			mau:     []string{"ride_bike"},
		},
		{
			nama:    "layanan di luar tiket dibuang",
			diTiket: []string{"ride_bike"},
			diminta: []string{"ride_car"},
			mau:     []string{},
		},
		{
			nama:    "sebagian sah, sebagian tidak",
			diTiket: []string{"ride_bike"},
			diminta: []string{"ride_bike", "ride_car", "food"},
			mau:     []string{"ride_bike"},
		},
	}

	for _, k := range kasus {
		hasil := persempitLayanan(k.diTiket, k.diminta)

		if len(hasil) != len(k.mau) {
			t.Errorf("%s: hasil %v, harusnya %v", k.nama, hasil, k.mau)

			continue
		}

		for i := range hasil {
			if hasil[i] != k.mau[i] {
				t.Errorf("%s: hasil %v, harusnya %v", k.nama, hasil, k.mau)

				break
			}
		}
	}
}
