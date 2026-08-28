<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Menyimpan gambar yang diunggah ke disk, dan membuang yang lama.
 *
 * ============================================================================
 *  SATU-SATUNYA TEMPAT YANG MENENTUKAN NAMA BERKAS
 * ============================================================================
 *  Nama berkas yang datang dari client TIDAK PERNAH dipakai. Yang dipakai nama
 *  yang dibuat di sini, dan alasannya bukan kerapian:
 *
 *    Path traversal   `../../../.env` sebagai nama berkas. Laravel `store()`
 *                     sendiri sudah membersihkannya, tapi mengandalkan itu
 *                     berarti bergantung pada perilaku yang bisa berubah di
 *                     versi berikutnya.
 *
 *    Nama tabrakan    dua driver mengunggah `ktp.jpg`. Yang kedua menimpa yang
 *                     pertama, dan KTP driver A menjadi KTP driver B di mata
 *                     verifikator. Tidak ada galat.
 *
 *    Ekstensi ganda   `foto.jpg.php`. Di server yang salah konfigurasi, itu
 *                     berkas yang bisa dieksekusi.
 *
 *  Nama yang dihasilkan: `{prefix}/{uuid7}.{ext}`, dengan ekstensi dari
 *  PENGENDUSAN isi berkas — bukan dari nama yang dikirim.
 * ============================================================================
 *
 * ============================================================================
 *  BERKAS LAMA DIBUANG, DAN URUTANNYA PENTING
 * ============================================================================
 *  Dokumen driver punya `unique(driver_id, type)`: satu KTP per driver. Driver
 *  yang mengunggah ulang karena fotonya kabur mengganti barisnya — dan berkas
 *  lamanya harus ikut dibuang.
 *
 *  Kalau tidak: setiap unggahan ulang meninggalkan satu KTP di disk yang tidak
 *  ditunjuk baris mana pun. Tidak ada yang tahu itu milik siapa, tidak ada yang
 *  bisa menghapusnya berdasarkan permintaan, dan jumlahnya tumbuh selamanya.
 *  Untuk data identitas, tumpukan seperti itu adalah kewajiban hukum yang
 *  menumpuk tanpa ada yang menyadarinya.
 *
 *  Yang baru disimpan LEBIH DULU, yang lama dibuang setelahnya — lihat
 *  [replace]. Urutan sebaliknya berarti kegagalan penyimpanan meninggalkan baris
 *  yang menunjuk ke berkas yang sudah tidak ada.
 * ============================================================================
 */
final readonly class ImageStore
{
    /**
     * Tipe MIME yang diterima, beserta ekstensi yang dipakai untuk masing-masing.
     *
     * ========================================================================
     *  DAFTAR IZIN, BUKAN DAFTAR LARANGAN
     * ========================================================================
     *  Yang tidak ada di sini ditolak. Bentuk sebaliknya — menolak daftar tipe
     *  berbahaya — selalu ketinggalan satu, dan yang ketinggalan itu yang dipakai.
     *
     *  SVG sengaja TIDAK ada di daftar, walaupun dia gambar. SVG adalah XML yang
     *  bisa memuat `<script>`, dan gambar yang dilayani dari domain kita sendiri
     *  berarti script itu berjalan di origin kita — dengan akses ke cookie
     *  sesinya. Untuk foto KTP dan foto produk, SVG juga tidak masuk akal.
     * ========================================================================
     *
     * @var array<string, string>
     */
    private const MIME_DITERIMA = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * Simpan gambar dan kembalikan path beserta hash-nya.
     *
     * @param  string  $prefix  Direktori di dalam disk, misalnya `driver/12`.
     * @return array{path: string, hash: string, size: int, mime: string}
     *
     * @throws ImageRejectedException
     */
    public function store(UploadedFile $file, string $disk, string $prefix): array
    {
        $mime = $this->mimeSebenarnya($file);

        $ekstensi = self::MIME_DITERIMA[$mime];

        /*
         * uuid7, bukan uuid4 atau hash isi berkas.
         *
         * uuid7 terurut waktu, jadi berkas yang berdekatan waktunya berdekatan
         * juga namanya — yang membuat listing direktori bisa dibaca dan backup
         * inkremental jauh lebih efisien.
         *
         * BUKAN hash isi berkas sebagai nama: dua driver yang memfoto KTP yang
         * SAMA (dokumen dipinjam, atau pemalsuan) akan menghasilkan hash yang
         * sama, dan yang kedua akan menimpa yang pertama. Kasus itu justru yang
         * paling perlu terlihat, bukan yang paling perlu digabung.
         */
        $nama = Str::uuid7()->toString().'.'.$ekstensi;

        $path = trim($prefix, '/').'/'.$nama;

        /*
         * `putFileAs` dengan nama yang KITA tentukan, bukan `store()`.
         *
         * `store()` menghasilkan nama acak dan mengembalikannya, yang juga aman —
         * tapi ekstensinya diambil dari nama berkas client. Berkas bernama
         * `ktp.php` yang isinya benar-benar JPEG akan disimpan sebagai `.php`.
         */
        $tersimpan = Storage::disk($disk)->putFileAs(
            trim($prefix, '/'),
            $file,
            $nama,
        );

        if ($tersimpan === false) {
            throw ImageRejectedException::gagalDisimpan();
        }

        return [
            'path' => $path,

            /*
             * Hash isi berkas, disimpan bersama barisnya.
             *
             * Dua gunanya, dan keduanya muncul belakangan:
             *
             *   * Verifikator bisa diberi tahu kalau dokumen yang diunggah
             *     PERSIS SAMA dengan dokumen driver lain — tanda dokumen
             *     dipinjam, dan itu yang paling sering ditemukan pada pemalsuan.
             *
             *   * Kalau berkasnya rusak di penyimpanan, hash-nya yang
             *     memperlihatkannya. Tanpa hash, KTP yang berubah satu byte
             *     terlihat baik-baik saja sampai ada yang membukanya.
             */
            'hash' => hash_file('sha256', $file->getRealPath()) ?: '',

            'size' => (int) $file->getSize(),
            'mime' => $mime,
        ];
    }

    /**
     * Simpan yang baru, lalu buang yang lama.
     *
     * @return array{path: string, hash: string, size: int, mime: string}
     *
     * @throws ImageRejectedException
     */
    public function replace(
        UploadedFile $file,
        string $disk,
        string $prefix,
        ?string $pathLama,
    ): array {
        // Yang baru disimpan LEBIH DULU. Kalau penyimpanannya gagal, berkas lama
        // masih utuh dan barisnya masih menunjuk ke sesuatu yang ada.
        $hasil = $this->store($file, $disk, $prefix);

        if ($pathLama !== null && $pathLama !== '' && $pathLama !== $hasil['path']) {
            $this->buang($disk, $pathLama);
        }

        return $hasil;
    }

    /**
     * Buang berkas. Tidak pernah melempar.
     *
     * Berkas yang gagal dihapus TIDAK boleh menggagalkan unggahan yang sudah
     * berhasil: yang tertinggal hanya satu berkas tanpa pemilik, sementara
     * melempar di sini berarti driver melihat "gagal" untuk dokumen yang
     * sebenarnya sudah tersimpan — dan dia akan mengunggahnya lagi.
     */
    public function buang(string $disk, string $path): bool
    {
        try {
            return Storage::disk($disk)->delete($path);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Tipe MIME dari ISI berkas, bukan dari yang dikirim client.
     *
     * ========================================================================
     *  `getClientMimeType` TIDAK PERNAH DIPAKAI DI SINI
     * ========================================================================
     *  Nilai itu datang dari header `Content-Type` yang ditulis client, dan
     *  client bisa menuliskan apa pun. Berkas PHP yang dikirim dengan
     *  `Content-Type: image/jpeg` akan lolos setiap pemeriksaan yang
     *  mempercayainya.
     *
     *  `getMimeType()` di Symfony membaca ISI berkasnya — magic bytes lewat
     *  ekstensi fileinfo. Itu yang dipakai.
     *
     *  Aturan validasi `image` di Laravel juga memeriksa dengan cara ini, dan
     *  request class memang sudah memakainya. Pemeriksaan di sini adalah lapis
     *  kedua: kelas ini bisa dipanggil dari tempat yang tidak melewati
     *  FormRequest — job, command, seeder — dan lapisan penyimpanan tidak boleh
     *  bergantung pada siapa yang memanggilnya.
     * ========================================================================
     *
     * @throws ImageRejectedException
     */
    private function mimeSebenarnya(UploadedFile $file): string
    {
        if (! $file->isValid()) {
            throw ImageRejectedException::unggahanTidakSah();
        }

        $mime = (string) $file->getMimeType();

        if (! isset(self::MIME_DITERIMA[$mime])) {
            /*
             * Tipe sebenarnya masuk LOG, bukan response.
             *
             * Untuk unggahan dokumen identitas, memberitahu pengunggah tipe apa
             * yang terdeteksi membantu orang yang sedang mencari tipe yang tidak
             * diperiksa. Yang perlu mengetahuinya kita, bukan dia.
             *
             * Nama berkas dari client ikut dicatat karena pasangan
             * "nama .jpg tapi isinya application/x-php" adalah tanda percobaan
             * yang paling jelas — dan itu tidak terlihat dari salah satunya saja.
             */
            Log::warning('Unggahan gambar ditolak: tipe tidak didukung', [
                'mime_terdeteksi' => $mime,
                'nama_dari_client' => $file->getClientOriginalName(),
                'ukuran' => $file->getSize(),
            ]);

            throw ImageRejectedException::tipeTidakDidukung();
        }

        return $mime;
    }
}
