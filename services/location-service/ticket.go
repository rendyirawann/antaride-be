package main

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/json"
	"errors"
	"strings"
	"time"
)

// Ticket adalah isi tiket lokasi yang diterbitkan Laravel.
type Ticket struct {
	DriverID int64    `json:"driver_id"`
	Services []string `json:"services"`
	Exp      int64    `json:"exp"`
}

var (
	ErrTicketMalformed = errors.New("bentuk tiket tidak dikenali")
	ErrTicketSignature = errors.New("tanda tangan tiket tidak sah")
	ErrTicketExpired   = errors.New("tiket sudah kadaluarsa")
	ErrTicketEmpty     = errors.New("tiket tidak memuat driver")
)

// VerifyTicket memeriksa tanda tangan lalu mengembalikan isinya.
//
// ============================================================================
//
//	URUTANNYA PENTING: TANDA TANGAN DULU, BARU ISINYA
//
// ============================================================================
//
//	Payload TIDAK diurai sebelum tanda tangannya terbukti sah. Kalau dibalik,
//	layanan ini menguraikan JSON dari sumber yang belum dipercaya pada setiap
//	ping — dan itu permukaan serangan yang tidak perlu ada, karena payload yang
//	dibuat penyerang bisa dibentuk untuk membebani parser.
//
//	Biayanya nol: HMAC-SHA256 pada string pendek lebih murah daripada
//	menguraikan JSON.
//
// ============================================================================
func VerifyTicket(raw string, secret []byte) (*Ticket, error) {
	// `SplitN` dengan batas 3, bukan `Split`: tiket yang memuat lebih dari satu
	// titik harus DITOLAK, bukan diambil dua bagian pertamanya. Payload yang
	// menyelipkan titik tambahan adalah percobaan, bukan kesalahan.
	parts := strings.SplitN(raw, ".", 3)

	if len(parts) != 2 {
		return nil, ErrTicketMalformed
	}

	encoded, signature := parts[0], parts[1]

	/*
	 * `hmac.Equal`, bukan `==`.
	 *
	 * Perbandingan string biasa berhenti di byte pertama yang berbeda, dan
	 * lamanya bisa diukur dari luar. Dengan cukup banyak percobaan, penyerang
	 * bisa menebak tanda tangan byte demi byte.
	 *
	 * `hmac.Equal` membandingkan dalam waktu yang tidak bergantung isinya.
	 */
	if !hmac.Equal([]byte(signTicket(encoded, secret)), []byte(signature)) {
		return nil, ErrTicketSignature
	}

	payload, err := base64.RawURLEncoding.DecodeString(encoded)
	if err != nil {
		return nil, ErrTicketMalformed
	}

	var t Ticket

	if err := json.Unmarshal(payload, &t); err != nil {
		return nil, ErrTicketMalformed
	}

	if t.DriverID <= 0 {
		return nil, ErrTicketEmpty
	}

	if t.Exp < time.Now().Unix() {
		return nil, ErrTicketExpired
	}

	return &t, nil
}

func signTicket(encoded string, secret []byte) string {
	mac := hmac.New(sha256.New, secret)
	mac.Write([]byte(encoded))

	// `RawURLEncoding` — base64 URL-safe TANPA padding, cocok dengan
	// `LocationTicket::base64UrlEncode()` di PHP yang membuang `=`.
	//
	// Kalau salah satu sisi memakai padding, tanda tangannya tidak akan pernah
	// cocok — dan gejalanya 401 di setiap ping, tanpa petunjuk bahwa
	// penyebabnya padding.
	return base64.RawURLEncoding.EncodeToString(mac.Sum(nil))
}
