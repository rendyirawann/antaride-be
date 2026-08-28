<?php

declare(strict_types=1);

namespace Tests\Feature\Ordering;

use App\Domain\Ordering\Contracts\OrderLock;
use App\Infrastructure\Redis\Locks\RedisOrderLock;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Diuji terhadap Redis sungguhan.
 *
 * Yang diuji di sini adalah perilaku pada kondisi balapan, dan itu tidak bisa
 * dibuktikan dengan mock: mock akan mengonfirmasi bahwa saya memanggil SET NX,
 * bukan bahwa SET NX benar-benar menolak pemanggil kedua.
 */
class RedisOrderLockTest extends TestCase
{
    private RedisOrderLock $lock;

    private const ORDER = 990001;

    private const DRIVER_A = 900001;

    private const DRIVER_B = 900002;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lock = new RedisOrderLock;
        $this->lock->forceRelease(self::ORDER);
    }

    protected function tearDown(): void
    {
        $this->lock->forceRelease(self::ORDER);

        parent::tearDown();
    }

    public function test_driver_pertama_mendapatkan_lock(): void
    {
        $this->assertTrue($this->lock->acquire(self::ORDER, self::DRIVER_A));
        $this->assertSame(self::DRIVER_A, $this->lock->heldBy(self::ORDER));
    }

    /**
     * Inti dari class ini: driver kedua HARUS ditolak.
     *
     * Ini yang terjadi di lapangan saat satu order ditawarkan ke tiga driver
     * sekaligus dan dua di antaranya menekan terima dalam selisih milidetik.
     */
    public function test_driver_kedua_ditolak(): void
    {
        $this->assertTrue($this->lock->acquire(self::ORDER, self::DRIVER_A));
        $this->assertFalse(
            $this->lock->acquire(self::ORDER, self::DRIVER_B),
            'Driver kedua mendapatkan lock yang sama. Dua driver akan menuju titik jemput yang sama.',
        );

        $this->assertSame(self::DRIVER_A, $this->lock->heldBy(self::ORDER));
    }

    /**
     * Sepuluh driver menyerbu satu order. Tepat satu boleh menang.
     */
    public function test_hanya_satu_dari_sepuluh_driver_yang_menang(): void
    {
        $winners = [];

        for ($driverId = 900010; $driverId < 900020; $driverId++) {
            if ($this->lock->acquire(self::ORDER, $driverId)) {
                $winners[] = $driverId;
            }
        }

        $this->assertCount(
            1,
            $winners,
            'Jumlah pemenang bukan satu: '.implode(', ', $winners),
        );

        $this->assertSame($winners[0], $this->lock->heldBy(self::ORDER));
    }

    public function test_lock_bisa_dilepaskan_pemiliknya(): void
    {
        $this->lock->acquire(self::ORDER, self::DRIVER_A);

        $this->assertTrue($this->lock->release(self::ORDER, self::DRIVER_A));
        $this->assertNull($this->lock->heldBy(self::ORDER));

        // Dan setelah dilepas, driver lain boleh mengambilnya.
        $this->assertTrue($this->lock->acquire(self::ORDER, self::DRIVER_B));
    }

    /**
     * Driver yang BUKAN pemilik tidak boleh bisa melepaskan lock.
     *
     * Ini menutup kasus yang halus: proses driver A yang lambat, lock-nya
     * kadaluarsa, driver B mengambilnya, lalu proses A akhirnya selesai dan
     * memanggil release. Tanpa pemeriksaan pemilik, lock milik B ikut terhapus
     * dan order yang sudah sah diterima B jadi terbuka lagi.
     */
    public function test_bukan_pemilik_tidak_bisa_melepaskan_lock(): void
    {
        $this->lock->acquire(self::ORDER, self::DRIVER_A);

        $this->assertFalse(
            $this->lock->release(self::ORDER, self::DRIVER_B),
            'Driver B berhasil melepaskan lock milik driver A.',
        );

        $this->assertSame(
            self::DRIVER_A,
            $this->lock->heldBy(self::ORDER),
            'Lock milik driver A hilang karena dilepaskan orang lain.',
        );
    }

    public function test_melepaskan_lock_yang_tidak_ada_mengembalikan_false(): void
    {
        $this->assertFalse($this->lock->release(self::ORDER, self::DRIVER_A));
    }

    public function test_heldby_null_kalau_tidak_ada_lock(): void
    {
        $this->assertNull($this->lock->heldBy(self::ORDER));
    }

    /**
     * TTL wajib terpasang di perintah yang sama dengan SET.
     *
     * Lock tanpa kadaluarsa berarti satu proses yang mati di tengah penerimaan
     * order menahan order itu selamanya, dan tidak ada driver lain yang bisa
     * mengambilnya sampai ada yang menghapus key-nya lewat redis-cli.
     */
    public function test_lock_punya_ttl_yang_terpasang(): void
    {
        $this->lock->acquire(self::ORDER, self::DRIVER_A, ttlSeconds: 20);

        $ttl = (int) Redis::connection('shared')->ttl('order:lock:'.self::ORDER);

        $this->assertGreaterThan(0, $ttl, 'Lock tidak punya TTL. Order bisa tertahan selamanya.');
        $this->assertLessThanOrEqual(20, $ttl);
    }

    public function test_ttl_default_diambil_dari_config(): void
    {
        config(['antaride.matching.accept_lock_seconds' => 7]);

        $this->lock->acquire(self::ORDER, self::DRIVER_A);

        $ttl = (int) Redis::connection('shared')->ttl('order:lock:'.self::ORDER);

        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(7, $ttl);
    }

    /**
     * Setelah TTL habis, driver lain boleh mengambil.
     */
    public function test_lock_bisa_diambil_setelah_kadaluarsa(): void
    {
        $this->lock->acquire(self::ORDER, self::DRIVER_A, ttlSeconds: 1);

        $this->assertFalse($this->lock->acquire(self::ORDER, self::DRIVER_B));

        // Dipercepat dengan menghapus key, bukan menunggu satu detik. Yang
        // diuji adalah perilaku setelah lock hilang, bukan ketepatan TTL Redis.
        Redis::connection('shared')->del('order:lock:'.self::ORDER);

        $this->assertTrue($this->lock->acquire(self::ORDER, self::DRIVER_B));
        $this->assertSame(self::DRIVER_B, $this->lock->heldBy(self::ORDER));
    }

    /**
     * Key harus mentah, tanpa prefix, karena dibagi dengan service Go.
     */
    public function test_key_ditulis_tanpa_prefix(): void
    {
        $this->lock->acquire(self::ORDER, self::DRIVER_A);

        $this->assertSame(
            1,
            (int) Redis::connection('shared')->exists('order:lock:'.self::ORDER),
        );
    }

    public function test_force_release_mengabaikan_pemilik(): void
    {
        $this->lock->acquire(self::ORDER, self::DRIVER_A);
        $this->lock->forceRelease(self::ORDER);

        $this->assertNull($this->lock->heldBy(self::ORDER));
    }

    public function test_container_menyerahkan_implementasi_redis(): void
    {
        $this->assertInstanceOf(RedisOrderLock::class, $this->app->make(OrderLock::class));
    }
}
