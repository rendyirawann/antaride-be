<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Domain\Shared\Exceptions\InvalidMoneyException;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Shared\ValueObjects\RoundingMode;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ============================================================================
 *  KENAPA TEST INI SEBAGIAN BESAR TENTANG NILAI NEGATIF
 * ============================================================================
 *  Money adalah primitif yang dipakai setiap perhitungan uang di sistem ini,
 *  dan sebelum test ini ada, dia tidak punya satu pun unit test sendiri.
 *  Kebenarannya hanya teruji secara tidak langsung lewat FareCalculatorTest —
 *  yang seluruh datanya positif.
 *
 *  Akibatnya seluruh jalur negatif tidak pernah dijalankan, dan di situlah dua
 *  bug bersembunyi: bcmath dan intdiv sama-sama memotong ke arah NOL, bukan ke
 *  bawah. Selama tidak ada nilai negatif, keduanya identik dan tidak ada yang
 *  terlihat salah.
 *
 *  Nilai negatif berhenti menjadi kasus teoretis sejak `regulatory_adjustment`
 *  ada: kolom itu mencatat pemotongan karena tarif maksimum yang diatur
 *  pemerintah, dan dia SELALU negatif atau nol.
 * ============================================================================
 */
class MoneyTest extends TestCase
{
    // =========================================================================
    //  Pembulatan
    // =========================================================================

    /**
     * Nominalnya yang bertanda, BUKAN pengalinya.
     *
     * `scaledBy()` sengaja menolak pengali negatif — surge dan diskon tidak
     * pernah negatif, dan menerimanya berarti membuka jalur yang tidak punya
     * arti bisnis. Jadi nilai negatif diuji dengan cara yang memang terjadi di
     * produksi: nominal negatif dikali pengali positif.
     *
     * @return array<string, array{int, string, RoundingMode, int}>
     */
    public static function pembulatanCases(): array
    {
        return [
            // --- Floor: ke BAWAH, bukan ke arah nol ---
            'floor positif berpecahan' => [1, '3.7', RoundingMode::Floor, 3],
            'floor positif bulat' => [1, '3.0', RoundingMode::Floor, 3],
            'floor NEGATIF berpecahan' => [-1, '3.7', RoundingMode::Floor, -4],
            'floor NEGATIF bulat' => [-1, '3.0', RoundingMode::Floor, -3],
            'floor NEGATIF hampir nol' => [-1, '0.1', RoundingMode::Floor, -1],

            // --- Ceiling: ke ATAS ---
            'ceiling positif berpecahan' => [1, '3.2', RoundingMode::Ceiling, 4],
            'ceiling positif bulat' => [1, '3.0', RoundingMode::Ceiling, 3],
            'ceiling NEGATIF berpecahan' => [-1, '3.2', RoundingMode::Ceiling, -3],
            'ceiling NEGATIF bulat' => [-1, '3.0', RoundingMode::Ceiling, -3],
            'ceiling positif hampir nol' => [1, '0.1', RoundingMode::Ceiling, 1],

            // --- HalfUp: setengah MENJAUH dari nol ---
            'halfup positif bawah setengah' => [1, '3.4', RoundingMode::HalfUp, 3],
            'halfup positif tepat setengah' => [1, '3.5', RoundingMode::HalfUp, 4],
            'halfup positif atas setengah' => [1, '3.6', RoundingMode::HalfUp, 4],
            'halfup NEGATIF bawah setengah' => [-1, '3.4', RoundingMode::HalfUp, -3],
            'halfup NEGATIF tepat setengah' => [-1, '3.5', RoundingMode::HalfUp, -4],
            'halfup NEGATIF atas setengah' => [-1, '3.6', RoundingMode::HalfUp, -4],

            // Kasus yang paling menunjukkan bug lamanya: nilai negatif yang
            // BULAT ikut berubah, padahal tidak ada apa pun untuk dibulatkan.
            // -1 x 1,0 dulu menghasilkan 0 — satu rupiah hilang begitu saja.
            'halfup NEGATIF bulat tidak boleh berubah' => [-1, '1.0', RoundingMode::HalfUp, -1],
            'halfup NEGATIF bulat besar' => [-9999, '1.0', RoundingMode::HalfUp, -9999],
            'halfup nol' => [0, '3.5', RoundingMode::HalfUp, 0],

            // Nilai nyata: pemotongan regulasi Rp 5.600 dikali 1,00.
            'penyesuaian regulasi utuh' => [-5600, '1.00', RoundingMode::HalfUp, -5600],
        ];
    }

    #[DataProvider('pembulatanCases')]
    public function test_pembulatan_benar_untuk_nilai_positif_dan_negatif(
        int $amount,
        string $multiplier,
        RoundingMode $mode,
        int $expected,
    ): void {
        // scaledBy dipakai sebagai pintu masuk ke roundString, yang private.
        $result = Money::of($amount)->scaledBy($multiplier, $mode);

        $this->assertSame(
            $expected,
            $result->amount,
            "Rp {$amount} dikali {$multiplier} dengan {$mode->name} seharusnya {$expected}, dapat {$result->amount}."
        );
    }

    public function test_pengali_satu_koma_nol_tidak_pernah_mengubah_nilai(): void
    {
        // Ini invariant yang paling mudah dilanggar tanpa disadari, dan yang
        // paling merusak kepercayaan: mengalikan dengan 1,00 harus menghasilkan
        // nilai yang sama persis, untuk SETIAP mode dan SETIAP tanda.
        foreach ([RoundingMode::Floor, RoundingMode::Ceiling, RoundingMode::HalfUp] as $mode) {
            foreach ([-9999, -100, -1, 0, 1, 100, 9999] as $amount) {
                $result = Money::of($amount)->scaledBy('1.00', $mode);

                $this->assertSame(
                    $amount,
                    $result->amount,
                    "Rp {$amount} dikali 1,00 dengan {$mode->name} berubah menjadi {$result->amount}."
                );
            }
        }
    }

    // =========================================================================
    //  allocate()
    // =========================================================================

    /**
     * @return array<string, array{int, int}>
     */
    public static function alokasiCases(): array
    {
        return [
            'positif habis dibagi' => [9000, 3],
            'positif bersisa satu' => [10, 3],
            'positif bersisa dua' => [11, 3],
            'positif satu bagian' => [7777, 1],
            'nol' => [0, 4],

            // Inilah yang dulu kehilangan satu rupiah.
            'NEGATIF bersisa satu' => [-10, 3],
            'NEGATIF bersisa dua' => [-11, 3],
            'NEGATIF habis dibagi' => [-9000, 3],
            'NEGATIF satu bagian' => [-5600, 1],
            'NEGATIF banyak bagian' => [-7, 5],
        ];
    }

    #[DataProvider('alokasiCases')]
    public function test_alokasi_tidak_pernah_kehilangan_satu_rupiah(int $amount, int $parts): void
    {
        $allocated = Money::of($amount)->allocate($parts);

        $this->assertCount($parts, $allocated);

        $sum = array_sum(array_map(static fn (Money $m): int => $m->amount, $allocated));

        $this->assertSame(
            $amount,
            $sum,
            "Rp {$amount} dibagi {$parts} menghasilkan jumlah Rp {$sum}. Selisihnya hilang."
        );
    }

    public function test_alokasi_negatif_memberi_sisa_ke_bagian_pertama(): void
    {
        $allocated = Money::of(-10)->allocate(3);

        $this->assertSame(
            [-4, -3, -3],
            array_map(static fn (Money $m): int => $m->amount, $allocated),
            'Sisa satu rupiah harus jatuh ke bagian pertama, dengan tanda yang benar.'
        );
    }

    public function test_alokasi_menolak_bagian_kurang_dari_satu(): void
    {
        $this->expectException(InvalidMoneyException::class);

        Money::of(1000)->allocate(0);
    }

    // =========================================================================
    //  Aritmetika dasar
    // =========================================================================

    public function test_penjumlahan_dan_pengurangan(): void
    {
        $a = Money::of(15_000);
        $b = Money::of(4_500);

        $this->assertSame(19_500, $a->plus($b)->amount);
        $this->assertSame(10_500, $a->minus($b)->amount);
        $this->assertSame(-10_500, $b->minus($a)->amount);
    }

    public function test_nilai_tidak_pernah_berubah_di_tempat(): void
    {
        // Money readonly. Kalau invariant ini bocor, satu perhitungan tarif
        // bisa mengubah nilai yang sudah dipakai perhitungan lain, dan bug
        // seperti itu hampir tidak bisa dilacak.
        $original = Money::of(10_000);
        $original->plus(Money::of(5_000));

        $this->assertSame(10_000, $original->amount);
    }

    public function test_menolak_operasi_antar_mata_uang_berbeda(): void
    {
        $this->expectException(InvalidMoneyException::class);

        Money::of(1000, 'IDR')->plus(Money::of(1000, 'USD'));
    }

    public function test_persentase_membulatkan_ke_bawah_supaya_sisa_jatuh_ke_driver(): void
    {
        // Komisi 20% dari Rp 10.001 adalah 2000,2. Dibulatkan ke bawah menjadi
        // 2000, sehingga 0,2 rupiah sisanya tetap di pihak driver.
        $this->assertSame(2_000, Money::of(10_001)->percentage('20')->amount);
    }

    public function test_klem_ke_rentang_regulasi(): void
    {
        $min = Money::of(5_000);
        $max = Money::of(100_000);

        $this->assertSame(5_000, Money::of(3_000)->clamp($min, $max)->amount);
        $this->assertSame(100_000, Money::of(150_000)->clamp($min, $max)->amount);
        $this->assertSame(50_000, Money::of(50_000)->clamp($min, $max)->amount);

        // Batas atas tanpa batas bawah, dan sebaliknya.
        $this->assertSame(3_000, Money::of(3_000)->clamp(null, $max)->amount);
        $this->assertSame(150_000, Money::of(150_000)->clamp($min, null)->amount);
    }

    public function test_format_rupiah(): void
    {
        $this->assertSame('Rp 1.500.000', Money::of(1_500_000)->format());
        $this->assertSame('1.500.000', Money::of(1_500_000)->format(withPrefix: false));
        $this->assertSame('Rp 0', Money::of(0)->format());
    }

    public function test_format_nilai_negatif_menaruh_minus_sebelum_prefix(): void
    {
        // "Rp -5.600" terbaca seperti salah cetak. "-Rp 5.600" adalah bentuk
        // yang dipakai di struk dan laporan keuangan Indonesia.
        $this->assertSame('-Rp 5.600', Money::of(-5_600)->format());
    }

    public function test_absolute_dan_negated(): void
    {
        $this->assertSame(5_600, Money::of(-5_600)->absolute()->amount);
        $this->assertSame(5_600, Money::of(5_600)->absolute()->amount);
        $this->assertSame(-5_600, Money::of(5_600)->negated()->amount);
        $this->assertSame(0, Money::of(0)->negated()->amount);
    }

    public function test_perbandingan(): void
    {
        $seribu = Money::of(1_000);
        $duaRibu = Money::of(2_000);

        $this->assertTrue($duaRibu->isGreaterThan($seribu));
        $this->assertTrue($seribu->isLessThan($duaRibu));
        $this->assertTrue($seribu->equals(Money::of(1_000)));
        $this->assertTrue($seribu->isGreaterThanOrEqual(Money::of(1_000)));

        $this->assertTrue(Money::of(0)->isZero());
        $this->assertTrue(Money::of(1)->isPositive());
        $this->assertTrue(Money::of(-1)->isNegative());

        // Nol bukan positif dan bukan negatif. Kalau salah satu mengembalikan
        // true, setiap baris ledger bernilai nol akan salah diperlakukan.
        $this->assertFalse(Money::of(0)->isPositive());
        $this->assertFalse(Money::of(0)->isNegative());
    }

    public function test_persentase_menolak_masukan_tidak_valid(): void
    {
        $this->expectException(InvalidMoneyException::class);

        Money::of(1_000)->percentage('-20');
    }
}
