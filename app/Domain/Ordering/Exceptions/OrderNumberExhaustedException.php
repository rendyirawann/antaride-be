<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/**
 * Nomor order tetap bertabrakan setelah beberapa percobaan.
 *
 * Seharusnya tidak pernah terjadi: nomornya diturunkan dari nomor tertinggi
 * hari itu, jadi setiap pengulangan menghasilkan angka yang lebih besar.
 * Sampai di sini berarti ada yang salah secara mendasar — misalnya unique index
 * pada order_number sudah tidak ada, atau ada proses lain yang menulis nomor
 * dengan format berbeda sehingga split_part membaca bagian yang salah.
 *
 * 500, bukan 409. Ini bukan konflik yang client bisa selesaikan dengan mencoba
 * lagi; ini kondisi yang butuh diperiksa manusia.
 *
 * Pesan ke pengguna sengaja tidak menyebut index database: dia tidak bisa
 * berbuat apa pun soal itu, dan yang dia butuhkan hanya tahu ini bukan
 * kesalahannya. Jumlah percobaannya masuk `details` untuk log dan panel admin.
 */
class OrderNumberExhaustedException extends DomainException
{
    public static function after(int $attempts): self
    {
        return new self(
            'Gagal membuat order karena masalah teknis. Coba lagi sesaat.',
            details: ['attempts' => $attempts],
        );
    }

    public function errorCode(): string
    {
        return 'ORDER_NUMBER_EXHAUSTED';
    }

    public function httpStatus(): int
    {
        return 500;
    }
}
