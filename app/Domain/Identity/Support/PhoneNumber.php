<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support;

use App\Domain\Identity\Exceptions\InvalidPhoneNumberException;

/**
 * Normalisasi nomor HP Indonesia ke satu bentuk kanonik: 62xxxxxxxxxx.
 *
 * ============================================================================
 *  KENAPA INI HARUS ADA DI SATU TEMPAT
 * ============================================================================
 *  Satu nomor HP bisa ditulis dalam banyak bentuk, dan pengguna memakai
 *  semuanya:
 *
 *      081234567890      paling umum
 *      +6281234567890    dari kontak
 *      6281234567890     dari sistem lain
 *      0812-3456-7890    dari catatan
 *      0812 3456 7890    dari salin-tempel
 *      (0812) 34567890   dari formulir lama
 *
 *  Kalau normalisasinya tersebar — sedikit di controller, sedikit di model —
 *  akan ada jalur yang menyimpan bentuk berbeda, dan hasilnya adalah SATU ORANG
 *  dengan DUA AKUN. Saldonya terpisah, riwayat ordernya terpisah, dan tidak ada
 *  cara menyatukannya tanpa migrasi manual.
 *
 *  `phone` punya UNIQUE constraint, jadi bentuk yang tidak konsisten juga
 *  berarti pendaftaran yang gagal dengan pesan "nomor sudah dipakai" untuk
 *  nomor yang pemiliknya adalah orang yang sama.
 * ============================================================================
 */
final class PhoneNumber
{
    /** Bentuk kanonik: kode negara 62 diikuti nomor tanpa nol depan. */
    public static function normalize(string $raw): string
    {
        // Buang semua yang bukan angka, kecuali plus di awal yang akan diproses.
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if ($digits === '') {
            throw InvalidPhoneNumberException::make($raw);
        }

        /*
         * Urutan pemeriksaannya penting.
         *
         * "62" harus diperiksa SEBELUM "0", karena nomor yang sudah berbentuk
         * 62812... tidak boleh diperlakukan sebagai nomor lokal. Dan "0"
         * diperiksa sebelum kasus umum, karena 0812... adalah bentuk yang paling
         * sering masuk.
         */
        if (str_starts_with($digits, '62')) {
            $normalized = $digits;
        } elseif (str_starts_with($digits, '0')) {
            $normalized = '62'.substr($digits, 1);
        } else {
            // Sudah tanpa awalan, misal 812345678 dari formulir yang memisahkan
            // kode negara.
            $normalized = '62'.$digits;
        }

        self::assertPlausible($normalized, $raw);

        return $normalized;
    }

    /**
     * Bentuk yang enak dibaca manusia: 0812-3456-7890.
     *
     * Dipakai panel admin dan struk. Nomor kanonik 62812... benar untuk sistem
     * tapi tidak dikenali staf CS yang membacakannya lewat telepon.
     */
    public static function forDisplay(string $normalized): string
    {
        $local = '0'.substr($normalized, 2);

        // Kelompok 4-4-sisa, pola yang dipakai di Indonesia.
        return trim(preg_replace('/(\d{4})(\d{4})(\d+)/', '$1-$2-$3', $local) ?? $local);
    }

    /**
     * Nomor tersamarkan untuk ditampilkan ke pihak lain: 0812-****-7890.
     *
     * Dipakai saat driver melihat nomor penumpang sebelum order dimulai, dan
     * saat CS melihat daftar tiket. Nomor penuh dibuka hanya saat memang
     * dibutuhkan, dan pembukaannya dicatat.
     */
    public static function masked(string $normalized): string
    {
        $local = '0'.substr($normalized, 2);
        $length = strlen($local);

        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return substr($local, 0, 4).'-****-'.substr($local, -4);
    }

    // -------------------------------------------------------------------------

    /**
     * Panjang yang wajar untuk nomor seluler Indonesia.
     *
     * Nomor seluler Indonesia: 62 + 8 + 9 sampai 11 digit, jadi total 12 sampai
     * 14 digit. Batas ini bukan validasi operator — tidak ada gunanya menolak
     * nomor dari operator baru — hanya penyaring bentuk yang jelas salah,
     * seperti nomor telepon rumah atau angka yang terpotong.
     */
    private static function assertPlausible(string $normalized, string $raw): void
    {
        $length = strlen($normalized);

        if ($length < 11 || $length > 15) {
            throw InvalidPhoneNumberException::make($raw);
        }

        // Nomor seluler Indonesia selalu dimulai 8 setelah kode negara.
        if (! str_starts_with(substr($normalized, 2), '8')) {
            throw InvalidPhoneNumberException::notMobile($raw);
        }
    }
}
