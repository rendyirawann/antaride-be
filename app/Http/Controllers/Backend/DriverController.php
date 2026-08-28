<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backend;

use App\Domain\Driver\Actions\GoOffline;
use App\Domain\Driver\Models\Driver;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Shared\Support\BusinessClock;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Support\Models\AuditLog;
use App\Domain\Wallet\Models\Wallet;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Driver di panel admin.
 */
class DriverController extends Controller
{
    public function index(Request $request): View
    {
        $query = Driver::query()->with('user');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $cari = trim((string) $request->input('cari', ''));

        if ($cari !== '') {
            /*
             * Pencarian memakai index trigram pada nama dan plat nomor.
             *
             * `drivers_name_trgm` dan index pada plat kendaraan ada supaya
             * `ILIKE '%budi%'` tetap terindeks. Tanpa memanfaatkannya, setiap
             * pencarian CS berarti sequential scan.
             */
            $query->where(function ($q) use ($cari): void {
                $q->where('full_name', 'ilike', '%'.$cari.'%')
                    ->orWhereHas('user', fn ($u) => $u->where('phone', 'ilike', '%'.$cari.'%'))
                    ->orWhereHas('vehicles', fn ($v) => $v->where('plate_number', 'ilike', '%'.$cari.'%'));
            });
        }

        return view('backend.driver.index', [
            'driver' => $query
                ->orderByDesc('created_at')
                ->paginate(25)
                ->withQueryString(),

            'statistik' => $this->statistikDriver(),
        ]);
    }

    public function show(string $uuid): View
    {
        $driver = Driver::query()
            ->where('uuid', $uuid)
            ->with(['user', 'vehicles', 'documents', 'violations'])
            ->firstOrFail();

        $dompet = Wallet::forOwner('driver', (int) $driver->getKey());

        return view('backend.driver.show', [
            'driver' => $driver,
            'dompet' => $dompet,
            'kinerja' => $this->kinerja30Hari((int) $driver->getKey()),
            'orderBerjalan' => $this->orderBerjalan((int) $driver->getKey()),

            /*
             * Riwayat sesi kerja tujuh hari terakhir.
             *
             * Dipakai saat driver mengeluh pendapatannya kecil: angka jam kerja
             * yang sebenarnya sering jauh berbeda dari yang dia ingat, dan
             * percakapannya jadi jauh lebih pendek kalau angkanya ada di layar.
             */
            'sesi' => DB::table('driver_sessions')
                ->where('driver_id', $driver->getKey())
                ->where('started_at', '>=', now()->subDays(7))
                ->orderByDesc('started_at')
                ->get(),
        ]);
    }

    /**
     * Tangguhkan driver.
     */
    public function suspend(Request $request, GoOffline $goOffline, string $uuid): RedirectResponse
    {
        $request->validate([
            'reason' => ['required', 'string', 'min:20', 'max:500'],
        ], [
            'reason.required' => 'Jelaskan alasan penangguhan.',
            'reason.min' => 'Alasannya harus cukup jelas untuk dibaca orang lain nanti '
                .'(minimal 20 karakter).',
        ]);

        $driver = Driver::query()->where('uuid', $uuid)->firstOrFail();

        $sebelum = $driver->only(['status', 'rejection_note']);

        $driver->forceFill([
            'status' => 'suspended',
            'rejection_note' => (string) $request->input('reason'),
        ])->save();

        /*
         * Driver yang ditangguhkan HARUS dipaksa offline sekarang.
         *
         * Tanpa ini, dia tetap terdaftar di indeks ketersediaan Redis dan tetap
         * ditawari order — sampai ping-nya basi atau dia offline sendiri.
         * Penangguhan yang tidak berefek sampai dia menutup aplikasi bukan
         * penangguhan.
         *
         * `force: true` karena penangguhan biasanya terjadi justru saat ada
         * masalah pada order yang sedang dia pegang, dan menolak offline karena
         * ada order berjalan akan membuat penangguhannya tidak bisa dijalankan.
         */
        $goOffline->handle($driver, force: true);

        AuditLog::record(
            action: 'driver.suspended',
            auditable: $driver,
            oldValues: $sebelum,
            newValues: $driver->only(['status', 'rejection_note']),
        );

        return back()->with('success', 'Driver ditangguhkan dan dipaksa offline.');
    }

    /**
     * Aktifkan kembali driver yang ditangguhkan.
     */
    public function reinstate(Request $request, string $uuid): RedirectResponse
    {
        $request->validate([
            'reason' => ['required', 'string', 'min:20', 'max:500'],
        ]);

        $driver = Driver::query()->where('uuid', $uuid)->firstOrFail();

        if ($driver->status->value === 'banned') {
            /*
             * Driver yang DIBLOKIR tidak bisa diaktifkan lewat jalur ini.
             *
             * Blokir permanen adalah keputusan yang dibuat karena fraud atau
             * pelanggaran berat, dan membatalkannya harus lewat approval dua
             * penyetuju — bukan satu tombol yang sama dengan membatalkan
             * penangguhan sementara.
             */
            return back()->with(
                'error',
                'Driver ini diblokir permanen. Pembatalannya perlu pengajuan approval, '
                .'bukan lewat halaman ini.'
            );
        }

        $sebelum = $driver->only(['status']);

        $driver->forceFill(['status' => 'active'])->save();

        AuditLog::record(
            action: 'driver.reinstated',
            auditable: $driver,
            oldValues: $sebelum,
            newValues: ['status' => 'active', 'reason' => $request->input('reason')],
        );

        return back()->with('success', 'Driver diaktifkan kembali.');
    }

    // -------------------------------------------------------------------------

    /**
     * @return array<string, int>
     */
    private function statistikDriver(): array
    {
        $baris = DB::table('drivers')
            ->selectRaw("
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE status = 'active') AS aktif,
                COUNT(*) FILTER (WHERE status = 'pending_review') AS menunggu_review,
                COUNT(*) FILTER (WHERE status = 'suspended') AS ditangguhkan,
                COUNT(*) FILTER (WHERE status = 'banned') AS diblokir
            ")
            ->first();

        return [
            'total' => (int) ($baris->total ?? 0),
            'aktif' => (int) ($baris->aktif ?? 0),
            'menunggu_review' => (int) ($baris->menunggu_review ?? 0),
            'ditangguhkan' => (int) ($baris->ditangguhkan ?? 0),
            'diblokir' => (int) ($baris->diblokir ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function kinerja30Hari(int $driverId): array
    {
        [$mulai] = BusinessClock::dayRange(now()->subDays(29));

        $baris = DB::table('orders')
            ->where('driver_id', $driverId)
            ->where('requested_at', '>=', $mulai)
            ->selectRaw("
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE status = 'completed') AS selesai,
                COUNT(*) FILTER (WHERE status = 'cancelled' AND cancelled_by = 'driver') AS dibatalkan_driver,
                COALESCE(SUM(driver_earning) FILTER (WHERE status = 'completed'), 0) AS pendapatan
            ")
            ->first();

        $penawaran = DB::table('order_offers')
            ->where('driver_id', $driverId)
            ->where('created_at', '>=', $mulai)
            ->selectRaw("
                COUNT(*) AS ditawari,
                COUNT(*) FILTER (WHERE response = 'accepted') AS diterima,
                COUNT(*) FILTER (WHERE response = 'rejected') AS ditolak,
                COUNT(*) FILTER (WHERE response = 'timeout') AS diabaikan,
                COUNT(*) FILTER (WHERE response = 'lost') AS kalah_balapan
            ")
            ->first();

        $ditawari = (int) ($penawaran->ditawari ?? 0);
        $kalah = (int) ($penawaran->kalah_balapan ?? 0);

        return [
            'order_total' => (int) ($baris->total ?? 0),
            'order_selesai' => (int) ($baris->selesai ?? 0),
            'dibatalkan_driver' => (int) ($baris->dibatalkan_driver ?? 0),
            'pendapatan' => Money::of((int) ($baris->pendapatan ?? 0)),

            'ditawari' => $ditawari,
            'diterima' => (int) ($penawaran->diterima ?? 0),
            'ditolak' => (int) ($penawaran->ditolak ?? 0),
            'diabaikan' => (int) ($penawaran->diabaikan ?? 0),
            'kalah_balapan' => $kalah,

            /*
             * Acceptance rate dihitung TANPA penawaran yang kalah balapan.
             *
             * Driver yang kalah adu cepat tidak melakukan kesalahan apa pun, dan
             * memasukkannya ke penyebut berarti makin sering dia ditawari
             * bersama driver lain, makin buruk angkanya. Itu menghukum driver
             * yang paling aktif.
             *
             * Angka ini yang ditampilkan ke tim ops saat menilai driver, dan
             * harus cocok dengan yang dipakai skoring matching.
             */
            'acceptance_bersih' => ($ditawari - $kalah) > 0
                ? round((int) ($penawaran->diterima ?? 0) / ($ditawari - $kalah) * 100, 1)
                : null,
        ];
    }

    private function orderBerjalan(int $driverId): ?object
    {
        return DB::table('orders')
            ->where('driver_id', $driverId)
            ->whereIn('status', OrderStatus::activeValues())
            ->first(['uuid', 'order_number', 'status', 'pickup_address']);
    }
}
