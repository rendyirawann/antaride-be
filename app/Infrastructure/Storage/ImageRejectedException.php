<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Domain\Shared\Exceptions\DomainException;

/**
 * Gambar yang diunggah ditolak lapisan penyimpanan.
 *
 * ============================================================================
 *  LAPIS KEDUA, BUKAN PENGGANTI VALIDASI REQUEST
 * ============================================================================
 *  FormRequest sudah memeriksa aturan `image`, `mimetypes`, dan `max`. Yang di
 *  sini bukan pengulangannya untuk kerapian — ini penjaga untuk pemanggil yang
 *  TIDAK melewati FormRequest: job, artisan command, seeder, dan kode yang
 *  ditulis nanti.
 *
 *  Lapisan penyimpanan yang mengandalkan pemanggilnya sudah memvalidasi akan
 *  benar tepat sampai ada satu pemanggil baru yang lupa. Dan yang lolos di situ
 *  bukan sekadar data buruk — dia berkas di disk, dengan nama dan ekstensi yang
 *  ditentukan orang lain.
 * ============================================================================
 *
 * ============================================================================
 *  PESANNYA TIDAK MENYEBUT DAFTAR TIPE YANG DITERIMA
 * ============================================================================
 *  Untuk unggahan dokumen identitas, membocorkan daftar tipe yang lolos membantu
 *  orang yang sedang mencoba menemukan yang tidak diperiksa.
 *
 *  Yang disebut ke pengguna: format yang dia harus pakai (JPG atau PNG). Yang
 *  tidak disebut: bahwa webp juga lolos, dan bahwa svg tidak. Tipe sebenarnya
 *  masuk log, bukan response.
 * ============================================================================
 */
class ImageRejectedException extends DomainException
{
    private function __construct(
        string $message,
        private readonly string $kodeGalat,
    ) {
        parent::__construct($message);
    }

    /**
     * Tipe MIME-nya TIDAK diterima sebagai parameter, dan itu disengaja.
     *
     * Nilainya tidak boleh masuk pesan maupun `details()` — lihat docblock
     * kelas. Yang butuh mengetahuinya adalah log, dan yang punya nilainya adalah
     * `ImageStore`. Jadi pencatatannya di sana, tepat sebelum melempar.
     *
     * Parameter yang diterima lalu diabaikan akan membuat pemanggil berikutnya
     * mengira nilainya sampai ke suatu tempat.
     */
    public static function tipeTidakDidukung(): self
    {
        return new self(
            'Berkas ini bukan foto yang bisa dibaca. Kirim foto JPG atau PNG.',
            'IMAGE_TYPE_UNSUPPORTED',
        );
    }

    /**
     * Unggahan yang rusak di tengah jalan.
     *
     * Paling sering: batas `upload_max_filesize` PHP terlampaui, atau koneksi
     * terputus. Keduanya menghasilkan `UploadedFile` yang ada tapi `isValid()`
     * bernilai false — bukan exception.
     */
    public static function unggahanTidakSah(): self
    {
        return new self(
            'Unggahan tidak selesai. Periksa koneksi lalu coba lagi.',
            'IMAGE_UPLOAD_INCOMPLETE',
        );
    }

    public static function gagalDisimpan(): self
    {
        return new self(
            'Foto tidak bisa disimpan. Coba lagi sebentar.',
            'IMAGE_STORE_FAILED',
        );
    }

    public function errorCode(): string
    {
        return $this->kodeGalat;
    }

    public function httpStatus(): int
    {
        /*
         * 422 untuk tipe yang ditolak — bentuk datanya memang salah, dan
         * aplikasi menampilkannya di dekat tombol unggah.
         *
         * 503 untuk kegagalan penyimpanan: yang salah bukan berkasnya, dan
         * pengguna harus mencoba lagi alih-alih mencari foto lain. 422 di situ
         * akan membuat driver mengganti-ganti fotonya untuk masalah yang ada di
         * disk kita.
         */
        return $this->kodeGalat === 'IMAGE_STORE_FAILED' ? 503 : 422;
    }
}
