<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Enums;

/**
 * Status order dan transisi yang diizinkan.
 *
 * Transisi didefinisikan EKSPLISIT, bukan dibiarkan bebas. Blueprint bagian 12
 * menempatkan ini sebagai kesalahan nomor delapan yang paling sering terjadi:
 * "Status order bebas berubah ke mana saja tanpa state machine. Order selesai
 * kembali jadi mencari driver, dan tidak ada yang tahu kenapa."
 *
 * ============================================================================
 *  PERINGATAN
 * ============================================================================
 *  activeStatuses() HARUS sama dengan daftar di partial unique index
 *  `orders_one_active_per_driver` pada migration ordering.
 *
 *  Kalau berbeda, driver bisa memegang dua order sekaligus tanpa satu pun error
 *  muncul: database mengizinkan karena statusnya tidak ada di daftar index, dan
 *  kode mengizinkan karena statusnya tidak ada di daftar sini.
 *
 *  Ada test yang membandingkan keduanya langsung ke definisi index di database:
 *  Tests\Feature\Ordering\OrderStatusMatchesDatabaseIndexTest
 * ============================================================================
 */
enum OrderStatus: string
{
    /** Order dibuat, harga sudah dibekukan, belum masuk antrean matching. */
    case Created = 'created';

    /** Job matching sedang menawarkan ke driver. */
    case Searching = 'searching';

    /** Seorang driver menerima. */
    case Accepted = 'accepted';

    /** Driver bergerak menuju titik jemput. */
    case DriverArriving = 'driver_arriving';

    /** Driver masuk radius 100 m titik jemput. Timer tunggu mulai. */
    case DriverArrived = 'driver_arrived';

    /** Kode jemput sudah dimasukkan. Tidak bisa dibatalkan gratis lagi. */
    case InProgress = 'in_progress';

    /** Selesai. Settlement ledger jalan, rating diminta. */
    case Completed = 'completed';

    case Cancelled = 'cancelled';

    /** Empat gelombang penawaran habis tanpa ada yang menerima. */
    case NoDriver = 'no_driver';

    /** Dibuat tapi tidak pernah dikonfirmasi sampai quote-nya kadaluarsa. */
    case Expired = 'expired';

    // -------------------------------------------------------------------------
    // Transisi
    // -------------------------------------------------------------------------

    /**
     * Status yang boleh diikuti status ini.
     *
     * Perhatikan bahwa Completed, Cancelled, NoDriver, dan Expired tidak punya
     * lanjutan sama sekali. Itu yang membuat order selesai tidak bisa kembali
     * mencari driver.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Created => [self::Searching, self::Cancelled, self::Expired],
            self::Searching => [self::Accepted, self::NoDriver, self::Cancelled],
            self::Accepted => [self::DriverArriving, self::Cancelled],
            self::DriverArriving => [self::DriverArrived, self::Cancelled],
            self::DriverArrived => [self::InProgress, self::Cancelled],
            self::InProgress => [self::Completed, self::Cancelled],

            // Status akhir. Tidak ada jalan keluar.
            self::Completed,
            self::Cancelled,
            self::NoDriver,
            self::Expired => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    // -------------------------------------------------------------------------
    // Pengelompokan
    // -------------------------------------------------------------------------

    /**
     * Status yang berarti driver sedang memegang order ini.
     *
     * HARUS sama dengan daftar di partial unique index
     * `orders_one_active_per_driver`. Jangan ubah salah satu tanpa yang lain.
     *
     * @return array<int, self>
     */
    public static function activeStatuses(): array
    {
        return [
            self::Accepted,
            self::DriverArriving,
            self::DriverArrived,
            self::InProgress,
        ];
    }

    /**
     * Status yang berarti user sedang punya order berjalan, termasuk yang
     * masih mencari driver.
     *
     * HARUS sama dengan daftar di `orders_one_active_per_user`.
     *
     * @return array<int, self>
     */
    public static function userBlockingStatuses(): array
    {
        return array_merge(
            [self::Created, self::Searching],
            self::activeStatuses(),
        );
    }

    /**
     * @return array<int, string>
     */
    public static function activeValues(): array
    {
        return array_map(fn (self $s) => $s->value, self::activeStatuses());
    }

    /**
     * @return array<int, string>
     */
    public static function userBlockingValues(): array
    {
        return array_map(fn (self $s) => $s->value, self::userBlockingStatuses());
    }

    public function isActiveForDriver(): bool
    {
        return in_array($this, self::activeStatuses(), true);
    }

    /**
     * Apakah driver sudah ditugaskan pada status ini.
     */
    public function hasDriver(): bool
    {
        return $this->isActiveForDriver() || $this === self::Completed;
    }

    /**
     * Apakah pembatalan pada status ini boleh dikenai biaya.
     *
     * Sebelum driver menerima, tidak ada yang dirugikan waktu, jadi gratis.
     * Setelah in_progress, perjalanan sudah dimulai dan pembatalan berarti
     * driver kehilangan seluruh ongkos.
     */
    public function cancellationMayIncurFee(): bool
    {
        return in_array($this, [
            self::Accepted,
            self::DriverArriving,
            self::DriverArrived,
            self::InProgress,
        ], true);
    }

    /**
     * Apakah order pada status ini masih boleh dibatalkan.
     *
     * ========================================================================
     *  SATU DAFTAR, BUKAN DUA
     * ========================================================================
     *  Daftar ini dibaca oleh `CancelOrder` (yang menegakkannya) DAN oleh
     *  Resource API (yang mengirim `can_cancel` ke aplikasi). Kalau keduanya
     *  punya daftarnya sendiri, akan ada hari di mana aplikasi menampilkan
     *  tombol "Batalkan" yang selalu ditolak backend — bug yang terlihat sebagai
     *  aplikasi rusak, dan yang menerima keluhannya adalah CS.
     *
     *  `InProgress` TIDAK termasuk. Order yang sedang berjalan — penumpang sudah
     *  di atas kendaraan — tidak bisa "dibatalkan"; dia harus diselesaikan.
     *  Membiarkannya dibatalkan berarti perjalanan yang sudah separuh jalan
     *  menjadi gratis, dan driver tidak mendapat apa pun.
     * ========================================================================
     */
    public function isCancellable(): bool
    {
        return in_array($this, [
            self::Created,
            self::Searching,
            self::Accepted,
            self::DriverArriving,
            self::DriverArrived,
        ], true);
    }

    // -------------------------------------------------------------------------
    // Penyajian
    // -------------------------------------------------------------------------

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Dibuat',
            self::Searching => 'Mencari driver',
            self::Accepted => 'Driver ditemukan',
            self::DriverArriving => 'Driver menuju lokasi',
            self::DriverArrived => 'Driver sudah tiba',
            self::InProgress => 'Dalam perjalanan',
            self::Completed => 'Selesai',
            self::Cancelled => 'Dibatalkan',
            self::NoDriver => 'Tidak ada driver',
            self::Expired => 'Kadaluarsa',
        };
    }

    /**
     * Kalimat yang dilihat penumpang di aplikasi.
     *
     * Dipisah dari label() karena label dipakai di panel admin yang butuh
     * istilah teknis ringkas, sementara yang ini dibaca orang yang sedang
     * menunggu di pinggir jalan.
     */
    public function customerMessage(): string
    {
        return match ($this) {
            self::Created => 'Pesanan sedang disiapkan',
            self::Searching => 'Mencari driver terdekat untuk Anda',
            self::Accepted => 'Driver sudah ditemukan',
            self::DriverArriving => 'Driver sedang menuju lokasi Anda',
            self::DriverArrived => 'Driver sudah tiba di titik jemput',
            self::InProgress => 'Perjalanan sedang berlangsung',
            self::Completed => 'Pesanan selesai',
            self::Cancelled => 'Pesanan dibatalkan',
            self::NoDriver => 'Belum ada driver yang tersedia saat ini',
            self::Expired => 'Pesanan kadaluarsa karena tidak dikonfirmasi',
        };
    }

    /**
     * Warna dipakai sebagai data, bukan hiasan: satu untuk berjalan normal,
     * satu untuk perlu tindakan, satu untuk gagal.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Created, self::Searching => 'badge-light-warning',
            self::Accepted, self::DriverArriving,
            self::DriverArrived, self::InProgress => 'badge-light-primary',
            self::Completed => 'badge-light-success',
            self::Cancelled, self::Expired => 'badge-light-dark',
            self::NoDriver => 'badge-light-danger',
        };
    }

    /**
     * Cap waktu di tabel orders yang diisi saat masuk status ini.
     */
    public function timestampColumn(): ?string
    {
        return match ($this) {
            self::Accepted => 'matched_at',
            self::DriverArrived => 'arrived_at',
            self::InProgress => 'started_at',
            self::Completed => 'completed_at',
            self::Cancelled => 'cancelled_at',
            default => null,
        };
    }
}
