<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/**
 * Penawaran sudah kadaluarsa saat driver menekan terima.
 *
 * Dibedakan dari OrderAlreadyTakenException karena tindak lanjutnya berbeda di
 * aplikasi driver: order ini mungkin masih mencari driver, hanya penawaran
 * miliknya yang sudah habis. Menampilkannya sebagai "sudah diambil orang lain"
 * akan membuat driver berhenti mencoba padahal gelombang berikutnya bisa
 * menawarkannya lagi.
 *
 * Kadaluarsa selalu diukur dengan waktu server. Jam HP bisa diubah, dan
 * penawaran yang "masih berlaku menurut HP saya" adalah cara paling mudah untuk
 * mengambil order yang sudah ditawarkan ke orang lain.
 *
 * 410 Gone, bukan 409: sumber dayanya (penawaran itu) memang sudah tidak ada,
 * dan tidak akan kembali.
 */
class OfferExpiredException extends DomainException
{
    public static function make(): self
    {
        return new self('Waktu untuk menerima order ini sudah habis.');
    }

    public function errorCode(): string
    {
        return 'OFFER_EXPIRED';
    }

    public function httpStatus(): int
    {
        return 410;
    }
}
