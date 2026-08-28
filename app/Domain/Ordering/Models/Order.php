<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Models;

use App\Domain\Catalog\Models\PricingRule;
use App\Domain\Catalog\Models\ServiceType;
use App\Domain\Catalog\Models\Zone;
use App\Domain\Driver\Models\Driver;
use App\Domain\Driver\Models\Vehicle;
use App\Domain\Identity\Models\User;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Promo\Models\Promo;
use App\Domain\Shared\Concerns\HasUuid;
use App\Domain\Shared\Support\BusinessClock;
use App\Domain\Shared\ValueObjects\Coordinate;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Shared\ValueObjects\Polyline;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/**
 * Order: inti seluruh sistem.
 *
 * Seluruh kolom uang adalah SNAPSHOT yang dibekukan saat order dibuat. Tarif
 * boleh berubah besok; ongkos order yang sudah jalan tidak. Itu sebabnya
 * `pricing_rule_id` juga disimpan: kalau ada sengketa tiga bulan kemudian,
 * angkanya bisa dilacak ke aturan persisnya, bukan ke aturan yang berlaku
 * sekarang.
 *
 * Status HANYA berubah lewat OrderStateMachine. Mengubahnya langsung dengan
 * `$order->status = ...` akan melewati pencatatan ke `order_status_logs` dan
 * pengiriman event realtime, dan yang terjadi adalah penumpang tidak pernah
 * tahu drivernya sudah tiba.
 */
#[UseFactory(OrderFactory::class)]
class Order extends Model
{
    use HasFactory;
    use HasUuid;

    protected $fillable = [
        'order_number',
        'user_id',
        'service_type_id',
        'zone_id',
        'driver_id',
        'vehicle_id',
        'status',
        'payment_method',
        'payment_status',
        'distance_m',
        'duration_s',
        'base_fare',
        'distance_fare',
        'time_fare',
        'surge_multiplier',
        'surge_amount',
        'regulatory_adjustment',
        'platform_fee',
        'service_fee',
        'discount_amount',
        'promo_id',
        'total_fare',
        'driver_earning',
        'commission_amount',
        'pricing_rule_id',
        'pickup_address',
        'pickup_lat',
        'pickup_lng',
        'pickup_note',
        'dest_address',
        'dest_lat',
        'dest_lng',
        'pickup_code',
        'route_polyline',
        'actual_polyline',
        'actual_distance_m',
        'needs_fare_review',
        'requested_at',
        'matched_at',
        'arrived_at',
        'started_at',
        'completed_at',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason_id',
        'cancellation_note',
        'cancellation_fee',
        'idempotency_key',
    ];

    /**
     * Kode jemput tidak pernah ikut serialisasi otomatis.
     *
     * Kode ini yang mencegah driver mengaku sudah menjemput padahal belum.
     * Kalau ikut terkirim di response endpoint driver, seluruh gunanya hilang:
     * driver bisa membacanya dari payload tanpa perlu bertanya ke penumpang.
     *
     * Endpoint penumpang menampilkannya secara eksplisit lewat Resource.
     */
    protected $hidden = ['pickup_code', 'idempotency_key'];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            // Nilai uang tetap integer, tanpa cast khusus. Pembungkusan ke
            // Money dilakukan method di bawah, bukan cast, supaya kolomnya
            // tetap bisa dipakai langsung di query builder dan agregasi.
            'distance_m' => 'integer',
            'duration_s' => 'integer',
            'actual_distance_m' => 'integer',
            'surge_multiplier' => 'string',
            'pickup_lat' => 'float',
            'pickup_lng' => 'float',
            'dest_lat' => 'float',
            'dest_lng' => 'float',
            'needs_fare_review' => 'boolean',
            'requested_at' => 'datetime',
            'matched_at' => 'datetime',
            'arrived_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    // -------------------------------------------------------------------------
    // Relasi
    // -------------------------------------------------------------------------

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function pricingRule(): BelongsTo
    {
        return $this->belongsTo(PricingRule::class);
    }

    public function promo(): BelongsTo
    {
        return $this->belongsTo(Promo::class);
    }

    public function stops(): HasMany
    {
        return $this->hasMany(OrderStop::class)->orderBy('sequence');
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(OrderStatusLog::class)->orderBy('created_at');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(OrderOffer::class);
    }

    public function chats(): HasMany
    {
        return $this->hasMany(OrderChat::class)->orderBy('created_at');
    }

    /**
     * Penilaian untuk order ini — dari penumpang dan/atau dari driver.
     *
     * `hasMany`, bukan `hasOne`, karena kedua pihak menilai satu sama lain:
     * `unique(order_id, rater_type)` membatasinya jadi maksimal dua baris, satu
     * per pihak. Memodelkannya sebagai `hasOne` akan mengembalikan penilaian
     * yang sembarang dari keduanya.
     */
    public function ratings(): HasMany
    {
        return $this->hasMany(Rating::class);
    }

    public function rating(): HasOne
    {
        return $this->hasOne(Rating::class);
    }

    public function cancellationReason(): BelongsTo
    {
        return $this->belongsTo(CancellationReason::class, 'cancellation_reason_id');
    }

    // -------------------------------------------------------------------------
    // Scope
    // -------------------------------------------------------------------------

    /**
     * Order yang sedang dipegang driver.
     *
     * Daftar statusnya dari enum, bukan ditulis ulang di sini. Menulis ulang
     * berarti ada dua daftar yang harus sepakat, dan satu di antaranya akan
     * tertinggal.
     */
    public function scopeActiveForDriver(Builder $query): Builder
    {
        return $query->whereIn('status', OrderStatus::activeValues());
    }

    /**
     * Order yang membuat pengguna tidak boleh membuat order baru.
     */
    public function scopeBlockingForUser(Builder $query): Builder
    {
        return $query->whereIn('status', OrderStatus::userBlockingValues());
    }

    public function scopeSearching(Builder $query): Builder
    {
        return $query->where('status', OrderStatus::Searching);
    }

    /**
     * Order yang sudah mencari driver lebih lama dari ambang.
     *
     * Ini yang paling sering dilihat tim ops di live map.
     */
    public function scopeStuckSearching(Builder $query, ?int $seconds = null): Builder
    {
        $seconds ??= (int) config('antaride.live_map.stuck_order_highlight_seconds', 60);

        return $query
            ->where('status', OrderStatus::Searching)
            ->where('requested_at', '<=', now()->subSeconds($seconds));
    }

    public function scopeNeedsFareReview(Builder $query): Builder
    {
        return $query->where('needs_fare_review', true);
    }

    // -------------------------------------------------------------------------
    // Nilai uang
    // -------------------------------------------------------------------------

    public function totalFare(): Money
    {
        return Money::of((int) $this->total_fare);
    }

    public function driverEarning(): Money
    {
        return Money::of((int) $this->driver_earning);
    }

    public function commission(): Money
    {
        return Money::of((int) $this->commission_amount);
    }

    public function discount(): Money
    {
        return Money::of((int) $this->discount_amount);
    }

    public function cancellationFee(): Money
    {
        return Money::of((int) $this->cancellation_fee);
    }

    // -------------------------------------------------------------------------
    // Lokasi
    // -------------------------------------------------------------------------

    public function pickupCoordinate(): Coordinate
    {
        return Coordinate::of($this->pickup_lat, $this->pickup_lng);
    }

    public function destinationCoordinate(): ?Coordinate
    {
        if ($this->dest_lat === null || $this->dest_lng === null) {
            return null;
        }

        return Coordinate::of($this->dest_lat, $this->dest_lng);
    }

    public function plannedRoute(): Polyline
    {
        return Polyline::decode((string) ($this->route_polyline ?? ''));
    }

    public function actualRoute(): Polyline
    {
        return Polyline::decode((string) ($this->actual_polyline ?? ''));
    }

    // -------------------------------------------------------------------------
    // Perilaku
    // -------------------------------------------------------------------------

    public function isActiveForDriver(): bool
    {
        return $this->status->isActiveForDriver();
    }

    public function isFinal(): bool
    {
        return $this->status->isFinal();
    }

    public function isCash(): bool
    {
        return $this->payment_method === 'cash';
    }

    /**
     * Apakah pembatalan sekarang dikenai biaya.
     *
     * Gratis selama masih di jendela yang ditentukan setelah driver menerima.
     * Setelah itu, driver sudah menghabiskan waktu dan bahan bakar menuju titik
     * jemput, dan pembatalan menanggung biaya.
     */
    public function cancellationIncursFee(): bool
    {
        if (! $this->status->cancellationMayIncurFee()) {
            return false;
        }

        if ($this->matched_at === null) {
            return false;
        }

        $window = (int) config('antaride.order.free_cancel_window_seconds', 180);

        return $this->matched_at->addSeconds($window)->isPast();
    }

    /**
     * Berapa lama penumpang menunggu sampai mendapat driver, dalam detik.
     *
     * Ini metrik kualitas layanan yang paling menentukan. Null kalau belum
     * dapat driver.
     */
    public function waitSeconds(): ?int
    {
        if ($this->matched_at === null) {
            return null;
        }

        return max(0, (int) floor($this->requested_at->diffInSeconds($this->matched_at, absolute: true)));
    }

    /**
     * Selisih jarak aktual dari estimasi, sebagai persentase.
     *
     * Selisih besar menandai order untuk direview, bukan di-settle otomatis
     * (blueprint bagian 5). Null kalau belum ada jarak aktual.
     */
    public function distanceVariancePercent(): ?float
    {
        if ($this->actual_distance_m === null || $this->distance_m === 0) {
            return null;
        }

        return round(
            (($this->actual_distance_m - $this->distance_m) / $this->distance_m) * 100,
            2,
        );
    }

    /**
     * Nomor order dengan format RD-20260827-000123.
     *
     * Dibuat dari tanggal plus urutan harian. Formatnya dipilih supaya bisa
     * dibacakan lewat telepon tanpa ambigu, karena CS memakainya setiap hari.
     */
    public static function generateOrderNumber(?\DateTimeInterface $at = null): string
    {
        $prefix = (string) config('antaride.brand.order_number_prefix', 'RD');

        // Tanggal dan batas hari memakai ZONA BISNIS, bukan UTC.
        //
        // Tanpa itu, nomor order berganti tanggal pada jam 7 pagi WIB: order
        // jam 6 pagi tanggal 28 masih bernomor RD-20260827-xxx, dan urutannya
        // ikut melanjutkan hitungan hari sebelumnya. Yang menerima akibatnya
        // adalah CS yang mencari order berdasarkan tanggal dan tim finance yang
        // merekonsiliasi harian.
        $businessDate = BusinessClock::at($at);
        $date = $businessDate->format('Ymd');
        [$dayStart, $dayEnd] = BusinessClock::dayRange($at);

        /*
         * =====================================================================
         *  URUTAN DIAMBIL DARI NOMOR TERTINGGI, BUKAN DARI JUMLAH BARIS
         * =====================================================================
         *  Versi pertama memakai `count() + 1`. Itu terlihat setara, dan tidak.
         *
         *  Nomor order punya unique constraint, jadi tabrakan ditangkap database
         *  dan pemanggil mengulang. Yang menentukan apakah pengulangan itu ada
         *  gunanya adalah: apakah nomor berikutnya akan BERBEDA.
         *
         *  Dengan count(), nomornya fungsi murni dari jumlah baris. Begitu ada
         *  satu baris yang hilang dari hitungan — order dihapus, atau
         *  created_at-nya dipindah ke hari lain — jumlah baris berhenti sejalan
         *  dengan nomor tertinggi. Contohnya:
         *
         *    100 order hari ini, satu dihapus  -> count = 99
         *    nomor yang dihasilkan             -> 000100  (sudah dipakai)
         *    INSERT gagal, ulangi              -> count masih 99
         *    nomor yang dihasilkan             -> 000100  lagi
         *
         *  Pembuatan order MATI untuk sisa hari itu, dan setiap percobaan
         *  menghasilkan nomor yang sama persis. Pengulangan tidak akan pernah
         *  berhasil karena tidak ada yang berubah di antara percobaan.
         *
         *  MAX menyelesaikannya karena dia dihitung dari domain yang sama dengan
         *  nilai yang dicari — dari nomor, bukan dari jumlah baris. Baris yang
         *  hilang tidak pernah menurunkan nomor tertinggi, dan tabrakan selalu
         *  menaikkannya, jadi percobaan berikutnya pasti berbeda.
         *
         *  split_part dipakai supaya panjang prefix tidak perlu dihitung; ini
         *  juga alasan prefix dilarang memuat tanda hubung.
         * =====================================================================
         */
        if (str_contains($prefix, '-')) {
            throw new \LogicException(
                "Prefix nomor order tidak boleh memuat tanda hubung: '{$prefix}'. "
                .'Tanda hubung dipakai sebagai pemisah bagian nomor.'
            );
        }

        $highest = (int) self::query()
            ->whereBetween('created_at', [$dayStart, $dayEnd])
            ->max(DB::raw("NULLIF(split_part(order_number, '-', 3), '')::int"));

        return sprintf('%s-%s-%06d', $prefix, $date, $highest + 1);
    }
}
