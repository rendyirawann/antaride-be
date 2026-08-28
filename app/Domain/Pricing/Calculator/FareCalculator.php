<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Calculator;

use App\Domain\Catalog\Models\PricingRule;
use App\Domain\Pricing\DTOs\FareBreakdown;
use App\Domain\Pricing\DTOs\RouteResult;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Shared\ValueObjects\RoundingMode;

/**
 * Menghitung ongkos dari rute dan aturan tarif.
 *
 * ============================================================================
 *  URUTAN OPERASI, DAN KENAPA URUTANNYA ITU
 * ============================================================================
 *
 *   1. tarif angkutan  = base + (per_km x jarak berbayar) + (per_menit x waktu)
 *   2. surge           = tarif angkutan x pengali
 *   3. minimum bisnis  = dinaikkan kalau di bawah minimum_fare
 *   4. batas regulasi  = diklem ke [min_regulated, max_regulated]
 *   5. biaya aplikasi  = + platform_fee + service_fee
 *   6. diskon          = - potongan promo
 *   7. komisi          = persentase dari tarif angkutan pasca-klem
 *   8. pendapatan driver = tarif angkutan pasca-klem - komisi
 *
 * Yang paling mudah salah adalah urutan 3 dan 4. Kalau batas regulasi
 * diterapkan SEBELUM minimum bisnis, tarif minimum bisa mendorong angka di atas
 * batas atas Kemenhub. Kalau surge diterapkan SETELAH klem, pengali jam sibuk
 * bisa melompati batas atas itu juga. Karena itu klem regulasi harus paling
 * akhir di antara keduanya.
 *
 * Yang juga penting: biaya aplikasi (langkah 5) ditambahkan DI LUAR klem
 * regulasi. Alasannya, yang diatur Kemenhub adalah tarif angkutan, bukan biaya
 * jasa aplikasi. Penafsiran ini perlu dikonfirmasi ke konsultan hukum sebelum
 * go-live; kalau ternyata regulasinya mencakup total yang dibayar penumpang,
 * yang perlu diubah hanya urutan di method calculate() dan test yang
 * menguncinya.
 *
 * ============================================================================
 *  KOMISI DAN BIAYA APLIKASI ADALAH DUA HAL BERBEDA
 * ============================================================================
 *
 *   platform_fee        ditambahkan di atas tarif, dibayar penumpang
 *   commission_percent  dipotong dari bagian driver
 *
 * Keduanya masuk ke platform, tapi dari kantong yang berbeda, dan
 * mencampurnya berarti laporan pendapatan tidak bisa dijelaskan. Contoh dengan
 * tarif Rp 24.000, platform_fee Rp 1.000, komisi 20%:
 *
 *   penumpang bayar   25.000
 *   driver terima     19.200   (24.000 - 20%)
 *   platform terima    5.800   (komisi 4.800 + biaya aplikasi 1.000)
 *                     ------
 *                     25.000   utuh
 *
 * ============================================================================
 *
 * Seluruh perhitungan memakai Money, jadi tidak ada tahap yang melewati float.
 */
class FareCalculator
{
    /**
     * @param  string  $surgeMultiplier  pengali sebagai string, misal "1.30"
     */
    public function calculate(
        RouteResult $route,
        PricingRule $rule,
        string $surgeMultiplier = '1.00',
        ?Money $discount = null,
        bool $applyPackagingFee = false,
        bool $applyInsuranceFee = false,
    ): FareBreakdown {
        $discount ??= Money::zero();

        // --- 1. Tarif angkutan ---

        $baseFare = $rule->baseFare();
        $distanceFare = $this->distanceFare($route, $rule);
        $timeFare = $this->timeFare($route, $rule);

        $transportFare = $baseFare->plus($distanceFare)->plus($timeFare);

        // --- 2. Surge ---

        $fareWithSurge = $transportFare->scaledBy($surgeMultiplier, RoundingMode::Ceiling);
        $surgeAmount = $fareWithSurge->minus($transportFare);

        // --- 3. Minimum bisnis ---

        $minimumFare = $rule->minimumFare();
        $raisedToMinimum = $fareWithSurge->isLessThan($minimumFare);
        $afterMinimum = $raisedToMinimum ? $minimumFare : $fareWithSurge;

        // --- 4. Batas regulasi Kemenhub ---

        $minRegulated = $rule->minFareRegulated();
        $maxRegulated = $rule->maxFareRegulated();
        $afterRegulation = $afterMinimum->clamp($minRegulated, $maxRegulated);
        $clamped = ! $afterRegulation->equals($afterMinimum);

        // Penyesuaian sebagai satu baris eksplisit, bukan dengan menskala turun
        // komponen lain.
        //
        // Batas atas bisa memotong ongkos sampai di BAWAH jumlah komponennya
        // sendiri: rute 50 km menghasilkan biaya jarak Rp 105.600 sementara
        // batas atasnya Rp 100.000. Menyetel ulang bagian surge tidak bisa
        // menutup selisih itu, karena surge-nya sudah nol pun jumlahnya masih
        // melebihi batas.
        //
        // Selisihnya dicatat di kolom sendiri supaya rincian SELALU menjumlah
        // ke total, dan supaya biaya per km yang ditampilkan tetap cocok dengan
        // tarif resmi yang dipublikasikan. Pemotongan karena regulasi memang
        // perlu terlihat, bukan disembunyikan di dalam angka lain.
        $regulatoryAdjustment = $afterRegulation->minus($fareWithSurge);

        // --- 5. Biaya aplikasi ---

        $platformFee = $rule->platformFee();
        $serviceFee = $this->serviceFee($rule, $applyPackagingFee, $applyInsuranceFee);

        $beforeDiscount = $afterRegulation->plus($platformFee)->plus($serviceFee);

        // --- 6. Diskon ---

        // Diskon tidak boleh melebihi yang harus dibayar. Promo Rp 20.000 pada
        // ongkos Rp 12.000 menghasilkan ongkos nol, bukan minus, dan sisanya
        // TIDAK menjadi utang platform ke penumpang.
        $effectiveDiscount = $discount->isGreaterThan($beforeDiscount)
            ? $beforeDiscount
            : $discount;

        $total = $beforeDiscount->minus($effectiveDiscount);

        // --- 7 & 8. Komisi dan pendapatan driver ---

        // Dihitung dari tarif angkutan pasca-klem, BUKAN dari total yang dibayar
        // penumpang. Kalau dari total, driver akan ikut menanggung biaya
        // aplikasi yang bukan bagiannya, dan ikut kehilangan pendapatan saat
        // platform memberi promo.
        $commission = $afterRegulation->percentage(
            (string) $rule->commission_percent,
            RoundingMode::Floor,
        );

        $driverEarning = $afterRegulation->minus($commission);

        return new FareBreakdown(
            baseFare: $baseFare,
            distanceFare: $distanceFare,
            timeFare: $timeFare,
            surgeMultiplier: $this->normalizeMultiplier($surgeMultiplier),
            surgeAmount: $surgeAmount,
            regulatoryAdjustment: $regulatoryAdjustment,
            platformFee: $platformFee,
            serviceFee: $serviceFee,
            discount: $effectiveDiscount,
            total: $total,
            driverEarning: $driverEarning,
            commission: $commission,
            clampedToRegulation: $clamped,
            raisedToMinimum: $raisedToMinimum,
        );
    }

    // -------------------------------------------------------------------------

    /**
     * Biaya jarak, setelah jarak gratis dikurangi.
     *
     * `free_distance_m` adalah jarak yang sudah tercakup tarif buka pintu.
     * Tanpa pengurangan ini, kilometer pertama ditagih dua kali: sekali di
     * base_fare dan sekali di per_km.
     *
     * Dibulatkan ke atas per kilometer? TIDAK. Yang dipakai adalah proporsi
     * meter sebenarnya, karena membulatkan 2.100 m menjadi 3 km menagih 43%
     * lebih dari jarak tempuh, dan itu keluhan yang wajar.
     */
    private function distanceFare(RouteResult $route, PricingRule $rule): Money
    {
        $chargeableMeters = max(0, $route->distanceMeters - $rule->free_distance_m);

        if ($chargeableMeters === 0) {
            return Money::zero();
        }

        // Proporsi kilometer sebagai string, supaya perkaliannya tetap di
        // bcmath dan tidak melewati float.
        $kilometers = bcdiv((string) $chargeableMeters, '1000', 6);

        return $rule->perKm()->scaledBy($kilometers, RoundingMode::Ceiling);
    }

    /**
     * Biaya waktu tempuh.
     *
     * Memakai durasi estimasi dari OSRM, bukan waktu perjalanan sebenarnya.
     * Ini disengaja: ongkos dibekukan saat order dibuat, jadi penumpang tahu
     * berapa yang dibayar sebelum berangkat. Perjalanan yang lebih lama karena
     * macet TIDAK menambah ongkos, dan itu keputusan bisnis yang membedakan
     * layanan ini dari taksi meter.
     */
    private function timeFare(RouteResult $route, PricingRule $rule): Money
    {
        if ($rule->per_minute === 0) {
            return Money::zero();
        }

        // Dibulatkan ke atas per menit. Perjalanan 12 menit 10 detik ditagih
        // 13 menit, dan selisihnya kecil serta konsisten.
        $minutes = (int) ceil($route->durationSeconds / 60);

        return $rule->perMinute()->multipliedBy($minutes);
    }

    /**
     * Biaya tambahan khusus vertikal: kemasan untuk makanan, asuransi untuk
     * barang.
     */
    private function serviceFee(
        PricingRule $rule,
        bool $applyPackagingFee,
        bool $applyInsuranceFee,
    ): Money {
        $fee = Money::zero();

        if ($applyPackagingFee) {
            $fee = $fee->plus(Money::of($rule->packaging_fee));
        }

        if ($applyInsuranceFee) {
            $fee = $fee->plus(Money::of($rule->insurance_fee));
        }

        return $fee;
    }

    /**
     * Normalkan pengali ke dua desimal, supaya nilai yang disimpan di kolom
     * `surge_multiplier` selalu berbentuk sama.
     */
    private function normalizeMultiplier(string $multiplier): string
    {
        return number_format((float) $multiplier, 2, '.', '');
    }
}
