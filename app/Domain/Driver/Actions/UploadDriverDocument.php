<?php

declare(strict_types=1);

namespace App\Domain\Driver\Actions;

use App\Domain\Driver\Models\Driver;
use App\Domain\Driver\Models\DriverDocument;
use App\Infrastructure\Storage\ImageStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Menyimpan atau mengganti satu dokumen KYC driver.
 *
 * ============================================================================
 *  UNGGAH ULANG MENGGANTI, DAN MENGEMBALIKAN STATUSNYA KE `pending`
 * ============================================================================
 *  `driver_documents` punya `unique(driver_id, type)`: satu KTP per driver, satu
 *  SIM per driver. Jadi unggahan kedua untuk jenis yang sama BUKAN baris baru —
 *  dia mengganti yang ada.
 *
 *  Dan statusnya kembali ke `pending`, termasuk untuk dokumen yang SUDAH
 *  disetujui. Itu bagian yang paling penting di seluruh kelas ini:
 *
 *    Tanpa reset, driver bisa mengunggah KTP orang lain SETELAH KTP-nya sendiri
 *    disetujui, dan barisnya tetap berstatus `approved`. Yang dilihat
 *    verifikator berikutnya: dokumen yang sudah lolos. Yang sebenarnya ada di
 *    disk: dokumen yang tidak pernah diperiksa siapa pun.
 *
 *  Konsekuensi yang harus disadari: driver yang mengunggah ulang SIM-nya karena
 *  fotonya kabur akan kehilangan persetujuannya sampai diperiksa lagi — dan
 *  kalau SIM termasuk dokumen wajib, dia tidak bisa online selama itu.
 *
 *  Itu memang yang benar. Alternatifnya adalah mempercayai berkas yang belum
 *  dilihat siapa pun, dan yang menanggung akibatnya bukan kita.
 * ============================================================================
 *
 * ============================================================================
 *  BERKAS DISIMPAN DI LUAR TRANSAKSI, BARISNYA DI DALAM
 * ============================================================================
 *  Penulisan disk TIDAK bisa di-rollback. Kalau berkasnya disimpan di dalam
 *  transaksi dan transaksinya batal, berkasnya tetap ada di disk tanpa baris yang
 *  menunjuknya — dan untuk dokumen identitas, berkas tanpa pemilik adalah
 *  kewajiban yang tidak bisa ditemukan lagi untuk dihapus.
 *
 *  Jadi urutannya: simpan berkas, lalu tulis barisnya dalam transaksi. Kalau
 *  penulisan barisnya gagal, berkas yang baru dibuang secara eksplisit —
 *  `catch` di bawah. Yang tersisa hanya kalau proses mati di antara keduanya, dan
 *  itu jauh lebih sempit daripada setiap kegagalan validasi.
 * ============================================================================
 */
final readonly class UploadDriverDocument
{
    public function __construct(private ImageStore $store) {}

    /**
     * @param  string  $type  `ktp`, `sim`, `stnk`, `skck`, `selfie`, `bank_book`, `vaccine`.
     */
    public function handle(
        Driver $driver,
        string $type,
        UploadedFile $file,
        ?string $expiresAt = null,
        ?string $number = null,
    ): DriverDocument {
        $disk = (string) config('antaride.storage.kyc_disk', 'kyc');

        $lama = DriverDocument::query()
            ->where('driver_id', $driver->getKey())
            ->where('type', $type)
            ->first();

        /*
         * Prefix per driver: `driver/{id}`.
         *
         * Bukan satu direktori berisi semuanya. Dua alasan yang muncul saat
         * jumlahnya sudah besar:
         *
         *   * Permintaan penghapusan data ("hapus semua data saya") menjadi satu
         *     penghapusan direktori, bukan pencarian di ratusan ribu berkas.
         *   * Filesystem lokal melambat drastis pada direktori dengan puluhan
         *     ribu entri, dan yang paling dulu terasa adalah listing untuk backup.
         */
        $hasil = $this->store->replace(
            file: $file,
            disk: $disk,
            prefix: 'driver/'.$driver->getKey(),
            pathLama: $lama?->file_path,
        );

        try {
            return DB::transaction(function () use (
                $driver,
                $type,
                $hasil,
                $expiresAt,
                $number,
                $lama,
            ): DriverDocument {
                $atribut = [
                    'file_path' => $hasil['path'],
                    'file_hash' => $hasil['hash'],

                    /*
                     * Status SELALU kembali ke `pending`, dan jejak review lama
                     * dihapus seluruhnya.
                     *
                     * `reviewed_by_admin_id` dan `reviewed_at` yang tertinggal
                     * akan membuat panel admin menampilkan "diperiksa oleh Budi
                     * pada 3 Agustus" untuk berkas yang diunggah hari ini dan
                     * belum dilihat siapa pun. Itu bukan data yang basi — itu
                     * pernyataan yang salah.
                     */
                    'status' => 'pending',
                    'reject_reason' => null,
                    'reviewed_by_admin_id' => null,
                    'reviewed_at' => null,
                ];

                // Nilai null TIDAK menimpa yang sudah ada.
                //
                // Driver yang mengunggah ulang fotonya saja tidak perlu mengisi
                // ulang nomor dan tanggal berlakunya. Menimpanya dengan null akan
                // menghapus data yang sudah benar — dan verifikator harus
                // menanyakannya lagi.
                if ($expiresAt !== null) {
                    $atribut['expires_at'] = $expiresAt;
                }

                if ($number !== null) {
                    $atribut['number'] = $number;
                }

                if ($lama !== null) {
                    $lama->update($atribut);

                    return $lama->fresh() ?? $lama;
                }

                return DriverDocument::create([
                    'driver_id' => $driver->getKey(),
                    'type' => $type,
                    ...$atribut,
                ]);
            });
        } catch (\Throwable $e) {
            /*
             * Barisnya gagal ditulis: berkas yang baru diunggah dibuang.
             *
             * Tanpa ini, setiap kegagalan penulisan meninggalkan satu foto KTP
             * di disk yang tidak ditunjuk baris mana pun — tidak bisa ditemukan,
             * tidak bisa dihapus atas permintaan, dan tidak diketahui ada.
             *
             * Berkas LAMA tidak bisa dikembalikan; `replace` sudah membuangnya.
             * Itu kompromi yang disengaja: alternatifnya menahan berkas lama
             * sampai transaksinya commit, yang menuntut pembersihan tertunda
             * beserta jobnya sendiri — mesin yang lebih rumit daripada masalah
             * yang diselesaikannya.
             */
            $this->store->buang($disk, $hasil['path']);

            throw $e;
        }
    }
}
