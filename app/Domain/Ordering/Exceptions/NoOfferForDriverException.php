<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/**
 * Driver mencoba menerima order yang tidak ditawarkan kepadanya.
 *
 * ============================================================================
 *  INI PEMERIKSAAN OTORISASI, BUKAN VALIDASI
 * ============================================================================
 *  Tanpa dia, siapa pun yang punya token driver yang sah bisa menerima order
 *  APA PUN yang statusnya masih mencari — cukup dengan menebak UUID order atau
 *  membacanya dari channel realtime yang dia ikuti.
 *
 *  Akibatnya bukan sekadar celah teoretis. Driver yang tahu caranya akan
 *  mengambil order-order terbaik tanpa pernah menunggu ditawari, dan seluruh
 *  sistem skoring — termasuk bobot keadilan yang menaikkan driver baru — menjadi
 *  tidak berarti. Yang terlihat di data: sekelompok kecil driver menguasai
 *  hampir semua order bernilai tinggi, dan tidak ada penjelasan di log
 *  matching karena mereka memang tidak pernah muncul sebagai kandidat.
 *
 *  403, bukan 404. Ordernya ada; yang tidak ada adalah haknya.
 * ============================================================================
 */
class NoOfferForDriverException extends DomainException
{
    public static function make(): self
    {
        return new self('Order ini tidak ditawarkan kepada Anda.');
    }

    public static function alreadyRejected(): self
    {
        return new self('Anda sudah menolak order ini.');
    }

    public function errorCode(): string
    {
        return 'NO_OFFER_FOR_DRIVER';
    }

    public function httpStatus(): int
    {
        return 403;
    }
}
