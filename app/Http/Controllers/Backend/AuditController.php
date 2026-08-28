<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backend;

use App\Domain\Shared\Support\BusinessClock;
use App\Domain\Support\Models\AuditLog;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Audit log.
 *
 * ============================================================================
 *  HALAMAN INI TIDAK PUNYA TOMBOL YANG MENGUBAH APA PUN
 * ============================================================================
 *  Hanya membaca, dan itu bukan kekurangan fitur. Audit log yang bisa diedit
 *  atau dihapus dari panel tidak membuktikan apa pun — dan justru orang yang
 *  paling ingin mengubahnya adalah orang yang punya akses ke panel.
 *
 *  Tidak ada endpoint hapus, tidak ada endpoint edit, dan penghapusan retensi
 *  dijalankan perintah artisan yang berjalan di server, bukan lewat HTTP.
 * ============================================================================
 */
class AuditController extends Controller
{
    public function index(Request $request): View
    {
        $query = AuditLog::query()
            ->with('admin')
            ->latest('created_at');

        if ($request->filled('admin_id')) {
            $query->where('admin_id', $request->integer('admin_id'));
        }

        if ($request->filled('action')) {
            /*
             * Pencocokan PREFIX, bukan sama-dengan.
             *
             * Nama tindakan berbentuk hierarki: `finance.withdrawal_approved`,
             * `finance.withdrawal_rejected`, `finance.reauth_failed`. Yang
             * dibutuhkan saat menyelidiki biasanya seluruh kelompok, bukan satu
             * tindakan — "apa saja yang dilakukan tim finance kemarin".
             */
            $query->where('action', 'like', $request->string('action').'%');
        }

        if ($request->filled('dari')) {
            [$mulai] = BusinessClock::dayRange(
                Carbon::parse((string) $request->string('dari'))
            );
            $query->where('created_at', '>=', $mulai);
        }

        if ($request->filled('sampai')) {
            [, $selesai] = BusinessClock::dayRange(
                Carbon::parse((string) $request->string('sampai'))
            );
            $query->where('created_at', '<=', $selesai);
        }

        return view('backend.audit.index', [
            'log' => $query->paginate(50)->withQueryString(),

            'admin' => DB::table('admins')->orderBy('name')->get(['id', 'name']),

            /*
             * Daftar tindakan diambil dari data, bukan dari daftar tetap.
             *
             * Tindakan baru muncul setiap kali ada fitur baru, dan daftar tetap
             * di kode akan tertinggal. Yang diambil hanya prefix-nya — bagian
             * sebelum titik pertama — supaya filternya berupa kelompok, bukan
             * ratusan pilihan.
             */
            'kelompokTindakan' => DB::table('audit_logs')
                ->selectRaw("split_part(action, '.', 1) AS kelompok, COUNT(*) AS jumlah")
                ->groupBy('kelompok')
                ->orderBy('kelompok')
                ->get(),
        ]);
    }

    /**
     * Satu baris audit, lengkap dengan nilai sebelum dan sesudah.
     */
    public function show(string $uuid): View
    {
        $baris = AuditLog::query()
            ->with('admin')
            ->where('uuid', $uuid)
            ->firstOrFail();

        return view('backend.audit.show', ['baris' => $baris]);
    }
}
