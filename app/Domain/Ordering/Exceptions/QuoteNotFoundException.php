<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/**
 * Quote tidak ada, sudah kadaluarsa, atau bukan milik pengguna ini.
 *
 * Ketiganya memberi pesan YANG SAMA, dan itu disengaja. Membedakan "tidak ada"
 * dari "bukan milik Anda" memberi tahu penyerang bahwa quote_id yang dia tebak
 * benar-benar ada — dan itu satu-satunya informasi yang dia butuhkan untuk tahu
 * bahwa menebak lebih lanjut ada gunanya.
 *
 * 422, bukan 404: tindak lanjutnya bukan "halaman tidak ada" tapi "minta harga
 * baru lalu coba lagi", dan aplikasi mobile membedakan keduanya.
 */
class QuoteNotFoundException extends DomainException
{
    public static function make(): self
    {
        return new self('Estimasi harga sudah kadaluarsa. Muat ulang untuk mendapat harga terbaru.');
    }

    public static function serviceNotInQuote(string $serviceCode): self
    {
        return new self(
            "Layanan {$serviceCode} tidak tersedia untuk estimasi ini.",
            details: ['service_code' => $serviceCode],
        );
    }

    public function errorCode(): string
    {
        return 'QUOTE_EXPIRED';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
