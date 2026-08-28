// Layanan lokasi Antaride.
//
// ============================================================================
//
//	KENAPA LAYANAN TERSENDIRI, BUKAN ENDPOINT LARAVEL
//
// ============================================================================
//
//	Ping GPS driver adalah permintaan yang paling sering masuk di seluruh
//	sistem, dan yang isinya paling sedikit: dua angka.
//
//	Seribu driver dengan ping tiap empat detik adalah 250 permintaan per detik.
//	Melewatkannya lewat Laravel berarti 250 boot framework per detik — memuat
//	container, service provider, middleware, dan Eloquent — untuk pekerjaan yang
//	tidak membutuhkan satu pun di antaranya.
//
//	Layanan ini menulis langsung ke Redis. Yang tersisa di Laravel adalah hal
//	yang memang membutuhkannya: siapa yang boleh mengirim ping (lewat tiket
//	bertanda tangan), dan siapa yang tersedia untuk order mana.
//
// ============================================================================
//
// ============================================================================
//
//	YANG SENGAJA TIDAK ADA DI SINI
//
// ============================================================================
//
//	  Database    layanan ini tidak pernah menyentuh Postgres. Verifikasi
//	              pemanggil lewat HMAC, jadi tidak ada query per ping.
//	  Logika      tidak ada keputusan bisnis. Dia tidak memutuskan siapa yang
//	              tersedia, tidak menghitung jarak, tidak memilih driver.
//	              Semuanya di Laravel.
//	  State       tidak ada apa pun yang disimpan di memori proses selain
//	              penyaring laju. Layanan ini boleh dimatikan dan dijalankan
//	              ulang kapan saja tanpa kehilangan apa pun.
//
//	Ketiganya disengaja: layanan yang isinya hanya "terima, validasi, tulis"
//	bisa dibaca seluruhnya dalam satu duduk — dan itu penting untuk komponen
//	yang duduk di jalur paling sibuk.
//
// ============================================================================
package main

import (
	"context"
	"encoding/json"
	"errors"
	"log"
	"net/http"
	"os"
	"os/signal"
	"strings"
	"sync"
	"syscall"
	"time"
)

func main() {
	cfg := LoadConfig()
	store := NewStore(cfg)

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	if err := store.Ping(ctx); err != nil {
		log.Fatalf(
			"Tidak bisa menyambung ke Redis di %s: %v\n\n"+
				"Layanan ini tidak punya gunanya tanpa Redis — setiap ping akan\n"+
				"diterima lalu hilang. Lebih baik gagal sekarang daripada berjalan\n"+
				"dan membuat driver terlihat online tanpa pernah tercatat.",
			cfg.RedisAddr, err,
		)
	}

	h := &Handler{
		cfg:      cfg,
		store:    store,
		lastPing: make(map[int64]time.Time),
	}

	mux := http.NewServeMux()
	mux.HandleFunc("/health", h.Health)
	mux.HandleFunc("/v1/ping", h.Ping)

	srv := &http.Server{
		Addr:    cfg.Addr,
		Handler: mux,

		// Timeout eksplisit. Bawaan `http.Server` adalah TANPA batas, dan
		// koneksi yang menggantung selamanya akan menumpuk sampai file
		// descriptor habis — kegagalan yang muncul berjam-jam setelah
		// penyebabnya, sebagai "too many open files".
		ReadTimeout:       5 * time.Second,
		WriteTimeout:      5 * time.Second,
		IdleTimeout:       60 * time.Second,
		ReadHeaderTimeout: 3 * time.Second,
	}

	// Pembersih penyaring laju. Tanpa ini, map `lastPing` tumbuh selamanya —
	// satu entri per driver yang pernah mengirim ping, dan tidak ada yang
	// pernah menghapusnya.
	go h.bersihkanBerkala()

	go func() {
		log.Printf("Layanan lokasi Antaride mendengarkan di %s", cfg.Addr)
		log.Printf("Redis %s db=%d", cfg.RedisAddr, cfg.RedisDB)

		if err := srv.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
			log.Fatalf("Server berhenti: %v", err)
		}
	}()

	/*
	 * Berhenti dengan rapi saat menerima SIGINT/SIGTERM.
	 *
	 * Yang dijaga: ping yang sedang diproses diselesaikan dulu, bukan diputus
	 * di tengah penulisan ke Redis. Ping yang terputus tidak merusak apa pun —
	 * ping berikutnya menimpanya — tapi koneksi Redis yang tidak ditutup
	 * meninggalkan socket menggantung di sisi Redis sampai timeout-nya lewat.
	 */
	stop := make(chan os.Signal, 1)
	signal.Notify(stop, syscall.SIGINT, syscall.SIGTERM)
	<-stop

	log.Println("Menghentikan layanan lokasi...")

	shutdownCtx, shutdownCancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer shutdownCancel()

	if err := srv.Shutdown(shutdownCtx); err != nil {
		log.Printf("Shutdown tidak selesai dengan rapi: %v", err)
	}

	if err := store.Close(); err != nil {
		log.Printf("Gagal menutup koneksi Redis: %v", err)
	}

	log.Println("Selesai.")
}

type Handler struct {
	cfg   Config
	store *Store

	// ============================================================================
	//  PENYARING LAJU PER DRIVER, DI MEMORI
	// ============================================================================
	//  Membuang ping yang lebih rapat dari `MinInterval`. Yang memicunya:
	//  aplikasi driver yang salah konfigurasi, atau yang sengaja dimodifikasi
	//  untuk mengirim puluhan ping per detik.
	//
	//  Di memori, bukan di Redis: pemeriksaan laju yang butuh perjalanan ke Redis
	//  menggagalkan tujuannya sendiri — dia menambah satu operasi Redis untuk
	//  menghindari satu operasi Redis.
	//
	//  Konsekuensinya, dan ini diterima: kalau nanti layanan ini dijalankan lebih
	//  dari satu instans, penyaringnya per instans. Driver bisa mengirim dua kali
	//  lebih rapat dengan dua instans. Itu tidak merusak apa pun — ping yang
	//  lolos tetap ping yang sah — dan menyelesaikannya menuntut state bersama
	//  yang biayanya lebih besar daripada masalahnya.
	// ============================================================================
	mu       sync.Mutex
	lastPing map[int64]time.Time
}

func (h *Handler) Health(w http.ResponseWriter, r *http.Request) {
	ctx, cancel := context.WithTimeout(r.Context(), 2*time.Second)
	defer cancel()

	if err := h.store.Ping(ctx); err != nil {
		// 503, bukan 500: Redis yang tidak terjangkau adalah keadaan SEMENTARA,
		// dan load balancer harus mengeluarkan instans ini dari rotasi lalu
		// memasukkannya lagi — bukan menganggapnya rusak permanen.
		writeJSON(w, http.StatusServiceUnavailable, map[string]any{
			"ok":    false,
			"redis": err.Error(),
		})

		return
	}

	h.mu.Lock()
	dipantau := len(h.lastPing)
	h.mu.Unlock()

	writeJSON(w, http.StatusOK, map[string]any{
		"ok":               true,
		"service":          "antaride-location",
		"drivers_tracked":  dipantau,
		"min_interval_ms":  h.cfg.MinInterval.Milliseconds(),
		"max_accuracy_m":   h.cfg.MaxAccuracy,
		"meta_ttl_seconds": int(h.cfg.MetaTTL.Seconds()),
	})
}

// PingRequest adalah badan permintaan ping.
//
// Pointer untuk field opsional, bukan nilai dengan bawaan nol: heading 0 yang
// BENAR (menghadap utara) harus bisa dibedakan dari heading yang TIDAK dikirim.
// Tanpa pointer, keduanya sama-sama 0 — dan panel admin akan menampilkan setiap
// driver menghadap utara.
type PingRequest struct {
	Lat      float64  `json:"lat"`
	Lng      float64  `json:"lng"`
	Heading  *float64 `json:"heading"`
	SpeedKmh *float64 `json:"speed_kmh"`
	Accuracy *float64 `json:"accuracy_m"`
	Battery  *int     `json:"battery_percent"`

	// Layanan yang diminta ditulis. Kalau kosong, dipakai daftar dari TIKET —
	// yang lebih dipercaya, karena ditandatangani Laravel.
	Services []string `json:"services"`
}

func (h *Handler) Ping(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		writeError(w, http.StatusMethodNotAllowed, "METHOD_NOT_ALLOWED", "Pakai POST.")

		return
	}

	tiket, err := h.verifikasi(r)
	if err != nil {
		// 401 untuk semua kegagalan tiket, dengan pesan yang TIDAK membedakan
		// "tanda tangan salah" dari "sudah kadaluarsa".
		//
		// Membedakannya memberi penyerang umpan balik yang berguna: dia jadi
		// tahu tanda tangannya sudah benar dan tinggal soal waktu.
		writeError(w, http.StatusUnauthorized, "INVALID_TICKET", "Tiket lokasi tidak sah.")
		log.Printf("ping ditolak: %v", err)

		return
	}

	// Badan dibatasi 4 KB. Ping yang sah sekitar 200 byte; apa pun yang jauh
	// lebih besar bukan ping, dan membacanya sampai habis berarti membiarkan
	// satu permintaan menghabiskan memori.
	var req PingRequest

	if err := json.NewDecoder(http.MaxBytesReader(w, r.Body, 4096)).Decode(&req); err != nil {
		writeError(w, http.StatusBadRequest, "MALFORMED_BODY", "Badan permintaan tidak bisa dibaca.")

		return
	}

	if alasan := validasiKoordinat(req, h.cfg.MaxAccuracy); alasan != "" {
		writeError(w, http.StatusUnprocessableEntity, "INVALID_POSITION", alasan)

		return
	}

	if !h.lolosPenyaringLaju(tiket.DriverID) {
		/*
		 * 429 dengan badan sukses-semu, BUKAN galat.
		 *
		 * Ping yang terlalu rapat dibuang, dan aplikasi driver TIDAK perlu
		 * menganggapnya kegagalan — tidak ada yang perlu dia coba lagi. Kalau
		 * ini dilaporkan sebagai galat, aplikasi akan mencoba ulang dan
		 * memperburuk keadaan yang sedang disaring.
		 */
		writeJSON(w, http.StatusTooManyRequests, map[string]any{
			"ok":      true,
			"skipped": "rate_limited",
		})

		return
	}

	// Daftar layanan dari tiket yang lebih dipercaya. Yang dikirim badan hanya
	// dipakai untuk MEMPERSEMPIT — driver boleh memilih menulis ke sebagian
	// layanan saja, tapi tidak boleh menambah layanan yang tidak ada di tiketnya.
	services := persempitLayanan(tiket.Services, req.Services)

	ctx, cancel := context.WithTimeout(r.Context(), 2*time.Second)
	defer cancel()

	pos := Position{
		DriverID: tiket.DriverID,
		Lat:      req.Lat,
		Lng:      req.Lng,
		Heading:  req.Heading,
		SpeedKmh: req.SpeedKmh,
		Accuracy: req.Accuracy,
		Battery:  req.Battery,
	}

	if err := h.store.Record(ctx, pos, services); err != nil {
		log.Printf("gagal menulis posisi driver %d: %v", tiket.DriverID, err)
		writeError(w, http.StatusServiceUnavailable, "STORE_UNAVAILABLE", "Coba lagi.")

		return
	}

	writeJSON(w, http.StatusOK, map[string]any{
		"ok":       true,
		"services": services,
	})
}

func (h *Handler) verifikasi(r *http.Request) (*Ticket, error) {
	header := r.Header.Get("Authorization")

	// Tiket juga diterima lewat header tersendiri. Sebagian proxy dan WAF
	// menghapus atau menulis ulang `Authorization`, dan yang terjadi kalau itu
	// satu-satunya jalur adalah 401 yang penyebabnya ada di infrastruktur, bukan
	// di aplikasi.
	if header == "" {
		header = r.Header.Get("X-Antaride-Location-Ticket")
	} else {
		header = strings.TrimPrefix(header, "Bearer ")
	}

	if header == "" {
		return nil, ErrTicketMalformed
	}

	return VerifyTicket(strings.TrimSpace(header), h.cfg.Secret)
}

// lolosPenyaringLaju mengembalikan false kalau ping ini terlalu rapat.
func (h *Handler) lolosPenyaringLaju(driverID int64) bool {
	h.mu.Lock()
	defer h.mu.Unlock()

	sekarang := time.Now()

	if sebelumnya, ada := h.lastPing[driverID]; ada {
		if sekarang.Sub(sebelumnya) < h.cfg.MinInterval {
			return false
		}
	}

	h.lastPing[driverID] = sekarang

	return true
}

// bersihkanBerkala membuang entri penyaring laju milik driver yang sudah lama
// tidak mengirim ping.
//
// Tanpa ini, map-nya tumbuh selamanya. Untuk seribu driver itu tidak berarti
// apa-apa, tapi layanan yang berjalan berbulan-bulan akan mengumpulkan entri
// setiap driver yang pernah online sekali.
func (h *Handler) bersihkanBerkala() {
	tick := time.NewTicker(5 * time.Minute)
	defer tick.Stop()

	for range tick.C {
		batas := time.Now().Add(-10 * time.Minute)

		h.mu.Lock()

		for id, kapan := range h.lastPing {
			if kapan.Before(batas) {
				delete(h.lastPing, id)
			}
		}

		sisa := len(h.lastPing)

		h.mu.Unlock()

		log.Printf("penyaring laju dibersihkan, %d driver masih dipantau", sisa)
	}
}

// validasiKoordinat mengembalikan alasan penolakan, atau string kosong kalau sah.
func validasiKoordinat(req PingRequest, maxAccuracy float64) string {
	if req.Lat < -90 || req.Lat > 90 {
		return "Lintang di luar rentang -90..90."
	}

	if req.Lng < -180 || req.Lng > 180 {
		return "Bujur di luar rentang -180..180."
	}

	/*
	 * Koordinat (0, 0) DITOLAK.
	 *
	 * Titik itu ada di Teluk Guinea, dan tidak ada driver Antaride di sana. Yang
	 * menghasilkannya adalah GPS yang belum mendapat sinyal lalu melaporkan
	 * nilai bawaan — dan menerimanya berarti setiap driver yang baru membuka
	 * aplikasi sesaat tercatat di Afrika Barat.
	 *
	 * Akibatnya nyata: `GEORADIUS` dari Medan tidak akan menemukannya, jadi
	 * driver itu hilang dari pencocokan sampai ping berikutnya yang benar.
	 */
	if req.Lat == 0 && req.Lng == 0 {
		return "Posisi (0,0) ditolak — GPS belum mendapat sinyal."
	}

	if req.Accuracy != nil && *req.Accuracy > maxAccuracy {
		// Akurasi 2 km bukan posisi; dia lingkaran yang memuat separuh kota.
		// Menyimpannya membuat matching mengira driver ada di titik yang dia
		// tidak berada.
		return "Akurasi GPS terlalu buruk untuk dipakai."
	}

	return ""
}

// persempitLayanan mengambil irisan antara layanan di tiket dan yang diminta.
//
// ============================================================================
//
//	TIKET YANG MENENTUKAN BATAS ATASNYA
//
// ============================================================================
//
//	Badan permintaan datang dari aplikasi dan bisa diubah siapa pun yang
//	memegang tiketnya. Kalau daftar layanan diambil dari badan tanpa dibatasi
//	tiket, driver yang hanya berhak atas `ride_bike` bisa menulis posisinya ke
//	`drv:loc:ride_car` — dan mendapat tawaran order taksi dengan sepeda motor.
//
//	Jadi badan hanya boleh MEMPERSEMPIT, tidak pernah memperluas.
//
// ============================================================================
func persempitLayanan(diTiket, diminta []string) []string {
	if len(diminta) == 0 {
		return diTiket
	}

	sah := make(map[string]bool, len(diTiket))

	for _, s := range diTiket {
		sah[s] = true
	}

	hasil := make([]string, 0, len(diminta))

	for _, s := range diminta {
		if sah[s] {
			hasil = append(hasil, s)
		}
	}

	return hasil
}

func writeJSON(w http.ResponseWriter, status int, body any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)

	if err := json.NewEncoder(w).Encode(body); err != nil {
		log.Printf("gagal menulis response: %v", err)
	}
}

// writeError memakai bentuk yang SAMA dengan `ApiResponse::error()` di Laravel.
//
// Aplikasi driver punya satu pengurai response untuk seluruh API — lihat
// `ApiClient._urai` di Flutter. Bentuk yang berbeda di sini berarti pengurai itu
// gagal membaca galatnya dan menampilkan pesan generik alih-alih pesan yang
// sudah ditulis di sini.
func writeError(w http.ResponseWriter, status int, code, message string) {
	writeJSON(w, status, map[string]any{
		"success": false,
		"error": map[string]any{
			"code":    code,
			"message": message,
		},
	})
}
