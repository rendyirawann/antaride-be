<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Ordering\DTOs\NewOrderRequest;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Exceptions\OrderNumberExhaustedException;
use App\Domain\Ordering\Exceptions\QuoteNotFoundException;
use App\Domain\Ordering\Exceptions\UserHasActiveOrderException;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderStatusLog;
use App\Domain\Pricing\Contracts\QuoteStore;
use App\Domain\Pricing\DTOs\Quote;
use App\Domain\Pricing\DTOs\QuoteOption;
use App\Domain\Promo\Models\Promo;
use App\Domain\Shared\Contracts\RealtimePublisher;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Wallet\Actions\HoldFunds;
use App\Domain\Wallet\Models\Wallet;
use App\Jobs\MatchDriverJob;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Membuat order baru.
 *
 * ============================================================================
 *  HARGA TIDAK PERNAH DATANG DARI CLIENT
 * ============================================================================
 *  Client mengirim `quote_id`, bukan angka. Harganya dibaca dari Redis, dari
 *  quote yang dihitung backend beberapa menit sebelumnya.
 *
 *  Kalau harga dikirim client, satu request yang diubah isinya cukup untuk
 *  membuat order Rp 1. Ini bukan kerentanan hipotetis: aplikasi mobile bisa
 *  dibongkar, dan HTTPS tidak melindungi apa pun dari pemilik perangkatnya
 *  sendiri.
 *
 *  Konsekuensi yang harus diterima: quote kadaluarsa (5 menit) berarti client
 *  harus meminta harga baru. Itu memang yang benar — tarif dan surge bisa
 *  berubah, dan order yang dibuat dari harga sepuluh menit lalu adalah janji
 *  yang tidak lagi berlaku.
 * ============================================================================
 *
 * ============================================================================
 *  URUTANNYA DIPILIH SUPAYA KEGAGALAN TIDAK MENINGGALKAN SISA
 * ============================================================================
 *    1. Baca quote                    tanpa efek samping
 *    2. Periksa order aktif pengguna  tanpa efek samping
 *    3. --- transaksi dibuka ---
 *    4. Kunci & pesan kuota promo     SELECT FOR UPDATE
 *    5. Simpan order
 *    6. Tahan dana (kalau wallet)
 *    7. --- commit ---
 *    8. Hapus quote, jadwalkan matching, kirim realtime
 *
 *  Yang di dalam transaksi adalah semua yang harus batal bersama-sama. Kalau
 *  penahanan dana gagal karena saldo kurang, kuota promo yang sudah dipesan
 *  ikut dilepas dan ordernya tidak pernah ada — bukan order menggantung yang
 *  menghabiskan kuota promo tanpa pernah jalan.
 *
 *  Yang di luar transaksi adalah yang tidak boleh menahan lock baris: Redis dan
 *  HTTP ke gateway realtime. Gateway yang lambat tidak boleh membuat tabel
 *  order terkunci.
 * ============================================================================
 */
class CreateOrder
{
    public function __construct(
        private readonly QuoteStore $quotes,
        private readonly HoldFunds $holdFunds,
        private readonly RealtimePublisher $realtime,
    ) {}

    public function handle(User $user, NewOrderRequest $request): Order
    {
        $quote = $this->quotes->get($request->quoteId);

        if ($quote === null || $quote->isExpired()) {
            throw QuoteNotFoundException::make();
        }

        /*
         * Quote milik pengguna lain tidak boleh dipakai.
         *
         * quote_id adalah UUID, jadi menebaknya tidak praktis — tapi "tidak
         * praktis" bukan alasan untuk melewatkan pemeriksaan pemilik. Quote
         * bocor lewat log, lewat screenshot, dan lewat riwayat HTTP di
         * perangkat yang dipakai bersama.
         */
        if ($quote->userId !== (int) $user->getKey()) {
            throw QuoteNotFoundException::make();
        }

        $option = $quote->option($request->serviceCode);

        if ($option === null) {
            throw QuoteNotFoundException::serviceNotInQuote($request->serviceCode);
        }

        $this->assertUserHasNoActiveOrder($user);

        $order = DB::transaction(
            fn (): Order => $this->persist($user, $request, $quote, $option)
        );

        $this->afterCommit($order, $request);

        return $order;
    }

    // -------------------------------------------------------------------------

    /**
     * Pengguna hanya boleh punya satu order berjalan.
     *
     * Ini aturan produk, bukan batasan teknis: penumpang yang memesan dua ojek
     * sekaligus membuat satu driver datang untuk penumpang yang sudah berangkat,
     * dan driver itu tidak mendapat apa pun.
     *
     * Diperiksa sebelum transaksi karena ini pemeriksaan murah yang menolak
     * mayoritas kasus, dan pesannya harus jelas. Balapan sempit — dua request
     * bersamaan dari pengguna yang sama — ditangkap unique index idempotency_key
     * pada tabel orders, yang membuat request kedua gagal, bukan membuat order
     * kedua.
     */
    private function assertUserHasNoActiveOrder(User $user): void
    {
        $active = Order::query()
            ->where('user_id', $user->getKey())
            ->blockingForUser()
            ->first();

        if ($active !== null) {
            throw UserHasActiveOrderException::make($active);
        }
    }

    private function persist(
        User $user,
        NewOrderRequest $request,
        Quote $quote,
        QuoteOption $option,
    ): Order {
        /*
         * Diskon diambil dari QUOTE, bukan dari tabel promo dan bukan dari
         * client.
         *
         * `$option->fare` adalah harga TANPA promo — nominal diskon per promo
         * disimpan terpisah di `eligiblePromos`, karena satu quote menawarkan
         * beberapa promo sekaligus dan penumpang memilih salah satunya di layar
         * konfirmasi.
         *
         * Menghitung ulang diskonnya di sini akan membuka celah yang persis
         * ingin dihindari: nominal yang ditagih bisa berbeda dari yang dilihat
         * penumpang, karena aturan promo bisa berubah di antara keduanya.
         */
        $discount = $request->promoCode === null
            ? 0
            : (int) ($quote->promoDiscountFor($request->promoCode, $request->serviceCode) ?? 0);

        $promo = $this->reservePromoQuota($request, $user, $option, $discount);

        if ($promo === null) {
            // Promo tidak berlaku, jadi tidak ada diskon. Ini jalur yang paling
            // sering terjadi setelah kuota promo habis, dan harganya harus
            // kembali ke harga penuh secara utuh.
            $discount = 0;
        }

        $order = $this->insertOrder($user, $request, $quote, $option, $promo, $discount);

        if ($request->isWalletPayment()) {
            $this->holdPayment($user, $order);
        }

        $this->logInitialStatus($order, $user);

        if ($promo !== null) {
            $this->recordPromoUsage($promo, $user, $order);
        }

        return $order;
    }

    /**
     * Kunci baris promo lalu naikkan pemakaiannya.
     *
     * ========================================================================
     *  KENAPA SELECT FOR UPDATE, BUKAN UPDATE ... WHERE used_count < quota
     * ========================================================================
     *  Bentuk `UPDATE ... SET used_count = used_count + 1 WHERE used_count <
     *  quota_total` sebenarnya atomik dan benar untuk kuota total. Yang tidak
     *  bisa dia lakukan adalah memeriksa kuota PER PENGGUNA dalam operasi yang
     *  sama, karena itu butuh menghitung baris di promo_usages.
     *
     *  Dua pemeriksaan yang harus benar bersamaan menuntut satu lock. Tanpa
     *  lock, pengguna yang menekan tombol tiga kali pada detik yang sama akan
     *  lolos ketiganya: setiap request menghitung pemakaiannya sendiri sebagai
     *  nol, dan promo "maksimal satu kali per pengguna" terpakai tiga kali.
     *
     *  Kerugiannya nyata dan berulang. Promo cashback Rp 20.000 yang dipakai
     *  tiga kali oleh satu orang, dikali beberapa ratus orang yang tahu
     *  caranya, adalah biaya pemasaran yang tidak pernah dianggarkan.
     * ========================================================================
     */
    private function reservePromoQuota(
        NewOrderRequest $request,
        User $user,
        QuoteOption $option,
        int $discount,
    ): ?Promo {
        if ($request->promoCode === null || $discount <= 0) {
            return null;
        }

        /** @var Promo|null $promo */
        $promo = Promo::query()
            ->where('code', $request->promoCode)
            ->lockForUpdate()
            ->first();

        if ($promo === null || ! $promo->isRedeemable()) {
            // Promo yang tidak berlaku TIDAK menggagalkan order.
            //
            // Penumpang sudah melihat harga dengan diskon di layar konfirmasi,
            // jadi menggagalkan seluruh order karena promonya baru habis
            // beberapa detik lalu adalah pengalaman yang buruk untuk kesalahan
            // yang bukan miliknya. Yang benar: order jalan dengan harga penuh,
            // dan aplikasi memberi tahu promonya tidak terpakai.
            //
            // Karena diskonnya diambil dari quote (lihat discountFor di bawah),
            // promo null berarti diskon nol, dan angkanya tetap konsisten.
            return null;
        }

        if (! $promo->hasQuotaLeft()) {
            return null;
        }

        if (! $promo->appliesToService($option->serviceTypeId)) {
            return null;
        }

        if (! $promo->appliesToPaymentMethod($request->paymentMethod)) {
            return null;
        }

        if ($promo->quota_per_user !== null) {
            $usedByUser = DB::table('promo_usages')
                ->where('promo_id', $promo->getKey())
                ->where('user_id', $user->getKey())
                ->count();

            if ($usedByUser >= $promo->quota_per_user) {
                return null;
            }
        }

        $promo->increment('used_count');

        return $promo;
    }

    private function insertOrder(
        User $user,
        NewOrderRequest $request,
        Quote $quote,
        QuoteOption $option,
        ?Promo $promo,
        int $discount,
    ): Order {
        $columns = $option->fare->toOrderColumns();

        /*
         * Diskon diterapkan ke DUA kolom sekaligus, dan itu wajib.
         *
         * `orders_breakdown_sums_check` menuntut:
         *
         *   total_fare = base + jarak + waktu + surge + regulasi
         *              + biaya_app + biaya_layanan - diskon
         *
         * Jadi menaikkan discount_amount tanpa menurunkan total_fare akan
         * ditolak database, dan sebaliknya. Menyimpan keduanya di satu tempat
         * seperti ini yang membuat kesalahan itu tidak mungkin terjadi diam-diam.
         *
         * Diskon diklem ke total: promo yang nilainya melebihi ongkos
         * menghasilkan order gratis, bukan order bernilai negatif — dan
         * `orders_money_check` memang menolak total negatif.
         */
        if ($discount > 0) {
            $discount = min($discount, (int) $columns['total_fare']);

            $columns['discount_amount'] = $discount;
            $columns['total_fare'] -= $discount;
        }

        $attempts = 0;

        /*
         * Nomor order bisa bertabrakan dengan order lain yang dibuat pada
         * milidetik yang sama. Unique constraint yang menangkapnya, dan
         * pengulangan yang menyelesaikannya — nomornya diturunkan dari nomor
         * TERTINGGI, jadi percobaan berikutnya pasti berbeda (lihat
         * Order::generateOrderNumber).
         */
        while (true) {
            $attempts++;

            try {
                return Order::create([
                    'order_number' => Order::generateOrderNumber(),
                    'user_id' => $user->getKey(),
                    'service_type_id' => $option->serviceTypeId,
                    'zone_id' => $quote->zoneId,
                    'status' => OrderStatus::Searching->value,
                    'payment_method' => $request->paymentMethod,
                    'payment_status' => $request->isWalletPayment() ? 'held' : 'unpaid',

                    'distance_m' => $quote->distanceMeters,
                    'duration_s' => $quote->durationSeconds,

                    'promo_id' => $promo?->getKey(),
                    'pricing_rule_id' => $option->pricingRuleId,

                    'pickup_address' => $request->pickupAddress,
                    'pickup_lat' => $quote->pickup->lat,
                    'pickup_lng' => $quote->pickup->lng,
                    'pickup_note' => $request->pickupNote,

                    'dest_address' => $request->destinationAddress,
                    'dest_lat' => $quote->destination?->lat,
                    'dest_lng' => $quote->destination?->lng,

                    'pickup_code' => $this->generatePickupCode(),
                    'route_polyline' => $quote->routePolyline->encode(),

                    'requested_at' => now(),
                    'idempotency_key' => $request->idempotencyKey,
                ] + $columns);
            } catch (QueryException $e) {
                if ($attempts >= 5 || ! $this->isOrderNumberCollision($e)) {
                    if ($this->isOrderNumberCollision($e)) {
                        throw OrderNumberExhaustedException::after($attempts);
                    }

                    throw $e;
                }
            }
        }
    }

    /**
     * Tahan dana penumpang untuk order ini.
     *
     * Ditahan, bukan dibayarkan. Order bisa dibatalkan sebelum driver datang,
     * dan mengembalikan uang yang sudah berpindah ke driver jauh lebih rumit
     * daripada melepas dana yang belum berpindah.
     */
    private function holdPayment(User $user, Order $order): void
    {
        $wallet = Wallet::forOwner('user', (int) $user->getKey());

        $this->holdFunds->handle(
            wallet: $wallet,
            amount: $order->totalFare(),
            referenceType: 'order',
            referenceId: (int) $order->getKey(),
            description: "Dana ditahan untuk order {$order->order_number}",
        );
    }

    /**
     * Baris pertama di riwayat status.
     *
     * Order lahir langsung sebagai `searching`, bukan `created` lalu berpindah.
     * Tapi riwayatnya tetap harus punya baris pembuka, karena kalau tidak,
     * halaman riwayat order di panel admin dimulai dari "diterima driver" dan
     * tidak ada catatan siapa yang membuatnya.
     */
    private function logInitialStatus(Order $order, User $user): void
    {
        OrderStatusLog::create([
            'order_id' => $order->getKey(),
            'from_status' => null,
            'to_status' => OrderStatus::Searching->value,
            'actor_type' => 'user',
            'actor_id' => $user->getKey(),
            'lat' => $order->pickup_lat,
            'lng' => $order->pickup_lng,
            'note' => 'Order dibuat.',
        ]);
    }

    private function recordPromoUsage(
        Promo $promo,
        User $user,
        Order $order,
    ): void {
        $discount = (int) $order->discount_amount;

        /*
         * Beban promo dibagi sesuai `cost_bearer`.
         *
         * Ini yang membuat laporan biaya pemasaran bisa dipisah antara yang
         * ditanggung platform dan yang ditanggung merchant. Tanpa pemisahan ini,
         * seluruh diskon terlihat sebagai biaya platform, dan negosiasi promo
         * bersama merchant tidak punya dasar angka.
         */
        $merchantShare = $promo->cost_bearer === 'merchant'
            ? $discount
            : ($promo->cost_bearer === 'shared'
                ? (int) Money::of($discount)
                    ->percentage((string) ($promo->merchant_share_percent ?? 0))
                    ->amount
                : 0);

        DB::table('promo_usages')->insert([
            'promo_id' => $promo->getKey(),
            'user_id' => $user->getKey(),
            'order_id' => $order->getKey(),
            'discount_amount' => $discount,
            'platform_cost' => $discount - $merchantShare,
            'merchant_cost' => $merchantShare,
            'created_at' => now(),
        ]);
    }

    /**
     * Hal-hal yang harus terjadi SETELAH order pasti tersimpan.
     */
    private function afterCommit(Order $order, NewOrderRequest $request): void
    {
        /*
         * Quote dihapus supaya tidak bisa dipakai dua kali.
         *
         * Dilakukan setelah commit, bukan di dalam transaksi. Kalau dihapus di
         * dalam dan transaksinya lalu gagal, quote-nya sudah hilang dari Redis
         * sementara ordernya tidak pernah ada — penumpang harus meminta harga
         * baru untuk kegagalan yang bukan kesalahannya.
         *
         * Risikonya terbalik dan lebih ringan: kalau proses mati tepat di sini,
         * quote tertinggal beberapa menit sampai TTL-nya habis. Dia tidak bisa
         * dipakai membuat order kedua, karena pemeriksaan order aktif menolak.
         */
        $this->quotes->forget($request->quoteId);

        MatchDriverJob::dispatch((int) $order->getKey());

        $this->realtime->publish("order:{$order->uuid}", [
            'event' => 'order.created',
            'order_uuid' => (string) $order->uuid,
            'status' => OrderStatus::Searching->value,
            'order_number' => (string) $order->order_number,
        ]);
    }

    // -------------------------------------------------------------------------

    /**
     * Kode empat digit yang disebut penumpang ke driver.
     *
     * random_int, bukan rand: kode ini yang mencegah driver mengaku sudah
     * menjemput padahal belum, dan generator yang bisa diprediksi membuatnya
     * tidak berguna.
     *
     * Empat digit memang hanya 10.000 kemungkinan, dan itu cukup: kodenya hanya
     * berlaku untuk satu order yang sedang berjalan, dan driver tidak punya
     * kesempatan mencoba berulang kali di depan penumpangnya.
     */
    private function generatePickupCode(): string
    {
        return str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    private function isOrderNumberCollision(QueryException $e): bool
    {
        return str_contains($e->getMessage(), 'orders_order_number_unique');
    }
}
