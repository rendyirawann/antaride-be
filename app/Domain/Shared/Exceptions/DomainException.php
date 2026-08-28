<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exceptions;

use RuntimeException;

/**
 * Induk semua kegagalan aturan bisnis.
 *
 * Bedanya dengan exception biasa: setiap turunan wajib membawa kode
 * mesin-readable dan status HTTP yang tepat. Itu yang membuat ApiExceptionRenderer
 * bisa mengubah kegagalan domain jadi response API yang konsisten tanpa perlu
 * daftar match raksasa yang harus diperbarui setiap ada exception baru.
 *
 * Yang penting dipegang: Domain tidak tahu apa pun soal HTTP. Yang disimpan di
 * sini hanya angka status; keputusan cara mengirimkannya tetap milik lapisan
 * HTTP.
 */
abstract class DomainException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        string $message,
        private readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /**
     * Kode yang dibaca aplikasi mobile untuk memutuskan apa yang ditampilkan.
     * Jangan pernah diubah setelah dirilis; app versi lama masih memakainya.
     */
    abstract public function errorCode(): string;

    public function httpStatus(): int
    {
        return 422;
    }

    /**
     * Data tambahan yang berguna untuk app, misal saldo saat ini pada kasus
     * saldo tidak cukup, supaya app tidak perlu memanggil endpoint lain hanya
     * untuk menampilkan pesan yang berarti.
     *
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }
}
