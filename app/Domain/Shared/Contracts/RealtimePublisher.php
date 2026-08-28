<?php

declare(strict_types=1);

namespace App\Domain\Shared\Contracts;

/**
 * Penerbit peristiwa realtime ke aplikasi mobile dan panel admin.
 *
 * Laravel TIDAK memegang koneksi WebSocket. PHP-FPM maupun Octane tidak cocok
 * memegang koneksi persisten untuk ribuan driver online: satu koneksi menahan
 * satu worker, dan dengan empat worker berarti empat driver.
 *
 * Yang dilakukan Laravel hanya dua hal: menerbitkan token channel setelah
 * memeriksa otorisasi, dan mengirim pesan lewat HTTP API gateway realtime.
 * Fan-out ke ribuan klien adalah tugas gateway.
 */
interface RealtimePublisher
{
    /**
     * Kirim satu peristiwa ke sebuah channel.
     *
     * TIDAK melempar exception kalau gateway mati. Alasannya: kegagalan
     * mengirim pembaruan posisi tidak boleh menggagalkan transaksi order yang
     * sudah ter-commit. Yang hilang adalah kenyamanan (penumpang perlu menarik
     * layar untuk menyegarkan), bukan kebenaran data.
     *
     * @param  array<string, mixed>  $payload
     * @return bool berhasil terkirim atau tidak
     */
    public function publish(string $channel, array $payload): bool;

    /**
     * Kirim satu peristiwa ke beberapa channel sekaligus.
     *
     * Satu panggilan HTTP, bukan satu per channel. Perubahan status order perlu
     * dikirim ke channel order, channel driver, dan channel admin secara
     * bersamaan.
     *
     * @param  array<int, string>  $channels
     * @param  array<string, mixed>  $payload
     */
    public function broadcast(array $channels, array $payload): bool;

    /**
     * Token koneksi untuk sebuah pengguna.
     *
     * Diterbitkan setelah autentikasi. Menandai SIAPA yang terhubung, bukan
     * channel apa yang boleh dia baca.
     */
    public function connectionToken(string $subject, ?int $ttlSeconds = null): string;

    /**
     * Token langganan untuk satu channel tertentu.
     *
     * Ini yang menegakkan otorisasi channel. Pengguna hanya boleh berlangganan
     * channel order miliknya sendiri, dan token inilah buktinya.
     *
     * Tanpa token per channel, siapa pun yang tahu format nama channel bisa
     * mendengarkan posisi driver dan isi percakapan order orang lain.
     */
    public function subscriptionToken(
        string $subject,
        string $channel,
        ?int $ttlSeconds = null,
    ): string;
}
