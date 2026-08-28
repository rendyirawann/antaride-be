<?php

declare(strict_types=1);

namespace App\Domain\Approval\Actions;

use App\Domain\Approval\DTOs\ApprovalThreshold;
use Illuminate\Support\Facades\DB;

/**
 * Menentukan berapa penyetuju yang dibutuhkan untuk sebuah nominal.
 *
 * ============================================================================
 *  TIGA HAL YANG PERNAH SALAH DI SINI, SEMUANYA TENTANG UANG BESAR
 * ============================================================================
 *
 *  1. BATAS ATAS INKLUSIF, PADAHAL TABELNYA EKSKLUSIF
 *
 *     Versi lama memakai `$amount <= $max`, sementara constraint
 *     `approval_thresholds_no_overlap` memakai `int8range(min, max, '[)')` —
 *     batas atas EKSKLUSIF. Akibatnya penarikan tepat Rp 500.000 dianggap
 *     masuk baris "< 500rb otomatis" dan cair TANPA satu pun penyetuju.
 *
 *     Nominal bulat justru yang paling sering muncul, dan siapa pun yang
 *     mencari batas sistem akan mencobanya lebih dulu.
 *
 *  2. MEMBACA CONFIG, BUKAN TABELNYA
 *
 *     Komentar di `config/antaride.php` menjanjikan tabel `approval_thresholds`
 *     sebagai sumber kebenaran supaya tim finance bisa mengubah ambang tanpa
 *     deploy. Kodenya tidak pernah menyentuh tabel itu. Tim finance mengubah
 *     angkanya di panel, melihat perubahannya tersimpan, dan penarikan tetap
 *     memakai angka lama — tanpa error apa pun.
 *
 *  3. URUTAN ARRAY MENENTUKAN HASIL
 *
 *     Karena hanya `max` yang diperiksa, hasilnya bergantung pada urutan baris.
 *     Menukar dua baris di config akan mengubah kebijakan approval seluruh
 *     platform, dan diff-nya terlihat seperti sekadar penataan ulang.
 *     Sekarang `min_amount` ikut diperiksa, jadi urutannya tidak berpengaruh.
 * ============================================================================
 */
class ResolveApprovalThreshold
{
    /**
     * @param  string  $type  withdrawal, balance_adjustment, pricing_change, ...
     */
    public function handle(string $type, int $amount): ApprovalThreshold
    {
        return $this->fromDatabase($type, $amount)
            ?? $this->fromConfig($type, $amount);
    }

    // -------------------------------------------------------------------------

    private function fromDatabase(string $type, int $amount): ?ApprovalThreshold
    {
        /*
         * Batas atas EKSKLUSIF, sama persis dengan int8range(min, max, '[)')
         * yang menjaga tabel ini dari tumpang tindih. Kalau keduanya berbeda,
         * ada nominal yang jatuh di antara dua aturan atau tidak masuk aturan
         * mana pun — dan keduanya baru terlihat saat ada nominal yang tepat di
         * batas.
         */
        $row = DB::table('approval_thresholds')
            ->where('type', $type)
            ->where('is_active', true)
            ->where('min_amount', '<=', $amount)
            ->where(function ($query) use ($amount): void {
                $query->whereNull('max_amount')
                    ->orWhere('max_amount', '>', $amount);
            })
            ->first();

        if ($row === null) {
            return null;
        }

        return new ApprovalThreshold(
            requiredApprovers: (int) $row->required_approvers,
            requiredRole: $row->required_role,
            minAmount: (int) $row->min_amount,
            maxAmount: $row->max_amount === null ? null : (int) $row->max_amount,
            fromDatabase: true,
        );
    }

    private function fromConfig(string $type, int $amount): ApprovalThreshold
    {
        $key = match ($type) {
            'withdrawal' => 'antaride.approval.withdrawal_thresholds',
            default => null,
        };

        $rows = $key === null ? [] : config($key, []);

        foreach ($rows as $row) {
            $min = (int) ($row['min'] ?? 0);
            $max = $row['max'] ?? null;

            if ($amount < $min) {
                continue;
            }

            if ($max !== null && $amount >= (int) $max) {
                continue;
            }

            return new ApprovalThreshold(
                requiredApprovers: (int) ($row['approvers'] ?? 1),
                requiredRole: $row['role'] ?? null,
                minAmount: $min,
                maxAmount: $max === null ? null : (int) $max,
                fromDatabase: false,
            );
        }

        /*
         * Tidak ada aturan yang cocok. Yang paling aman adalah menuntut
         * penyetuju paling banyak, bukan meloloskannya.
         *
         * Ini kelihatan defensif berlebihan sampai dipikirkan siapa yang
         * sampai ke sini: nominal yang tidak masuk aturan mana pun. Kalau
         * default-nya "otomatis", satu kesalahan konfigurasi ambang berarti
         * setiap penarikan sebesar apa pun cair tanpa review. Kalau
         * default-nya "butuh dua penyetuju", kesalahan yang sama hanya
         * berarti antrean approval menumpuk dan seseorang bertanya kenapa.
         */
        return new ApprovalThreshold(
            requiredApprovers: 2,
            requiredRole: 'super-admin',
            minAmount: $amount,
            maxAmount: null,
            fromDatabase: false,
        );
    }
}
