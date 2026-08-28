<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Contracts;

use App\Domain\Pricing\DTOs\Quote;

/**
 * Penyimpanan quote berumur pendek.
 *
 * Quote TIDAK masuk PostgreSQL. Setiap kali penumpang menggeser pin di peta,
 * aplikasi meminta quote baru; pada 500 order per hari itu bisa berarti puluhan
 * ribu quote, dan yang berumur lebih dari lima menit tidak berguna untuk apa
 * pun. Menyimpannya ke tabel berarti menambah puluhan ribu baris per hari yang
 * seluruhnya sampah dalam lima menit.
 *
 * Yang masuk PostgreSQL hanya snapshot harga di baris `orders`, saat quote
 * benar-benar dipakai membuat order.
 */
interface QuoteStore
{
    /**
     * Simpan quote dengan TTL.
     */
    public function put(Quote $quote): void;

    /**
     * Ambil quote. Null kalau tidak ada atau sudah kadaluarsa.
     */
    public function get(string $quoteId): ?Quote;

    /**
     * Hapus quote.
     *
     * Dipanggil setelah quote dipakai membuat order. Ini yang mencegah satu
     * quote dipakai membuat dua order: yang kedua akan menemukan quote sudah
     * hilang dan ditolak.
     *
     * Idempotency di lapisan HTTP menangkap pengiriman ganda dengan kunci yang
     * sama, tapi TIDAK menangkap dua permintaan berbeda yang memakai quote_id
     * yang sama. Penghapusan inilah yang menutup celah itu.
     */
    public function forget(string $quoteId): void;

    /**
     * Ambil lalu langsung hapus, dalam satu operasi atomik.
     *
     * Dipakai jalur pembuatan order. Atomik karena dua permintaan yang tiba
     * bersamaan dengan quote_id yang sama harus membuat tepat satu di antaranya
     * mendapatkan quote-nya; kalau dipisah jadi get() lalu forget(), keduanya
     * bisa lolos get() sebelum salah satu menghapus.
     */
    public function pull(string $quoteId): ?Quote;
}
