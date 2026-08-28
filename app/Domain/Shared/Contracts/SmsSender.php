<?php

declare(strict_types=1);

namespace App\Domain\Shared\Contracts;

/**
 * Pengirim SMS.
 *
 * Dibuat kontrak karena provider SMS adalah bagian yang paling sering berganti
 * dalam sistem seperti ini: harganya berubah, keterkiriman ke satu operator
 * memburuk, atau kontraknya berakhir. Yang tidak boleh ikut berubah adalah
 * seluruh alur autentikasi.
 *
 * `sendOtp` dipisahkan dari `send` biasa karena keduanya punya sifat berbeda:
 * OTP menuntut jalur bernomor pendek dengan prioritas tinggi dan template yang
 * disetujui operator, sementara pemberitahuan biasa boleh lewat jalur murah.
 * Menggabungkannya berarti seluruh SMS memakai jalur mahal, atau OTP memakai
 * jalur yang sampainya lima menit — dan OTP yang sampai setelah kadaluarsa sama
 * sekali tidak ada gunanya.
 */
interface SmsSender
{
    /**
     * Kirim kode OTP.
     *
     * TIDAK melempar exception saat gateway gagal, dan itu keputusan yang
     * disengaja: baris OTP-nya sudah tersimpan di database, jadi kode yang sudah
     * dikirim lewat jalur lain (misal percobaan sebelumnya) tetap bisa dipakai.
     * Yang dikembalikan adalah keberhasilannya, supaya pemanggil bisa mencatat
     * dan tetap membalas ke aplikasi dengan bentuk yang sama.
     */
    public function sendOtp(string $phone, string $code): bool;

    public function send(string $phone, string $message): bool;
}
