package main

import (
	"crypto/hmac"
	"crypto/sha256"
	"log"
	"os"
	"strconv"
	"time"
)

// Config adalah seluruh pengaturan layanan ini.
//
// Dibaca dari environment, dan setiap nilai punya bawaan yang cocok dengan
// lingkungan pengembangan Antaride di Windows — jadi layanan ini jalan tanpa
// satu pun variabel diset.
type Config struct {
	Addr string

	RedisAddr     string
	RedisPassword string

	// ============================================================================
	//  DATABASE REDIS HARUS SAMA DENGAN YANG DIPAKAI LARAVEL
	// ============================================================================
	//  Laravel memakai koneksi `shared` dengan database dan TANPA prefix. Kalau
	//  layanan ini menulis ke database yang berbeda, matching akan membaca set
	//  yang kosong sementara di sini ping-nya tercatat rapi.
	//
	//  Tidak ada galat di kedua sisi. Yang terlihat hanya driver yang online dan
	//  tidak pernah mendapat order.
	// ============================================================================
	RedisDB int

	Secret []byte

	// MinInterval membuang ping yang lebih rapat dari ini.
	//
	// Cocok dengan `antaride.gps.min_interval_seconds` di Laravel. Aplikasi driver
	// yang salah konfigurasi — atau yang sengaja dimodifikasi — bisa mengirim
	// puluhan ping per detik, dan setiap ping adalah tulis ke Redis.
	MinInterval time.Duration

	// MaxAccuracy menolak ping yang akurasinya lebih buruk dari ini, dalam meter.
	//
	// Posisi dengan akurasi 2 km bukan posisi; dia lingkaran yang memuat separuh
	// kota. Menyimpannya membuat matching mengira driver ada di titik yang dia
	// tidak berada.
	MaxAccuracy float64

	// MetaTTL harus sama dengan META_TTL_SECONDS di RedisDriverLocationIndex.
	//
	// Ini yang membuat driver yang berhenti mengirim ping otomatis dianggap
	// hilang: metanya kadaluarsa, dan matching menyaringnya keluar.
	MetaTTL time.Duration
}

func LoadConfig() Config {
	cfg := Config{
		Addr:          envString("ANTARIDE_LOCATION_ADDR", "127.0.0.1:8200"),
		RedisAddr:     envString("ANTARIDE_REDIS_ADDR", "127.0.0.1:6379"),
		RedisPassword: envString("ANTARIDE_REDIS_PASSWORD", ""),
		RedisDB:       envInt("ANTARIDE_REDIS_DB", 0),
		MinInterval:   time.Duration(envFloat("ANTARIDE_GPS_MIN_INTERVAL_SECONDS", 2)*1000) * time.Millisecond,
		MaxAccuracy:   envFloat("ANTARIDE_GPS_MAX_ACCURACY_M", 100),
		MetaTTL:       time.Duration(envInt("ANTARIDE_META_TTL_SECONDS", 60)) * time.Second,
	}

	cfg.Secret = loadSecret()

	return cfg
}

// loadSecret menyiapkan rahasia HMAC untuk memverifikasi tiket.
//
// ============================================================================
//
//	DUA JALAN, DAN KEDUANYA HARUS COCOK DENGAN SISI LARAVEL
//
// ============================================================================
//
//  1. `ANTARIDE_LOCATION_SECRET` diset — dipakai apa adanya. Ini yang dipakai
//     produksi, dan nilainya harus sama dengan `LOCATION_SERVICE_SECRET` di
//     `.env` Laravel.
//
//  2. Tidak diset — diturunkan dari `ANTARIDE_APP_KEY` dengan rumus yang PERSIS
//     sama dengan `LocationTicket::secret()` di PHP:
//
//     hash_hmac('sha256', 'antaride-location-service', APP_KEY)
//
//     Ada supaya pengembangan jalan tanpa konfigurasi tambahan. Rumusnya harus
//     berubah bersamaan di kedua sisi — kalau tidak, setiap ping ditolak 401.
//
// ============================================================================
func loadSecret() []byte {
	if s := os.Getenv("ANTARIDE_LOCATION_SECRET"); s != "" {
		return []byte(s)
	}

	appKey := os.Getenv("ANTARIDE_APP_KEY")

	if appKey == "" {
		log.Fatal(
			"ANTARIDE_LOCATION_SECRET atau ANTARIDE_APP_KEY harus diset.\n" +
				"Tanpa salah satunya, tidak ada cara memverifikasi tiket — dan\n" +
				"menerima ping tanpa verifikasi berarti siapa pun bisa memalsukan\n" +
				"posisi driver mana pun.",
		)
	}

	mac := hmac.New(sha256.New, []byte(appKey))
	mac.Write([]byte("antaride-location-service"))

	// Hex, bukan biner: `hash_hmac()` PHP tanpa argumen `binary` mengembalikan
	// hex, dan rahasianya harus identik byte per byte di kedua sisi.
	return []byte(hexEncode(mac.Sum(nil)))
}

func hexEncode(b []byte) string {
	const digits = "0123456789abcdef"

	out := make([]byte, len(b)*2)

	for i, v := range b {
		out[i*2] = digits[v>>4]
		out[i*2+1] = digits[v&0x0f]
	}

	return string(out)
}

func envString(key, bawaan string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}

	return bawaan
}

func envInt(key string, bawaan int) int {
	if v := os.Getenv(key); v != "" {
		if n, err := strconv.Atoi(v); err == nil {
			return n
		}

		log.Printf("%s bukan angka (%q) — memakai bawaan %d", key, v, bawaan)
	}

	return bawaan
}

func envFloat(key string, bawaan float64) float64 {
	if v := os.Getenv(key); v != "" {
		if f, err := strconv.ParseFloat(v, 64); err == nil {
			return f
		}

		log.Printf("%s bukan angka (%q) — memakai bawaan %v", key, v, bawaan)
	}

	return bawaan
}
