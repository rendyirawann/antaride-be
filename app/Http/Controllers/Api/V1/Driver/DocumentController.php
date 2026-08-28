<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Driver;

use App\Domain\Driver\Actions\UploadDriverDocument;
use App\Domain\Driver\Models\Driver;
use App\Domain\Driver\Models\DriverDocument;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Driver\UploadDocumentRequest;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dokumen KYC driver: unggah dan lihat status.
 *
 * ============================================================================
 *  TANPA ENDPOINT INI, TIDAK ADA SATU PUN DRIVER YANG BISA MULAI BEKERJA
 * ============================================================================
 *  Kolomnya sudah ada sejak awal (`driver_documents`), panel verifikasi admin
 *  sudah lengkap, dan `GoOnline` sudah menolak driver yang dokumennya belum
 *  disetujui.
 *
 *  Yang tidak ada: cara driver MENGIRIM dokumennya. Sampai endpoint ini ada,
 *  satu-satunya jalan adalah admin memasukkan barisnya lewat database secara
 *  manual — yang berarti pendaftaran driver tidak bisa dijalankan sama sekali.
 * ============================================================================
 *
 * ============================================================================
 *  `file_path` TIDAK PERNAH KELUAR KE APLIKASI
 * ============================================================================
 *  `DriverDocument` menyembunyikannya lewat `$hidden`, dan bentuk response di
 *  sini disusun manual — bukan `->toArray()`.
 *
 *  Yang dikirim sebagai gantinya: URL bertanda tangan berumur lima menit. Path
 *  mentah tidak berguna bagi aplikasi (disknya privat), tapi berguna bagi orang
 *  yang sedang mencari cara menebak path dokumen driver lain.
 * ============================================================================
 */
class DocumentController extends Controller
{
    /**
     * Daftar dokumen driver, beserta yang masih kurang.
     *
     * ========================================================================
     *  YANG KURANG IKUT DIKIRIM, DAN ITU YANG MEMBUAT LAYARNYA BISA DIPAKAI
     * ========================================================================
     *  Daftar dokumen yang sudah diunggah saja tidak cukup: driver baru punya
     *  daftar KOSONG, dan layar yang menampilkan daftar kosong tidak memberi tahu
     *  dia harus mengunggah apa.
     *
     *  Jadi response memuat keduanya — yang sudah ada beserta statusnya, dan
     *  jenis apa saja yang masih wajib. Daftar wajibnya dari
     *  `antaride.kyc.required_documents`, bukan ditulis di aplikasi: kalau nanti
     *  ada peraturan daerah yang menuntut dokumen tambahan, aplikasi lama tetap
     *  menampilkannya tanpa perlu dirilis ulang.
     * ========================================================================
     */
    public function index(Request $request): JsonResponse
    {
        $driver = $this->driver($request);

        $dokumen = DriverDocument::query()
            ->where('driver_id', $driver->getKey())
            ->orderBy('type')
            ->get();

        /** @var list<string> $wajib */
        $wajib = (array) config('antaride.kyc.required_documents', []);

        /** @var list<string> $berlakuTerbatas */
        $berlakuTerbatas = (array) config('antaride.kyc.expiring_documents', []);

        /*
         * ====================================================================
         *  DISETUJUI SAJA TIDAK CUKUP — YANG KADALUARSA TIDAK TERHITUNG SIAP
         * ====================================================================
         *  Ini bug yang sudah pernah hidup di endpoint ini: `can_go_online`
         *  dihitung dari status `approved` saja, sementara `GoOnline` MENOLAK
         *  driver yang punya dokumen `approved` dengan `expires_at` yang sudah
         *  lewat.
         *
         *  Akibatnya: layar menyatakan "Dokumen lengkap, Anda sudah bisa mulai
         *  bekerja", lalu tombol online ditolak backend. Driver yang SIM-nya habis
         *  bulan lalu tidak punya satu pun petunjuk tentang apa yang salah —
         *  layarnya sendiri menyatakan dia siap.
         *
         *  Jadi yang dihitung siap hanya dokumen yang `approved` DAN belum
         *  kadaluarsa. Ada test yang membandingkan hasilnya dengan keputusan
         *  `GoOnline` — dua tempat yang menjawab pertanyaan yang sama harus
         *  sepakat, dan yang menjaganya bukan kehati-hatian.
         * ====================================================================
         */
        $siapDipakai = $dokumen
            ->filter(
                fn (DriverDocument $d): bool => $d->isApproved() && ! $d->isExpired(),
            )
            ->pluck('type')
            ->all();

        $kurang = array_values(array_diff($wajib, $siapDipakai));

        /*
         * Dokumen kadaluarsa yang menghalangi, TERMASUK yang tidak wajib.
         *
         * `GoOnline` menolak setiap dokumen `approved` yang tanggalnya lewat —
         * tanpa memeriksa apakah jenisnya wajib. Itu memang yang benar: SKCK
         * kadaluarsa yang tersimpan sebagai disetujui adalah dokumen tidak sah di
         * berkas kita, bukan sekadar dokumen opsional.
         *
         * Jadi daftar ini dihitung dari SELURUH dokumen, bukan dari yang wajib.
         * Kalau tidak, driver dengan SKCK kadaluarsa akan melihat "dokumen
         * lengkap" lalu ditolak online.
         */
        $kadaluarsa = $dokumen
            ->filter(
                fn (DriverDocument $d): bool => $d->isApproved() && $d->isExpired(),
            )
            ->pluck('type')
            ->values()
            ->all();

        return ApiResponse::success([
            'documents' => $dokumen->map(
                fn (DriverDocument $d): array => $this->bentuk($d, $berlakuTerbatas),
            )->all(),

            'required' => $wajib,

            /*
             * Yang masih menghalangi driver bekerja.
             *
             * Dokumen yang sudah diunggah tapi masih `pending` TETAP masuk daftar
             * ini — dia memang belum bisa dipakai bekerja, dan driver yang
             * melihatnya hilang dari daftar akan menyimpulkan dia sudah bisa
             * online.
             */
            'missing' => $kurang,

            /*
             * Jenis yang perlu diperbarui karena tanggalnya sudah lewat.
             *
             * Dikirim terpisah dari `missing` karena tindakannya berbeda: yang
             * `missing` belum pernah diunggah atau belum diperiksa, sementara ini
             * pernah lolos dan sekarang perlu diperpanjang di kantor yang
             * menerbitkannya. Kalimat di layar untuk keduanya tidak bisa sama.
             */
            'expired' => $kadaluarsa,

            'can_go_online' => $kurang === [] && $kadaluarsa === [],
        ]);
    }

    /**
     * Unggah atau ganti satu dokumen.
     *
     * Mengembalikan 200, bukan 201, walaupun barisnya bisa baru. Alasannya:
     * aplikasi tidak membedakan keduanya — jenis dokumennya unik per driver, jadi
     * "membuat" dan "mengganti" adalah operasi yang sama dari sisi layar. Kode
     * yang berbeda hanya menambah cabang yang tidak dipakai.
     */
    public function store(
        UploadDocumentRequest $request,
        UploadDriverDocument $action,
    ): JsonResponse {
        $driver = $this->driver($request);

        $dokumen = $action->handle(
            driver: $driver,
            type: (string) $request->validated('type'),
            file: $request->file('file'),
            expiresAt: $request->validated('expires_at'),
            number: $request->validated('number'),
        );

        /** @var list<string> $berlakuTerbatas */
        $berlakuTerbatas = (array) config('antaride.kyc.expiring_documents', []);

        return ApiResponse::success($this->bentuk($dokumen, $berlakuTerbatas));
    }

    // -------------------------------------------------------------------------

    /**
     * @param  list<string>  $berlakuTerbatas
     * @return array<string, mixed>
     */
    private function bentuk(DriverDocument $d, array $berlakuTerbatas): array
    {
        return [
            'uuid' => (string) $d->uuid,
            'type' => (string) $d->type,
            'label' => $d->label(),
            'status' => (string) $d->status,

            /*
             * Alasan penolakan dikirim APA ADANYA dari verifikator.
             *
             * Ini satu-satunya cara driver mengetahui apa yang salah dengan
             * dokumennya. Tanpa itu dia mengunggah foto yang sama berulang kali —
             * dan setiap putaran memakan waktu verifikator juga.
             */
            'reject_reason' => $d->reject_reason,

            'expires_at' => $d->expires_at?->toDateString(),

            /*
             * Apakah jenis ini WAJIB punya tanggal berlaku.
             *
             * Dipakai layar untuk memutuskan apakah menampilkan kolom tanggal.
             * Menampilkannya untuk KTP — yang tidak punya masa berlaku — akan
             * membuat driver mencari tanggal yang tidak ada di kartunya.
             */
            'needs_expiry' => in_array($d->type, $berlakuTerbatas, true),

            'is_expired' => $d->isExpired(),

            /*
             * URL bertanda tangan berumur lima menit, bukan path.
             *
             * Cukup untuk menampilkan pratinjau di layar; terlalu pendek untuk
             * dibagikan lewat WhatsApp lalu masih bisa dibuka besok.
             */
            'preview_url' => $d->temporaryUrl(),

            'uploaded_at' => $d->created_at?->toIso8601String(),
            'reviewed_at' => $d->reviewed_at?->toIso8601String(),
        ];
    }

    /**
     * Driver milik pengguna yang sedang masuk.
     *
     * Melempar 403 kalau akun ini bukan driver. Itu bukan hal yang tidak mungkin
     * terjadi: satu orang bisa punya akun penumpang saja, dan token yang sama
     * dipakai untuk kedua aplikasi.
     */
    private function driver(Request $request): Driver
    {
        $driver = Driver::query()
            ->where('user_id', $request->user()?->getKey())
            ->first();

        abort_if($driver === null, 403, 'Akun ini bukan akun driver.');

        return $driver;
    }
}
