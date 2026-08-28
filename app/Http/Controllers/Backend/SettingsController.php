<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backend;

use App\Domain\Support\Models\AuditLog;
use App\Domain\Support\Models\FeatureFlag;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Kill switch dan feature flag.
 *
 * ============================================================================
 *  HALAMAN INI YANG MEMBUAT PANEL ADMIN PUNYA NILAI OPERASIONAL
 * ============================================================================
 *  Tanpa kill switch, satu-satunya cara menghentikan penerimaan order saat ada
 *  banjir atau serangan adalah deploy — dan deploy saat sedang ada insiden
 *  adalah hal terakhir yang mau dilakukan siapa pun.
 *
 *  Yang membuat kill switch benar-benar berguna bukan keberadaannya, tapi tiga
 *  hal ini:
 *
 *    1. Efeknya LANGSUNG. Cache dibatalkan saat diubah, bukan menunggu 30 detik.
 *       Ini ditegakkan FeatureFlag::set(), bukan controller.
 *
 *    2. Yang dimatikan TERLIHAT di setiap halaman. Banner di layout memastikan
 *       switch yang lupa dinyalakan tidak bertahan berjam-jam.
 *
 *    3. Setiap perubahan punya ALASAN yang tercatat. Switch yang dimatikan tanpa
 *       keterangan tidak bisa dinilai orang berikutnya: apakah masih perlu, atau
 *       sudah boleh dinyalakan.
 * ============================================================================
 */
class SettingsController extends Controller
{
    public function flags(): View
    {
        $flags = FeatureFlag::query()
            ->with('updatedBy')
            ->orderBy('key')
            ->get();

        return view('backend.settings.flags', [
            /*
             * Yang MATI ditampilkan lebih dulu.
             *
             * Halaman ini dibuka saat ada yang bertanya "kenapa order tidak
             * masuk", dan jawabannya selalu ada di antara switch yang mati.
             * Mengurutkannya menurut abjad berarti jawaban itu bisa berada di
             * baris kedua puluh.
             */
            'flags' => $flags->sortBy([
                fn ($a, $b) => (int) $a->is_enabled <=> (int) $b->is_enabled,
                fn ($a, $b) => strcmp((string) $a->key, (string) $b->key),
            ])->values(),

            'jumlahMati' => $flags->where('is_enabled', false)->count(),
        ]);
    }

    /**
     * Nyalakan atau matikan satu flag.
     */
    public function toggleFlag(Request $request, string $key): RedirectResponse
    {
        $request->validate([
            'enabled' => ['required', 'boolean'],

            /*
             * Alasan WAJIB saat MEMATIKAN, opsional saat menyalakan.
             *
             * Asimetrinya disengaja. Mematikan sesuatu adalah keputusan yang
             * orang berikutnya harus bisa menilai — "apakah ini masih perlu?" —
             * dan tanpa alasan, jawabannya tidak ada di mana pun. Menyalakan
             * kembali adalah pemulihan ke keadaan normal, dan menuntut penjelasan
             * untuk itu hanya menambah gesekan pada saat insiden baru selesai.
             */
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $enabled = $request->boolean('enabled');

        if (! $enabled && blank($request->input('reason'))) {
            return back()->withErrors([
                'reason' => 'Sebutkan alasan mematikannya. Orang berikutnya harus bisa '
                    .'menilai apakah ini masih perlu.',
            ]);
        }

        $sebelumnya = FeatureFlag::query()->where('key', $key)->first();

        if ($sebelumnya === null) {
            return back()->with('error', 'Flag tidak dikenali.');
        }

        $nilaiSebelum = (bool) $sebelumnya->is_enabled;

        FeatureFlag::set(
            key: $key,
            enabled: $enabled,
            adminId: (int) auth('admin')->id(),
            reason: $request->input('reason'),
        );

        AuditLog::record(
            action: $enabled ? 'feature_flag.enabled' : 'feature_flag.disabled',
            auditable: $sebelumnya,
            oldValues: ['is_enabled' => $nilaiSebelum],
            newValues: [
                'is_enabled' => $enabled,
                'reason' => $request->input('reason'),
            ],
        );

        $kata = $enabled ? 'dinyalakan' : 'DIMATIKAN';

        return back()->with(
            $enabled ? 'success' : 'warning',
            "Flag {$key} {$kata}. Efeknya berlaku sekarang."
        );
    }
}
