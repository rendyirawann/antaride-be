<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/**
 * Penilaian ditolak.
 *
 * ============================================================================
 *  TIGA SEBAB, DAN KETIGANYA PERLU PESAN YANG BERBEDA
 * ============================================================================
 *  Satu kelas dengan tiga factory, bukan tiga kelas: ketiganya berakhir sebagai
 *  penolakan penilaian di layar yang sama, dan kode HTTP-nya pun sama. Yang
 *  berbeda hanya kalimatnya — dan kalimat itu yang menentukan apakah penumpang
 *  tahu harus berbuat apa.
 *
 *    belum selesai   dia harus menunggu perjalanannya selesai
 *    sudah dinilai   tidak ada yang perlu dilakukan; layar menutup form
 *    bukan miliknya  order orang lain — tidak boleh terjadi lewat aplikasi,
 *                    dan kalau terjadi berarti ada yang mengirim uuid
 *                    sembarang
 *
 *  Pesan generik "tidak bisa menilai" akan membuat ketiganya terlihat sama, dan
 *  penumpang yang sudah menilai akan mencoba lagi berulang.
 * ============================================================================
 */
class RatingNotAllowedException extends DomainException
{
    /*
     * Nama propertinya BUKAN `$code`.
     *
     * `Exception` bawaan PHP sudah punya `$code` sebagai properti non-readonly,
     * dan mendeklarasikannya ulang sebagai readonly adalah fatal error saat
     * kelasnya dimuat — bukan galat runtime yang muncul saat dipakai. Jadi
     * seluruh test suite berhenti, dengan pesan yang tidak menyebut kelas mana
     * yang memicunya.
     */
    private function __construct(
        string $message,
        private readonly string $kodeGalat,
    ) {
        parent::__construct($message);
    }

    public static function orderNotCompleted(): self
    {
        return new self(
            'Penilaian bisa diberikan setelah perjalanan selesai.',
            'RATING_ORDER_NOT_COMPLETED',
        );
    }

    public static function alreadyRated(): self
    {
        return new self(
            'Anda sudah menilai perjalanan ini.',
            'RATING_ALREADY_SUBMITTED',
        );
    }

    public static function notYourOrder(): self
    {
        return new self(
            'Pesanan ini bukan milik Anda.',
            'RATING_NOT_YOUR_ORDER',
        );
    }

    public function errorCode(): string
    {
        return $this->kodeGalat;
    }

    public function httpStatus(): int
    {
        /*
         * 409, bukan 422.
         *
         * Yang salah bukan BENTUK datanya — skornya sah, komentarnya sah. Yang
         * salah adalah KEADAAN order-nya saat penilaian tiba.
         *
         * Bedanya terasa di aplikasi: 422 diperlakukan sebagai galat validasi
         * dan ditampilkan di kolom yang bersangkutan, sementara ini harus
         * ditampilkan sebagai pesan tentang order-nya — dan pada kasus "sudah
         * dinilai", form-nya ditutup, bukan diperbaiki.
         */
        return 409;
    }
}
