<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Contracts;

/**
 * Lock singkat untuk mencegah dua driver menerima order yang sama.
 *
 * Ini LAPIS PERTAMA, bukan satu-satunya. Blueprint bagian 4.3 menegaskan yang
 * membuat penerimaan order aman adalah kombinasi tiga hal:
 *
 *   1. Lock Redis (di sini) — cepat, menahan mayoritas request bersaing
 *      sebelum menyentuh database.
 *   2. `SELECT ... FOR UPDATE` pada baris order di dalam transaksi.
 *   3. Partial unique index `orders_one_active_per_driver` di PostgreSQL.
 *
 * Yang ketiga adalah jaring terakhir dan yang paling bisa dipercaya, karena dia
 * tetap berlaku walaupun Redis baru restart dan seluruh lock-nya hilang.
 *
 * Lock ini sengaja BUKAN pengganti nomor 2 dan 3. Lock terdistribusi berbasis
 * Redis tunggal tidak memberi jaminan mutual exclusion yang benar saat terjadi
 * failover atau jeda GC, dan mempercayainya sebagai penjaga tunggal untuk
 * sesuatu yang menyangkut uang adalah kesalahan yang mahal.
 */
interface OrderLock
{
    /**
     * Coba kuasai order untuk seorang driver.
     *
     * Mengembalikan true kalau berhasil, false kalau sudah dikuasai orang lain.
     * Implementasinya memakai SET NX, jadi operasinya atomik di sisi Redis.
     */
    public function acquire(int $orderId, int $driverId, ?int $ttlSeconds = null): bool;

    /**
     * Lepaskan lock, tapi HANYA kalau pemiliknya driver ini.
     *
     * Pemeriksaan pemilik bukan kehati-hatian berlebihan. Tanpa itu, driver A
     * yang lock-nya sudah kadaluarsa dan diambil driver B akan melepaskan lock
     * milik B saat proses A akhirnya selesai, dan order yang sudah sah diterima
     * B menjadi terbuka lagi.
     */
    public function release(int $orderId, int $driverId): bool;

    /**
     * Siapa yang sedang menguasai order ini, kalau ada.
     */
    public function heldBy(int $orderId): ?int;

    /**
     * Lepaskan tanpa memeriksa pemilik.
     *
     * Hanya untuk intervensi admin dan pembersihan test. Jangan dipakai di
     * jalur normal.
     */
    public function forceRelease(int $orderId): void;
}
