<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Actions;

use App\Domain\Pricing\DTOs\QuoteOption;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Shared\ValueObjects\RoundingMode;
use App\Domain\Support\Models\FeatureFlag;
use Illuminate\Support\Facades\DB;

/**
 * Mencari promo yang benar-benar bisa dipakai pengguna ini, untuk quote ini.
 *
 * Perhitungan diskonnya dilakukan di sini, per layanan, dan hasilnya disimpan
 * di dalam quote. Saat penumpang membuat order, nominal diskonnya DIBACA dari
 * quote, bukan dihitung ulang dan bukan dari aplikasi.
 *
 * Kenapa per layanan: promo "gratis ongkir maksimal Rp 10.000" memberi diskon
 * yang berbeda pada ride_bike Rp 16.440 dan ride_car Rp 34.450, dan promo
 * dengan `min_order` bisa berlaku untuk satu layanan tapi tidak untuk layanan
 * lain di quote yang sama.
 *
 * ============================================================================
 *  YANG TIDAK DIKERJAKAN DI SINI: RESERVASI KUOTA
 * ============================================================================
 *  Method ini hanya MEMERIKSA kuota, tidak menguncinya. Reservasi terjadi saat
 *  order dibuat, di dalam transaksi, dengan SELECT FOR UPDATE pada baris promo.
 *
 *  Konsekuensinya: promo berkuota 1 bisa muncul sebagai eligible di quote lima
 *  orang sekaligus, dan empat di antaranya akan gagal saat membuat order. Itu
 *  perilaku yang BENAR. Mengunci kuota saat quote dibuat berarti kuota habis
 *  dipegang orang yang hanya melihat harga lalu menutup aplikasi, dan promo
 *  berkuota 50 tidak akan pernah terpakai oleh 50 orang.
 * ============================================================================
 */
class FindEligiblePromos
{
    /**
     * @param  array<string, QuoteOption>  $options
     * @return array<int, array<string, mixed>>
     */
    public function handle(int $userId, int $zoneId, array $options): array
    {
        if (! $this->promoEnabled()) {
            return [];
        }

        $candidates = $this->candidatePromos($zoneId);

        if ($candidates === []) {
            return [];
        }

        $isNewUser = $this->isNewUser($userId);
        $usageCounts = $this->usageCountsFor($userId, array_column($candidates, 'id'));

        $eligible = [];

        foreach ($candidates as $promo) {
            if ($promo->new_user_only && ! $isNewUser) {
                continue;
            }

            // Kuota per pengguna.
            if ($promo->quota_per_user !== null
                && ($usageCounts[$promo->id] ?? 0) >= $promo->quota_per_user) {
                continue;
            }

            // Kuota total. Diperiksa dari kolom cache `used_count`; kebenarannya
            // ada di tabel promo_usages dan ditegakkan saat reservasi.
            if ($promo->quota_total !== null && $promo->used_count >= $promo->quota_total) {
                continue;
            }

            $discounts = $this->discountsPerService($promo, $options);

            // Promo yang tidak memberi diskon pada satu pun layanan tidak
            // ditampilkan. Menampilkannya lalu memberi potongan Rp 0 lebih
            // membingungkan daripada tidak menampilkannya.
            if ($discounts === []) {
                continue;
            }

            $eligible[] = [
                'id' => $promo->id,
                'code' => $promo->code,
                'title' => $promo->title,
                'type' => $promo->type,
                'discounts' => $discounts,
            ];
        }

        return $eligible;
    }

    // -------------------------------------------------------------------------

    /**
     * Promo yang aktif, dalam masa berlaku, dan berlaku untuk zona ini.
     *
     * Penyaringan zona memakai operator containment JSONB, jadi dilakukan
     * database dan memakai index GIN `promos_zone_gin`. Menyaringnya di PHP
     * berarti memuat semua promo aktif lalu membuang mayoritasnya.
     *
     * @return array<int, object>
     */
    private function candidatePromos(int $zoneId): array
    {
        return DB::table('promos')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            // zone_ids NULL berarti berlaku untuk semua zona.
            ->where(function ($query) use ($zoneId): void {
                $query->whereNull('zone_ids')
                    ->orWhereRaw('zone_ids @> ?::jsonb', [json_encode([$zoneId])]);
            })
            ->orderByDesc('value')
            ->get()
            ->all();
    }

    /**
     * Apakah pengguna ini belum pernah menyelesaikan order.
     *
     * Diperiksa dari order SELESAI, bukan dari tanggal pendaftaran. Akun yang
     * dibuat setahun lalu tapi belum pernah dipakai tetap pengguna baru untuk
     * keperluan promo, dan itu memang yang dimaksud "new user only".
     */
    private function isNewUser(int $userId): bool
    {
        return ! DB::table('orders')
            ->where('user_id', $userId)
            ->where('status', 'completed')
            ->exists();
    }

    /**
     * Berapa kali pengguna ini sudah memakai masing-masing promo.
     *
     * Satu query untuk semua promo, bukan satu per promo.
     *
     * @param  array<int, int>  $promoIds
     * @return array<int, int>
     */
    private function usageCountsFor(int $userId, array $promoIds): array
    {
        if ($promoIds === []) {
            return [];
        }

        return DB::table('promo_usages')
            ->where('user_id', $userId)
            ->whereIn('promo_id', $promoIds)
            ->groupBy('promo_id')
            ->selectRaw('promo_id, count(*) as total')
            ->pluck('total', 'promo_id')
            ->map(static fn ($total) => (int) $total)
            ->all();
    }

    /**
     * Diskon yang diberikan promo ini untuk setiap layanan di quote.
     *
     * @param  array<string, QuoteOption>  $options
     * @return array<string, int>
     */
    private function discountsPerService(object $promo, array $options): array
    {
        $serviceFilter = $promo->service_type_ids === null
            ? null
            : json_decode((string) $promo->service_type_ids, true);

        $discounts = [];

        foreach ($options as $serviceCode => $option) {
            if ($serviceFilter !== null && ! in_array($option->serviceTypeId, $serviceFilter, true)) {
                continue;
            }

            $total = $option->fare->total;

            // min_order dibandingkan dengan ongkos SEBELUM diskon.
            if ($total->amount < (int) $promo->min_order) {
                continue;
            }

            $discount = $this->computeDiscount($promo, $option);

            if ($discount->isZero()) {
                continue;
            }

            // Diskon tidak boleh melebihi ongkos. Ini juga ditegakkan
            // FareCalculator, tapi diklem di sini juga supaya angka yang
            // ditampilkan di daftar promo sama dengan yang benar-benar dipotong.
            // Menampilkan "potongan Rp 20.000" lalu memotong Rp 12.000 adalah
            // keluhan yang wajar.
            $discounts[$serviceCode] = min($discount->amount, $total->amount);
        }

        return $discounts;
    }

    private function computeDiscount(object $promo, QuoteOption $option): Money
    {
        $total = $option->fare->total;

        return match ($promo->type) {
            'percent' => $this->percentDiscount($promo, $total),

            'fixed' => Money::of((int) $promo->value),

            // Gratis ongkir memotong tarif angkutan, bukan seluruh total.
            // Biaya aplikasi tetap ditagih, karena yang digratiskan adalah
            // ongkos kirimnya.
            'free_delivery' => $this->cappedDiscount(
                $promo,
                $option->fare->transportFare(),
            ),

            // Cashback tidak memotong yang dibayar sekarang; dia masuk saldo
            // setelah order selesai. Karena itu diskonnya nol di sini.
            //
            // Kalau cashback diperlakukan sebagai potongan, penumpang membayar
            // lebih sedikit DAN mendapat saldo, jadi platform menanggung dua
            // kali.
            'cashback' => Money::zero(),

            default => Money::zero(),
        };
    }

    private function percentDiscount(object $promo, Money $total): Money
    {
        // Pembulatan ke bawah, memihak platform.
        //
        // Ini arah yang berbeda dari komisi, dan itu disengaja: pada komisi,
        // sisa rupiah jatuh ke driver; pada diskon, sisa rupiah tidak dipotong.
        // Prinsipnya sama di keduanya, yaitu pihak yang lebih lemah posisinya
        // tidak menanggung selisih pembulatan.
        $raw = $total->percentage((string) $promo->value, RoundingMode::Floor);

        return $this->applyCap($promo, $raw);
    }

    private function cappedDiscount(object $promo, Money $base): Money
    {
        return $this->applyCap($promo, $base);
    }

    private function applyCap(object $promo, Money $discount): Money
    {
        if ($promo->max_discount === null) {
            return $discount;
        }

        $cap = Money::of((int) $promo->max_discount);

        return $discount->isGreaterThan($cap) ? $cap : $discount;
    }

    /**
     * Default TRUE: promo yang tidak berlaku hanya membuat harga penuh, dan itu
     * tidak merusak apa pun.
     *
     * Pola cache-nya ada di FeatureFlag, bukan ditulis ulang di sini — itu yang
     * membuat panel admin bisa membatalkan cache-nya saat flag diubah.
     */
    private function promoEnabled(): bool
    {
        return FeatureFlag::isEnabled('promo.enabled', default: true);
    }
}
