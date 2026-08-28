<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Matching\Actions\DispatchOfferWave;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\StateMachine\OrderStateMachine;
use App\Domain\Ordering\StateMachine\OrderTransition;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Menjalankan pencarian driver untuk satu order, satu gelombang per eksekusi.
 *
 * ============================================================================
 *  KENAPA SATU GELOMBANG PER JOB, BUKAN SATU JOB UNTUK SEMUA GELOMBANG
 * ============================================================================
 *  Alternatifnya adalah satu job yang menunggu 15 detik di antara gelombang
 *  dengan sleep(). Itu menahan satu worker queue selama 60 detik untuk satu
 *  order — dengan 20 order bersamaan, seluruh worker habis dan tidak ada job
 *  lain di sistem yang bisa jalan, termasuk settlement dan notifikasi.
 *
 *  Dengan menjadwalkan diri sendiri, worker dilepas di antara gelombang, dan
 *  yang menahan waktu adalah queue delay, bukan proses PHP.
 *
 *  Efek sampingnya yang juga penting: kalau order diterima driver di
 *  gelombang 2, gelombang 3 tidak pernah berjalan sama sekali — bukan berjalan
 *  lalu menemukan ordernya sudah diambil.
 * ============================================================================
 *
 * ============================================================================
 *  ShouldBeUnique DAN PEMERIKSAAN STATUS, KEDUANYA
 * ============================================================================
 *  ShouldBeUnique mencegah dua job untuk order yang sama berjalan bersamaan —
 *  yang bisa terjadi kalau job pertama timeout dan queue mengulanginya
 *  sementara yang lama masih hidup. Tanpa itu, satu order bisa mengirim dua
 *  gelombang sekaligus dan menawari sepuluh driver.
 *
 *  Tapi kunci unik punya masa berlaku, dan job yang mati bisa meninggalkan
 *  kuncinya. Karena itu status order tetap diperiksa di awal setiap gelombang.
 *  Dua lapis untuk hal yang sama, karena yang dicegah adalah menawarkan order
 *  yang sudah punya driver — kesalahan yang langsung terlihat driver dan
 *  langsung merusak kepercayaan mereka.
 * ============================================================================
 */
class MatchDriverJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Job matching harus cepat gagal, bukan menggantung.
     *
     * Order yang menunggu driver adalah penumpang yang menatap layar. Kalau
     * satu gelombang butuh lebih dari 30 detik, ada yang salah dengan Redis
     * atau database, dan mencoba lebih lama tidak akan memperbaikinya.
     */
    public int $timeout = 30;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [2, 5];

    public function __construct(
        public readonly int $orderId,
        public readonly int $wave = 1,
    ) {
        $this->onQueue('matching');
    }

    /**
     * Kunci unik per ORDER, bukan per order+gelombang.
     *
     * Kalau gelombangnya ikut masuk kunci, dua gelombang berbeda untuk order
     * yang sama dianggap job berbeda dan boleh jalan bersamaan — yang persis
     * merupakan hal yang mau dicegah.
     */
    public function uniqueId(): string
    {
        return "match-driver:{$this->orderId}";
    }

    /**
     * Kunci dilepas setelah 90 detik.
     *
     * Lebih panjang dari total durasi seluruh gelombang (4 x 15 detik ditambah
     * kelonggaran), supaya kunci tidak habis di tengah pencarian; dan cukup
     * pendek supaya order yang jobnya mati tidak terkunci lama.
     */
    public function uniqueFor(): int
    {
        return 90;
    }

    public function handle(
        DispatchOfferWave $dispatchWave,
        OrderStateMachine $stateMachine,
    ): void {
        $order = Order::with('serviceType')->find($this->orderId);

        if ($order === null) {
            // Order dihapus di tengah pencarian. Tidak ada yang perlu
            // dikerjakan, dan ini bukan kegagalan.
            return;
        }

        if ($order->status !== OrderStatus::Searching) {
            return;
        }

        $result = $dispatchWave->handle($order, $this->wave);

        Log::channel('matching')->info('Gelombang penawaran', [
            'order_id' => $this->orderId,
            'order_number' => $order->order_number,
        ] + $result->toLogContext());

        if (! $result->shouldContinue()) {
            return;
        }

        if ($this->wave >= $dispatchWave->maxWaves()) {
            $this->giveUp($order, $stateMachine, $dispatchWave);

            return;
        }

        /*
         * Jeda sebelum gelombang berikutnya.
         *
         * Kalau ada yang ditawari, jedanya selama masa berlaku penawaran —
         * gelombang berikutnya tidak boleh mulai sebelum yang sekarang habis,
         * karena kalau tidak, driver gelombang 1 dan 2 bersaing untuk order yang
         * sama dan salah satu pasti kalah.
         *
         * Kalau tidak ada satu pun kandidat, tidak ada yang perlu ditunggu.
         * Jeda pendek saja supaya radius melebar cepat: penumpang di daerah
         * yang sedikit drivernya tidak boleh menunggu 60 detik hanya untuk
         * mencapai radius terluas.
         */
        $delay = $result->outcome === 'offered'
            ? $dispatchWave->offerTtlSeconds()
            : 2;

        self::dispatch($this->orderId, $this->wave + 1)->delay(now()->addSeconds($delay));
    }

    /**
     * Tidak ada driver setelah seluruh gelombang habis.
     */
    private function giveUp(
        Order $order,
        OrderStateMachine $stateMachine,
        DispatchOfferWave $dispatchWave,
    ): void {
        /*
         * Penawaran yang masih menggantung TIDAK dibatalkan di sini.
         *
         * Kalau ada driver yang penawarannya masih berlaku beberapa detik lagi,
         * dia masih boleh menerima. Yang penting adalah ordernya tidak lagi
         * dianggap sedang dicarikan — dan itu ditegakkan pemeriksaan status di
         * AcceptOrder, bukan dengan menghapus penawaran.
         *
         * Menghapus penawaran justru akan mengambil order dari driver yang
         * SEDANG menekan tombol terima.
         */
        $stillLive = $order->offers()
            ->whereNull('response')
            ->where('expires_at', '>', now())
            ->exists();

        if ($stillLive) {
            // Beri kesempatan sampai penawaran terakhir habis, lalu putuskan.
            self::dispatch($this->orderId, $this->wave)
                ->delay(now()->addSeconds($dispatchWave->offerTtlSeconds()));

            return;
        }

        $stateMachine->apply(
            $order,
            OrderTransition::bySystem(
                to: OrderStatus::NoDriver,
                note: sprintf(
                    'Tidak ada driver yang menerima setelah %d gelombang, radius sampai %d m.',
                    $dispatchWave->maxWaves(),
                    $dispatchWave->radiusForWave($dispatchWave->maxWaves()),
                ),
            ),
        );

        Log::channel('matching')->warning('Order tidak mendapat driver', [
            'order_id' => $this->orderId,
            'order_number' => $order->order_number,
            'waves' => $dispatchWave->maxWaves(),
        ]);
    }
}
