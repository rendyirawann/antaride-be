<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backend;

use App\Domain\Driver\Models\Driver;
use App\Domain\Driver\Models\DriverDocument;
use App\Domain\Support\Models\AuditLog;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Antrean verifikasi dokumen driver.
 *
 * ============================================================================
 *  URUTAN ANTREANNYA: YANG PALING LAMA MENUNGGU DULU
 * ============================================================================
 *  Bukan yang terbaru, dan bukan yang paling lengkap dokumennya. Alasannya
 *  operasional: driver yang menunggu verifikasi tiga hari sudah kehilangan tiga
 *  hari pendapatan, dan sebagian dari mereka berhenti sebelum pernah mengambil
 *  satu order.
 *
 *  Yang terlihat di data kalau urutannya salah: pendaftaran terus masuk, tapi
 *  jumlah driver aktif tidak naik.
 * ============================================================================
 *
 * ============================================================================
 *  MEMBUKA DOKUMEN DICATAT, SETIAP KALI
 * ============================================================================
 *  Dokumen KYC memuat KTP, SIM, dan foto selfie — data paling pribadi yang
 *  dipegang platform ini. Yang melindunginya bukan hanya permission, tapi fakta
 *  bahwa setiap pembukaan tercatat dengan nama staf yang membukanya.
 *
 *  Itu yang membuat "membuka KTP orang tanpa alasan" menjadi tindakan yang bisa
 *  ditelusuri, bukan sesuatu yang tidak berjejak.
 * ============================================================================
 */
class DriverVerificationController extends Controller
{
    public function index(Request $request): View
    {
        $dokumen = DriverDocument::query()
            ->where('status', 'pending')
            ->with(['driver.user', 'driver.vehicles'])

            // Terlama dulu. Lihat penjelasan di docblock kelas.
            ->orderBy('created_at')

            ->paginate(20);

        return view('backend.driver.verification', [
            'dokumen' => $dokumen,
            'statistik' => $this->statistikAntrean(),
        ]);
    }

    /**
     * Halaman verifikasi satu driver: semua dokumennya sekaligus.
     *
     * Per DRIVER, bukan per dokumen. Verifikator perlu melihat KTP dan SIM
     * bersamaan untuk memastikan namanya sama — memeriksanya satu per satu di
     * halaman berbeda membuat pemeriksaan itu tidak terjadi.
     */
    public function show(string $uuid): View
    {
        $driver = Driver::query()
            ->where('uuid', $uuid)
            ->with(['user', 'vehicles', 'documents' => fn ($q) => $q->orderBy('type')])
            ->firstOrFail();

        AuditLog::record(
            action: 'driver.verification_opened',
            auditable: $driver,
        );

        return view('backend.driver.verify-detail', [
            'driver' => $driver,

            /*
             * Dokumen yang WAJIB ada, dan mana yang belum diunggah.
             *
             * Dihitung di sini, bukan dibiarkan verifikator menyimpulkannya dari
             * daftar yang ada. Dokumen yang belum diunggah tidak muncul di daftar
             * sama sekali — dan yang tidak terlihat tidak akan disadari hilang.
             */
            'wajib' => $this->kelengkapanDokumen($driver),
        ]);
    }

    /**
     * Setujui satu dokumen.
     */
    public function approve(Request $request, int $documentId): RedirectResponse
    {
        $request->validate([
            /*
             * Tanggal kadaluarsa WAJIB untuk dokumen yang punya masa berlaku.
             *
             * SIM dan STNK kadaluarsa tanpa ada yang menekan tombol apa pun —
             * hanya karena waktu berjalan. Tanpa tanggal ini, `GoOnline` tidak
             * punya cara mengetahuinya, dan driver dengan SIM habis tetap
             * mengambil order.
             *
             * Yang dipertaruhkan bukan kepatuhan administratif: kalau terjadi
             * kecelakaan dengan driver tanpa SIM berlaku, tanggung jawabnya ada
             * di platform yang membiarkannya bekerja.
             */
            'expires_at' => ['nullable', 'date', 'after:today'],
        ], [
            'expires_at.after' => 'Tanggal kadaluarsa harus di masa depan. Dokumen yang sudah habis tidak boleh disetujui.',
        ]);

        $dokumen = DriverDocument::query()->with('driver')->findOrFail($documentId);

        $butuhKadaluarsa = in_array(
            $dokumen->type,
            (array) config('antaride.kyc.expiring_documents', ['sim', 'stnk']),
            true,
        );

        if ($butuhKadaluarsa && $request->input('expires_at') === null) {
            return back()->withErrors([
                'expires_at' => 'Dokumen jenis '.$dokumen->label().' wajib punya tanggal kadaluarsa.',
            ]);
        }

        $sebelum = $dokumen->only(['status', 'expires_at', 'reject_reason']);

        $dokumen->forceFill([
            'status' => 'approved',
            'expires_at' => $request->input('expires_at'),
            'reviewed_by_admin_id' => auth('admin')->id(),
            'reviewed_at' => now(),

            /*
             * `reject_reason` DIKOSONGKAN saat disetujui.
             *
             * Kolomnya hanya untuk alasan penolakan, dan namanya sudah
             * mengatakan itu. Menyimpan catatan persetujuan di sana berarti
             * halaman driver akan menampilkan "alasan penolakan" untuk dokumen
             * yang justru disetujui — dan aplikasi driver membaca kolom ini
             * untuk memberi tahu apa yang harus diperbaiki.
             *
             * Dikosongkan, bukan dibiarkan: dokumen yang pernah ditolak lalu
             * diunggah ulang dan disetujui tidak boleh tetap membawa alasan
             * penolakan yang lama.
             */
            'reject_reason' => null,
        ])->save();

        AuditLog::record(
            action: 'driver.document_approved',
            auditable: $dokumen,
            oldValues: $sebelum,
            newValues: $dokumen->only(['status', 'expires_at']),
        );

        $this->aktifkanDriverKalauLengkap($dokumen->driver);

        return back()->with('success', $dokumen->label().' disetujui.');
    }

    /**
     * Tolak satu dokumen.
     */
    public function reject(Request $request, int $documentId): RedirectResponse
    {
        $request->validate([
            /*
             * Alasan penolakan WAJIB dan panjangnya bermakna.
             *
             * Alasan inilah yang dikirim ke aplikasi driver, dan dia harus bisa
             * memperbaiki unggahannya berdasarkan itu. "Tidak jelas" akan
             * menghasilkan unggahan kedua yang sama buruknya, lalu ketiga —
             * dan setiap putaran memakan satu hari kerja verifikator.
             */
            'note' => ['required', 'string', 'min:15', 'max:500'],
        ], [
            'note.required' => 'Jelaskan apa yang harus diperbaiki driver.',
            'note.min' => 'Alasannya harus cukup jelas untuk ditindaklanjuti driver (minimal 15 karakter).',
        ]);

        $dokumen = DriverDocument::query()->with('driver')->findOrFail($documentId);

        $sebelum = $dokumen->only(['status', 'reject_reason']);

        $dokumen->forceFill([
            'status' => 'rejected',
            'reviewed_by_admin_id' => auth('admin')->id(),
            'reviewed_at' => now(),
            'reject_reason' => (string) $request->input('note'),
        ])->save();

        AuditLog::record(
            action: 'driver.document_rejected',
            auditable: $dokumen,
            oldValues: $sebelum,
            newValues: $dokumen->only(['status', 'reject_reason']),
        );

        return back()->with('success', $dokumen->label().' ditolak. Driver akan diberi tahu.');
    }

    /**
     * Buka URL bertanda tangan untuk melihat berkasnya.
     *
     * Endpoint tersendiri, bukan URL yang langsung ditanam di HTML. Alasannya:
     * URL bertanda tangan berumur lima menit, dan halaman verifikasi bisa
     * terbuka jauh lebih lama dari itu. URL yang ditanam saat render akan sudah
     * kadaluarsa ketika verifikator akhirnya mengkliknya.
     */
    public function viewFile(int $documentId): RedirectResponse
    {
        $dokumen = DriverDocument::query()->findOrFail($documentId);

        $url = $dokumen->temporaryUrl();

        if ($url === null) {
            return back()->with('error', 'Berkasnya tidak ditemukan.');
        }

        AuditLog::recordSensitiveAccess('driver_document', $dokumen);

        return redirect()->away($url);
    }

    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function statistikAntrean(): array
    {
        $baris = DB::table('driver_documents')
            ->selectRaw("
                COUNT(*) FILTER (WHERE status = 'pending') AS menunggu,
                COUNT(*) FILTER (WHERE status = 'rejected') AS ditolak,
                COUNT(*) FILTER (WHERE status = 'approved' AND expires_at IS NOT NULL AND expires_at < now()) AS kadaluarsa,
                MIN(created_at) FILTER (WHERE status = 'pending') AS tertua
            ")
            ->first();

        return [
            'menunggu' => (int) ($baris->menunggu ?? 0),
            'ditolak' => (int) ($baris->ditolak ?? 0),

            /*
             * Dokumen yang sudah disetujui tapi KADALUARSA ikut dihitung.
             *
             * Ini kelompok yang paling mudah terlupakan: dokumennya pernah
             * benar, tidak ada yang menolaknya, dan drivernya tidak bisa online
             * tanpa tahu kenapa. Angka ini yang membuatnya terlihat.
             */
            'kadaluarsa' => (int) ($baris->kadaluarsa ?? 0),

            'tertua' => $baris->tertua === null
                ? null
                : Carbon::parse($baris->tertua),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function kelengkapanDokumen(Driver $driver): array
    {
        /*
         * Daftar dokumen wajib bergantung jenis kendaraan.
         *
         * Driver mobil butuh STNK; driver motor juga. Yang berbeda nanti kalau
         * ada layanan yang menuntut dokumen tambahan — dan daftarnya di config
         * supaya bisa berubah tanpa deploy.
         */
        $wajib = (array) config('antaride.kyc.required_documents', [
            'ktp', 'sim', 'stnk', 'selfie',
        ]);

        $dimiliki = $driver->documents->keyBy('type');
        $hasil = [];

        foreach ($wajib as $jenis) {
            $dokumen = $dimiliki->get($jenis);

            $hasil[$jenis] = [
                'ada' => $dokumen !== null,
                'status' => $dokumen?->status,
                'kadaluarsa' => $dokumen?->expires_at,
                'sudah_kadaluarsa' => $dokumen?->expires_at !== null
                    && $dokumen->expires_at->isPast(),
                'dokumen' => $dokumen,
            ];
        }

        return $hasil;
    }

    /**
     * Aktifkan driver kalau seluruh dokumen wajibnya sudah disetujui.
     *
     * ========================================================================
     *  OTOMATIS, TAPI TIDAK MELEWATI SATU PUN PEMERIKSAAN
     * ========================================================================
     *  Verifikator menyetujui dokumen satu per satu. Tanpa langkah ini, ada
     *  langkah manual tambahan setelah dokumen terakhir — dan langkah manual
     *  yang mudah dilupakan berarti driver yang seluruh dokumennya sudah
     *  disetujui tetap tidak bisa bekerja.
     *
     *  Yang TIDAK dilakukan: mengaktifkan driver yang dokumennya belum lengkap.
     *  Pemeriksaannya di sini sama dengan yang dipakai `GoOnline`, jadi driver
     *  yang lolos di sini juga akan lolos di sana.
     * ========================================================================
     */
    private function aktifkanDriverKalauLengkap(Driver $driver): void
    {
        if ($driver->status->value === 'active') {
            return;
        }

        $driver->load('documents');
        $kelengkapan = $this->kelengkapanDokumen($driver);

        foreach ($kelengkapan as $item) {
            if ($item['status'] !== 'approved' || $item['sudah_kadaluarsa']) {
                return;
            }
        }

        $sebelum = $driver->only(['status', 'verified_at']);

        $driver->forceFill([
            'status' => 'active',
            'verified_at' => now(),
            'verified_by_admin_id' => auth('admin')->id(),
        ])->save();

        AuditLog::record(
            action: 'driver.activated',
            auditable: $driver,
            oldValues: $sebelum,
            newValues: $driver->only(['status', 'verified_at']),
        );
    }
}
