<?php

declare(strict_types=1);

namespace App\Infrastructure\Redis\Locks;

use App\Domain\Ordering\Contracts\OrderLock;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

/**
 * Lock order di Redis.
 *
 * Key `order:lock:{orderId}` berisi `driver:{driverId}`, dengan TTL pendek.
 * Nama key dibagi dengan location service Go, jadi memakai koneksi tanpa
 * prefix seperti indeks posisi.
 *
 * TTL wajib ada. Lock tanpa kadaluarsa berarti satu proses yang mati di tengah
 * penerimaan order akan menahan order itu selamanya, dan tidak ada driver lain
 * yang bisa mengambilnya sampai ada yang menghapus key-nya lewat redis-cli.
 */
class RedisOrderLock implements OrderLock
{
    public function acquire(int $orderId, int $driverId, ?int $ttlSeconds = null): bool
    {
        $ttlSeconds ??= (int) config('antaride.matching.accept_lock_seconds', 20);

        // SET dengan NX dan EX dalam satu perintah. Ini atomik.
        //
        // Memisahkannya jadi SETNX lalu EXPIRE akan membuka jendela di mana
        // lock sudah terpasang tapi belum punya TTL, dan proses yang mati tepat
        // di jendela itu meninggalkan lock abadi.
        $result = $this->connection()->set(
            $this->key($orderId),
            $this->owner($driverId),
            'EX',
            $ttlSeconds,
            'NX',
        );

        // Predis mengembalikan objek Status untuk balasan +OK, phpredis
        // mengembalikan true. Keduanya menjadi false kalau NX menolak.
        return $result !== false && $result !== null;
    }

    public function release(int $orderId, int $driverId): bool
    {
        // Perbandingan pemilik dan penghapusan harus atomik.
        //
        // Kalau dipisah jadi GET lalu DEL, ada jendela di antara keduanya: lock
        // bisa kadaluarsa dan diambil driver lain tepat setelah GET, lalu DEL
        // menghapus lock milik driver baru itu. Order yang sudah sah diterima
        // orang lain menjadi terbuka lagi, dan dua driver menuju titik jemput
        // yang sama.
        //
        // Script Lua dijalankan Redis sebagai satu operasi tak terputus.
        $script = <<<'LUA'
            if redis.call("GET", KEYS[1]) == ARGV[1] then
                return redis.call("DEL", KEYS[1])
            end
            return 0
        LUA;

        $deleted = $this->connection()->eval(
            $script,
            1,
            $this->key($orderId),
            $this->owner($driverId),
        );

        return (int) $deleted === 1;
    }

    public function heldBy(int $orderId): ?int
    {
        $value = $this->connection()->get($this->key($orderId));

        if (! is_string($value) || ! str_starts_with($value, 'driver:')) {
            return null;
        }

        $id = filter_var(substr($value, 7), FILTER_VALIDATE_INT);

        return $id === false ? null : $id;
    }

    public function forceRelease(int $orderId): void
    {
        $this->connection()->del($this->key($orderId));
    }

    // -------------------------------------------------------------------------

    private function connection(): Connection
    {
        return Redis::connection('shared');
    }

    private function key(int $orderId): string
    {
        return "order:lock:{$orderId}";
    }

    private function owner(int $driverId): string
    {
        return "driver:{$driverId}";
    }
}
