package main

import (
	"context"
	"fmt"
	"strconv"
	"time"

	"github.com/redis/go-redis/v9"
)

// Store menulis posisi driver ke Redis.
//
// ============================================================================
//
//	FORMAT KEY-NYA HARUS PERSIS SAMA DENGAN RedisDriverLocationIndex DI PHP
//
// ============================================================================
//
//	Layanan ini MENULIS, dan matching engine di Laravel yang MEMBACA. Keduanya
//	tidak pernah bertemu di kode — yang menghubungkannya hanya kesepakatan nama
//	key. Kalau salah satu berubah, tidak ada galat di kedua sisi: matching
//	membaca set kosong sementara di sini ping-nya tercatat rapi.
//
//	Yang terlihat cuma driver yang online dan tidak pernah mendapat order.
//
//	Acuannya: app/Infrastructure/Redis/Geo/RedisDriverLocationIndex.php
//
//	    drv:loc:{serviceCode}   GEO set, member "driver:{id}"
//	    drv:meta:{driverId}     HASH lat,lng,heading,speed,acc,battery,ts
//	                            TTL 60 detik
//
//	Set ketersediaan (`drv:available:*`, `drv:zones:*`) TIDAK disentuh di sini.
//	Itu dikelola Laravel saat driver online/offline — bukan per ping.
//
// ============================================================================
//
// ============================================================================
//
//	KONEKSI TANPA PREFIX, DAN INI YANG PALING MUDAH SALAH
//
// ============================================================================
//
//	Laravel memakai koneksi `shared` yang sengaja TIDAK berprefix, khusus untuk
//	key yang dibagi dengan layanan ini. Klien Go di bawah juga tidak menambahkan
//	prefix apa pun.
//
//	Kalau kode PHP di sisi lain memakai koneksi `default` yang berprefix, dia
//	akan membaca `laravel_database_drv:loc:ride_bike` sementara di sini yang
//	ditulis `drv:loc:ride_bike`. Dua key berbeda, tidak ada galat.
//
// ============================================================================
type Store struct {
	client  *redis.Client
	metaTTL time.Duration
}

func NewStore(cfg Config) *Store {
	return &Store{
		client: redis.NewClient(&redis.Options{
			Addr:     cfg.RedisAddr,
			Password: cfg.RedisPassword,
			DB:       cfg.RedisDB,

			// Timeout pendek. Layanan ini menerima ratusan permintaan per detik;
			// koneksi yang menggantung tiga detik akan menumpuk goroutine sampai
			// prosesnya habis memori.
			DialTimeout:  2 * time.Second,
			ReadTimeout:  1 * time.Second,
			WriteTimeout: 1 * time.Second,

			// Pool cukup besar untuk beban ping, tapi tidak tak terbatas —
			// Redis punya batas `maxclients`, dan melewatinya membuat SELURUH
			// aplikasi kehilangan Redis, bukan hanya layanan ini.
			PoolSize:     50,
			MinIdleConns: 5,
		}),
		metaTTL: cfg.MetaTTL,
	}
}

func (s *Store) Ping(ctx context.Context) error {
	return s.client.Ping(ctx).Err()
}

func (s *Store) Close() error {
	return s.client.Close()
}

// Position adalah satu ping yang sudah lolos validasi.
type Position struct {
	DriverID int64
	Lat      float64
	Lng      float64
	Heading  *float64
	SpeedKmh *float64
	Accuracy *float64
	Battery  *int
}

// Record menulis posisi ke seluruh layanan yang driver aktifkan.
//
// Satu pipeline untuk semuanya: driver yang mengaktifkan tiga layanan
// menghasilkan tiga GEOADD, dan mengirimnya satu per satu berarti tiga
// perjalanan jaringan untuk satu ping.
func (s *Store) Record(ctx context.Context, pos Position, services []string) error {
	if len(services) == 0 {
		// Driver tanpa layanan aktif tidak bisa dicocokkan ke apa pun, jadi
		// menulis posisinya ke GEO set tidak ada gunanya. Metanya TETAP ditulis:
		// panel admin memakainya untuk melihat posisi terakhir driver.
		return s.recordMetaOnly(ctx, pos)
	}

	member := "driver:" + strconv.FormatInt(pos.DriverID, 10)
	metaKey := fmt.Sprintf("drv:meta:%d", pos.DriverID)

	pipe := s.client.Pipeline()

	for _, service := range services {
		pipe.GeoAdd(ctx, "drv:loc:"+service, &redis.GeoLocation{
			Name: member,

			// Longitude DULU, lalu latitude — urutan GEOADD di Redis, dan urutan
			// yang paling mudah tertukar. Tertukar berarti setiap driver di Medan
			// (3.6 LU, 98.7 BT) tercatat di 98.7 LU — di Samudra Arktik.
			//
			// Redis TIDAK menolaknya: 98.7 masih dalam rentang lintang yang sah.
			Longitude: pos.Lng,
			Latitude:  pos.Lat,
		})
	}

	s.queueMeta(ctx, pipe, metaKey, pos)

	_, err := pipe.Exec(ctx)

	return err
}

func (s *Store) recordMetaOnly(ctx context.Context, pos Position) error {
	metaKey := fmt.Sprintf("drv:meta:%d", pos.DriverID)

	pipe := s.client.Pipeline()
	s.queueMeta(ctx, pipe, metaKey, pos)

	_, err := pipe.Exec(ctx)

	return err
}

// queueMeta menyusun HSET meta beserta TTL-nya.
//
// Nama field dan formatnya mengikuti `RedisDriverLocationIndex::record()` di
// PHP: nilainya STRING, bukan angka — `hmset` di PHP menulisnya sebagai string,
// dan pembacanya mengurai dari string.
func (s *Store) queueMeta(
	ctx context.Context,
	pipe redis.Pipeliner,
	key string,
	pos Position,
) {
	fields := []any{
		"lat", strconv.FormatFloat(pos.Lat, 'f', -1, 64),
		"lng", strconv.FormatFloat(pos.Lng, 'f', -1, 64),
		"ts", strconv.FormatInt(time.Now().Unix(), 10),
	}

	// Field opsional hanya ditulis kalau ada nilainya.
	//
	// Menulis "0" untuk heading yang tidak diketahui akan membuat panel admin
	// menampilkan setiap driver menghadap ke utara — dan itu tampak seperti data
	// yang benar, bukan data yang hilang.
	if pos.Heading != nil {
		fields = append(fields, "heading", strconv.FormatFloat(*pos.Heading, 'f', -1, 64))
	}

	if pos.SpeedKmh != nil {
		fields = append(fields, "speed", strconv.FormatFloat(*pos.SpeedKmh, 'f', -1, 64))
	}

	if pos.Accuracy != nil {
		fields = append(fields, "acc", strconv.FormatFloat(*pos.Accuracy, 'f', -1, 64))
	}

	if pos.Battery != nil {
		fields = append(fields, "battery", strconv.Itoa(*pos.Battery))
	}

	pipe.HSet(ctx, key, fields...)

	/*
	 * TTL diperbarui di SETIAP ping, dan itu inti mekanismenya.
	 *
	 * Driver yang berhenti mengirim ping — aplikasinya ditutup, HP-nya mati,
	 * sinyalnya hilang — metanya kadaluarsa dalam 60 detik, dan matching
	 * menyaringnya keluar sebagai driver yang tidak lagi terpantau.
	 *
	 * Tanpa TTL, driver yang HP-nya mati akan terus terlihat tersedia di posisi
	 * terakhirnya selamanya — dan order akan ditawarkan kepadanya berulang,
	 * kadaluarsa tanpa jawaban, lalu penumpang menunggu tanpa hasil.
	 */
	pipe.Expire(ctx, key, s.metaTTL)
}
