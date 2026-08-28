<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Driver;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Unggahan satu dokumen KYC driver.
 *
 * ============================================================================
 *  ATURAN `image` MEMBACA ISI BERKAS, BUKAN NAMANYA
 * ============================================================================
 *  Itu yang membuatnya berguna di sini. `image` di Laravel memanggil
 *  `getimagesize()`, yang gagal pada berkas apa pun yang bukan gambar sungguhan
 *  — termasuk skrip PHP yang dinamai `.jpg`.
 *
 *  `mimetypes` di bawahnya juga membaca isi berkas lewat ekstensi fileinfo, BUKAN
 *  header `Content-Type` yang dikirim client. Header itu ditulis client dan bisa
 *  berisi apa pun.
 *
 *  Yang TIDAK dipakai: aturan `mimes:jpg,png`. Bentuk itu memeriksa EKSTENSI
 *  nama berkas, dan nama berkas datang dari client.
 * ============================================================================
 *
 * ============================================================================
 *  BATAS UKURAN 8 MB, DAN ANGKANYA BUKAN SELERA
 * ============================================================================
 *  Aplikasi sudah mengecilkan fotonya sebelum mengirim — sisi terpanjang 1600
 *  piksel, sekitar 200–400 KB. Jadi 8 MB bukan untuk unggahan normal.
 *
 *  Yang dilayaninya: driver yang mengunggah dari galeri hasil scan, dan
 *  perangkat yang jalur pengecilannya gagal. Keduanya sah, dan menolaknya berarti
 *  driver tidak bisa mendaftar tanpa tahu sebabnya.
 *
 *  Batas ini juga TIDAK menggantikan `upload_max_filesize` di PHP. Berkas yang
 *  melampaui batas PHP tidak pernah sampai ke validator — PHP membuangnya lebih
 *  dulu, dan yang tersisa adalah `UploadedFile` dengan `isValid()` false. Itu
 *  ditangani `ImageStore`.
 * ============================================================================
 */
class UploadDocumentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * Jenis dokumennya DIBATASI daftar dari database, bukan daftar di
             * kode ini.
             *
             * `driver_documents_type_check` di Postgres adalah sumber
             * kebenarannya. Daftar kedua di sini akan menyimpang darinya, dan
             * yang menyimpang menghasilkan salah satu dari dua hal: jenis yang
             * lolos validasi lalu ditolak database sebagai galat 500, atau jenis
             * yang sah tapi ditolak validator tanpa alasan yang bisa dijelaskan.
             */
            'type' => [
                'required',
                'string',
                Rule::in(self::JENIS_DOKUMEN),
            ],

            'file' => [
                'required',
                'file',

                // Membaca isi berkas — lihat docblock kelas.
                'image',
                'mimetypes:image/jpeg,image/png,image/webp',

                'max:8192',

                /*
                 * Dimensi MINIMUM, bukan maksimum.
                 *
                 * Yang dijaga: foto 100x75 piksel yang lolos sebagai "gambar"
                 * tapi tulisan di KTP-nya tidak bisa dibaca sama sekali.
                 * Verifikator akan menolaknya, driver mengunggah ulang, dan
                 * putaran itu berulang beberapa hari — untuk sesuatu yang bisa
                 * diberitahukan sejak unggahan pertama.
                 *
                 * 600 piksel dipilih karena di bawah itu nomor NIK mulai tidak
                 * terbaca pada foto KTP yang diambil dari jarak wajar.
                 */
                'dimensions:min_width=600,min_height=400',
            ],

            /*
             * Tanggal kadaluarsa diisi DRIVER, tapi tetap diperiksa verifikator.
             *
             * Opsional di sini, karena tidak semua jenis punya masa berlaku —
             * KTP dan selfie tidak. Yang punya (`sim`, `stnk`, `skck`) diisi
             * driver sebagai kemudahan, dan verifikator yang memastikannya cocok
             * dengan yang tertulis di dokumennya.
             *
             * `after:today` supaya dokumen yang SUDAH kadaluarsa tidak masuk
             * antrean verifikasi. Menolaknya di sini menghemat satu putaran
             * penuh: driver langsung tahu dia perlu memperpanjang dulu, bukan
             * menunggu dua hari untuk diberi tahu hal yang sama.
             */
            'expires_at' => ['nullable', 'date', 'after:today'],

            /*
             * Nomor dokumen. Dienkripsi di database (`number` => `encrypted`).
             *
             * Panjangnya tidak dibatasi ke 16 seperti NIK: kolom ini juga memuat
             * nomor SIM dan nomor STNK, yang formatnya berbeda dan bisa berubah.
             */
            'number' => ['nullable', 'string', 'max:40'],
        ];
    }

    /**
     * Jenis yang diterima.
     *
     * Harus sama dengan `driver_documents_type_check` di database. Ada test yang
     * membandingkan keduanya — daftar yang menyimpang tidak akan lolos suite.
     */
    public const JENIS_DOKUMEN = [
        'ktp',
        'sim',
        'stnk',
        'skck',
        'selfie',
        'bank_book',
        'vaccine',
    ];

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.image' => 'Berkas ini bukan foto. Kirim foto JPG atau PNG.',
            'file.mimetypes' => 'Format foto tidak didukung. Kirim JPG atau PNG.',
            'file.max' => 'Foto terlalu besar. Maksimal 8 MB.',

            // Menyebut AKIBATNYA, bukan hanya angkanya. "min_width 600" tidak
            // memberi tahu driver apa yang harus dia lakukan.
            'file.dimensions' => 'Fotonya terlalu kecil sehingga tulisannya '
                .'tidak akan terbaca. Foto ulang lebih dekat, atau kirim foto '
                .'dengan resolusi lebih tinggi.',

            'expires_at.after' => 'Tanggal berlaku dokumen sudah lewat. '
                .'Perpanjang dokumennya dulu sebelum mengunggah.',

            'type.in' => 'Jenis dokumen tidak dikenali.',
        ];
    }
}
