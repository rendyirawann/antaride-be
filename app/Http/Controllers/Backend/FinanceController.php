<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backend;

use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Support\Models\AuditLog;
use App\Domain\Wallet\Actions\PostLedgerEntries;
use App\Domain\Wallet\DTOs\LedgerEntry;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Domain\Wallet\Models\Withdrawal;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * Keuangan: penarikan, buku besar, rekonsiliasi.
 *
 * ============================================================================
 *  RE-AUTENTIKASI SEBELUM MENYETUJUI UANG KELUAR
 * ============================================================================
 *  Menyetujui penarikan menuntut kata sandi diketik ulang, walaupun sesinya
 *  masih aktif.
 *
 *  Alasannya bukan ketidakpercayaan pada stafnya, tapi pada komputernya:
 *  panel finance dibuka di komputer kantor yang ditinggalkan tidak terkunci
 *  saat orangnya makan siang. Sesi yang masih hidup berarti siapa pun yang lewat
 *  bisa menyetujui dua puluh penarikan dalam satu menit.
 *
 *  Kata sandi yang diketik ulang membuat tindakan itu menuntut kehadiran orang
 *  yang tahu kata sandinya, bukan hanya kursi yang kosong.
 * ============================================================================
 */
class FinanceController extends Controller
{
    /**
     * Antrean penarikan.
     */
    public function withdrawals(Request $request): View
    {
        $penarikan = Withdrawal::query()
            ->with(['wallet'])
            ->awaitingApproval()
            ->paginate(25);

        /*
         * Nama pemilik dompet dimuat terpisah, bukan lewat relasi.
         *
         * Dompet bersifat polimorfik (user / driver / merchant / platform), dan
         * relasi polimorfik dari `withdrawals` akan menghasilkan satu query per
         * baris. Pada dua puluh lima baris itu dua puluh lima query yang bisa
         * digabung menjadi tiga.
         */
        $pemilik = $this->muatPemilikDompet($penarikan->pluck('wallet')->filter());

        return view('backend.finance.withdrawals', [
            'penarikan' => $penarikan,
            'pemilik' => $pemilik,
            'statistik' => $this->statistikPenarikan(),
        ]);
    }

    /**
     * Setujui satu penarikan.
     */
    public function approveWithdrawal(Request $request, string $uuid): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'password.required' => 'Ketik ulang kata sandi Anda untuk menyetujui.',
        ]);

        $admin = auth('admin')->user();

        if (! Hash::check((string) $request->input('password'), (string) $admin->password)) {
            /*
             * Upaya yang gagal DICATAT.
             *
             * Kata sandi salah saat menyetujui penarikan bisa berarti dua hal:
             * staf yang salah ketik, atau orang lain yang memakai sesinya. Yang
             * kedua adalah satu-satunya alasan re-autentikasi ini ada, dan tanpa
             * catatan tidak ada cara mengetahui bahwa itu terjadi.
             */
            AuditLog::record(
                action: 'finance.reauth_failed',
                auditable: $admin,
                newValues: ['for' => 'withdrawal_approval', 'withdrawal_uuid' => $uuid],
            );

            return back()->withErrors(['password' => 'Kata sandi salah.']);
        }

        $penarikan = Withdrawal::query()->where('uuid', $uuid)->firstOrFail();

        if ($penarikan->isApproved()) {
            return back()->with('info', 'Penarikan ini sudah disetujui sebelumnya.');
        }

        $sebelum = $penarikan->only(['status', 'approved_by_admin_id', 'approved_at']);

        DB::transaction(function () use ($penarikan, $admin): void {
            $penarikan->forceFill([
                'status' => 'approved',
                'approved_by_admin_id' => $admin->getKey(),
                'approved_at' => now(),
            ])->save();

            /*
             * Pemotongan saldo TIDAK dilakukan di sini.
             *
             * Saldo dipotong saat penarikan DIAJUKAN, bukan saat disetujui. Kalau
             * dipotong saat disetujui, driver bisa mengajukan sepuluh penarikan
             * dari saldo yang cukup untuk satu, dan kesepuluhnya menunggu
             * persetujuan — lalu semuanya disetujui, dan saldonya minus.
             *
             * Yang terjadi di sini hanya perubahan status dan pencatatan. Uang
             * benar-benar dikirim job disbursement setelah ini.
             */
        });

        AuditLog::record(
            action: 'finance.withdrawal_approved',
            auditable: $penarikan,
            oldValues: $sebelum,
            newValues: $penarikan->only(['status', 'approved_by_admin_id', 'approved_at'])
                + ['note' => $request->input('note')],
        );

        return back()->with('success', 'Penarikan '.$penarikan->netAmount()->format().' disetujui.');
    }

    /**
     * Tolak satu penarikan.
     */
    public function rejectWithdrawal(Request $request, string $uuid): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'string'],
            'reason' => ['required', 'string', 'min:20', 'max:500'],
        ], [
            'password.required' => 'Ketik ulang kata sandi Anda.',
            'reason.required' => 'Jelaskan alasan penolakan.',
            'reason.min' => 'Alasannya dikirim ke driver dan harus bisa dia tindaklanjuti '
                .'(minimal 20 karakter).',
        ]);

        $admin = auth('admin')->user();

        if (! Hash::check((string) $request->input('password'), (string) $admin->password)) {
            AuditLog::record(
                action: 'finance.reauth_failed',
                auditable: $admin,
                newValues: ['for' => 'withdrawal_rejection', 'withdrawal_uuid' => $uuid],
            );

            return back()->withErrors(['password' => 'Kata sandi salah.']);
        }

        $penarikan = Withdrawal::query()->where('uuid', $uuid)->firstOrFail();

        $sebelum = $penarikan->only(['status', 'failure_reason']);

        $penarikan->forceFill([
            'status' => 'rejected',
            'failure_reason' => (string) $request->input('reason'),
            'approved_by_admin_id' => $admin->getKey(),
            'approved_at' => now(),
        ])->save();

        AuditLog::record(
            action: 'finance.withdrawal_rejected',
            auditable: $penarikan,
            oldValues: $sebelum,
            newValues: $penarikan->only(['status', 'failure_reason']),
        );

        /*
         * Saldo yang sudah dipotong HARUS dikembalikan.
         *
         * Ini bagian yang paling mudah lupa: penarikan yang ditolak tanpa
         * pengembalian saldo berarti driver kehilangan uangnya tanpa pernah
         * menerimanya di rekening. Tidak ada error, tidak ada jejak, dan yang
         * menemukannya adalah driver yang menghitung saldonya sendiri.
         *
         * Jalur pengembaliannya lewat ledger, bukan UPDATE saldo — sama seperti
         * seluruh perpindahan uang lain di sistem ini.
         */
        $this->kembalikanSaldoPenarikan($penarikan);

        return back()->with('success', 'Penarikan ditolak dan saldo dikembalikan.');
    }

    /**
     * Buku besar.
     */
    public function ledger(Request $request): View
    {
        $query = WalletTransaction::query()
            ->with(['wallet'])
            ->latest('created_at');

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        if ($request->filled('group_uuid')) {
            /*
             * Pencarian per group_uuid adalah yang paling berguna di halaman ini.
             *
             * Satu peristiwa keuangan menghasilkan beberapa baris yang harus
             * berjumlah nol. Melihatnya satu per satu tidak memberi tahu apa pun;
             * yang menjelaskan adalah SATU KELOMPOK utuh.
             */
            $query->where('group_uuid', $request->string('group_uuid'));
        }

        if ($request->filled('wallet_id')) {
            $query->where('wallet_id', $request->integer('wallet_id'));
        }

        $transaksi = $query->paginate(50)->withQueryString();

        return view('backend.finance.ledger', [
            'transaksi' => $transaksi,
            'saldoPlatform' => $this->saldoPlatform(),
        ]);
    }

    /**
     * Rekonsiliasi: apakah cache saldo cocok dengan jumlah ledger.
     *
     * ========================================================================
     *  INI SATU-SATUNYA HALAMAN YANG MEMBUKTIKAN PEMBUKUANNYA BENAR
     * ========================================================================
     *  `wallets.balance` adalah cache dari akumulasi `wallet_transactions`.
     *  Selama jalur penulisannya benar, keduanya selalu sama — dan trigger
     *  jumlah-nol plus CHECK aritmetika membuat itu sangat sulit dilanggar.
     *
     *  "Sangat sulit" bukan "mustahil". Kalau pernah ada UPDATE langsung lewat
     *  psql, atau bug pada jalur baru yang belum diuji, selisihnya hanya bisa
     *  ditemukan dengan membandingkan keduanya. Halaman ini yang melakukannya.
     *
     *  Dijalankan atas permintaan, bukan otomatis: query-nya memindai seluruh
     *  ledger, dan itu tidak boleh terjadi setiap kali panel dibuka.
     * ========================================================================
     */
    public function reconciliation(): View
    {
        $selisih = DB::select("
            SELECT
                w.id,
                w.owner_type,
                w.owner_id,
                w.balance AS cache_balance,
                COALESCE(SUM(
                    CASE
                        WHEN t.type IN ('hold', 'release') THEN 0
                        WHEN t.direction = 'credit' THEN t.amount
                        ELSE -t.amount
                    END
                ), 0) AS ledger_balance
            FROM wallets w
            LEFT JOIN wallet_transactions t ON t.wallet_id = w.id
            GROUP BY w.id, w.owner_type, w.owner_id, w.balance
            HAVING w.balance <> COALESCE(SUM(
                CASE
                    WHEN t.type IN ('hold', 'release') THEN 0
                    WHEN t.direction = 'credit' THEN t.amount
                    ELSE -t.amount
                END
            ), 0)
            ORDER BY ABS(w.balance - COALESCE(SUM(
                CASE
                    WHEN t.type IN ('hold', 'release') THEN 0
                    WHEN t.direction = 'credit' THEN t.amount
                    ELSE -t.amount
                END
            ), 0)) DESC
            LIMIT 100
        ");

        /*
         * Jumlah SELURUH saldo harus nol.
         *
         * Konsekuensi aritmetika dari pembukuan berpasangan tertutup: setiap
         * peristiwa berjumlah nol, jadi jumlah seluruh saldo adalah invarian, dan
         * karena semua dompet lahir di nol, invarian itu nol selamanya.
         *
         * Angka yang bukan nol di sini berarti ada uang yang muncul atau hilang
         * dari sistem tanpa pasangan — dan itu bukan sesuatu yang bisa diabaikan.
         */
        $jumlahSeluruhSaldo = (int) DB::table('wallets')->sum('balance');

        return view('backend.finance.reconciliation', [
            'selisih' => $selisih,
            'jumlahSeluruhSaldo' => Money::of($jumlahSeluruhSaldo),
            'seimbang' => $jumlahSeluruhSaldo === 0 && $selisih === [],
        ]);
    }

    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function statistikPenarikan(): array
    {
        $baris = DB::table('withdrawals')
            ->selectRaw("
                COUNT(*) FILTER (WHERE status IN ('requested','reviewing')) AS menunggu,
                COALESCE(SUM(net_amount) FILTER (WHERE status IN ('requested','reviewing')), 0) AS nilai_menunggu,
                COUNT(*) FILTER (WHERE status IN ('approved','processing')) AS diproses,
                MIN(created_at) FILTER (WHERE status IN ('requested','reviewing')) AS tertua
            ")
            ->first();

        return [
            'menunggu' => (int) ($baris->menunggu ?? 0),
            'nilai_menunggu' => Money::of((int) ($baris->nilai_menunggu ?? 0)),
            'diproses' => (int) ($baris->diproses ?? 0),
            'tertua' => $baris->tertua === null
                ? null
                : Carbon::parse($baris->tertua),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function saldoPlatform(): array
    {
        return DB::table('wallets')
            ->where('owner_type', 'platform')
            ->orderBy('owner_id')
            ->get(['owner_id', 'balance'])
            ->map(fn ($w): array => [
                'nama' => $this->labelPlatform((int) $w->owner_id),
                'saldo' => Money::of((int) $w->balance),

                /*
                 * Akun kontra platform memang bersaldo negatif, dan itu
                 * DIJELASKAN di sini.
                 *
                 * Tanpa penjelasan, staf finance yang membuka halaman ini akan
                 * melihat saldo minus ratusan juta dan menyimpulkan ada yang
                 * rusak. Yang benar: angka minus di akun settlement berarti
                 * sebanyak itu dana pengguna yang dititipkan platform.
                 */
                'wajar_negatif' => in_array(
                    (int) $w->owner_id,
                    [
                        Wallet::PLATFORM_SETTLEMENT,
                        Wallet::PLATFORM_PROMO_COST,
                        Wallet::PLATFORM_INCENTIVE_COST,
                        Wallet::PLATFORM_REFUND_COST,
                    ],
                    true,
                ),
            ])
            ->all();
    }

    /**
     * Nama akun platform yang bisa dibaca staf.
     *
     * Ditulis di sini, bukan di model Wallet, karena ini murni soal penyajian
     * di panel. Model Wallet tidak perlu tahu bagaimana angka 2 ditampilkan ke
     * manusia.
     */
    private function labelPlatform(int $ownerId): string
    {
        return match ($ownerId) {
            Wallet::PLATFORM_REVENUE => 'Pendapatan komisi',
            Wallet::PLATFORM_SETTLEMENT => 'Settlement gateway',
            Wallet::PLATFORM_PROMO_COST => 'Beban promo',
            Wallet::PLATFORM_INCENTIVE_COST => 'Beban insentif',
            Wallet::PLATFORM_REFUND_COST => 'Beban refund',
            default => "Akun platform #{$ownerId}",
        };
    }

    /**
     * @param  Collection<int, Wallet>  $dompet
     * @return array<int, string>
     */
    private function muatPemilikDompet(Collection $dompet): array
    {
        $hasil = [];
        $perJenis = $dompet->groupBy('owner_type');

        foreach ($perJenis as $jenis => $kumpulan) {
            $ids = $kumpulan->pluck('owner_id')->unique()->all();

            $nama = match ($jenis) {
                'driver' => DB::table('drivers')->whereIn('id', $ids)->pluck('full_name', 'id'),
                'user' => DB::table('users')->whereIn('id', $ids)->pluck('name', 'id'),
                'merchant' => DB::table('merchants')->whereIn('id', $ids)->pluck('name', 'id'),
                default => collect(),
            };

            foreach ($kumpulan as $w) {
                $hasil[(int) $w->getKey()] = (string) ($nama[$w->owner_id] ?? '—');
            }
        }

        return $hasil;
    }

    /**
     * Kembalikan saldo untuk penarikan yang ditolak.
     */
    private function kembalikanSaldoPenarikan(Withdrawal $penarikan): void
    {
        $dompet = $penarikan->wallet;

        if ($dompet === null) {
            return;
        }

        /*
         * Idempoten lewat pemeriksaan ledger, bukan lewat flag.
         *
         * Kalau tombol tolak ditekan dua kali — yang terjadi saat responsnya
         * lambat — pengembalian kedua akan menggandakan saldo driver. Memeriksa
         * apakah baris reversal-nya sudah ada menutup itu tanpa perlu kolom
         * tambahan.
         */
        $sudahDikembalikan = WalletTransaction::query()
            ->where('reference_type', 'withdrawal')
            ->where('reference_id', $penarikan->getKey())
            ->where('type', 'reversal')
            ->exists();

        if ($sudahDikembalikan) {
            return;
        }

        app(PostLedgerEntries::class)->handle([
            LedgerEntry::credit(
                walletId: (int) $dompet->getKey(),
                type: 'reversal',
                amount: $penarikan->amount(),
                referenceType: 'withdrawal',
                referenceId: (int) $penarikan->getKey(),
                description: 'Penarikan ditolak, saldo dikembalikan',
            ),
            LedgerEntry::debit(
                walletId: (int) Wallet::platform(Wallet::PLATFORM_SETTLEMENT)->getKey(),
                type: 'reversal',
                amount: $penarikan->amount(),
                referenceType: 'withdrawal',
                referenceId: (int) $penarikan->getKey(),
                description: 'Penarikan ditolak, saldo dikembalikan',
            ),
        ]);
    }
}
