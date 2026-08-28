<?php

declare(strict_types=1);

namespace Tests\Unit\Pricing;

use App\Domain\Catalog\Models\PricingRule;
use App\Domain\Pricing\Calculator\FareCalculator;
use App\Domain\Pricing\DTOs\RouteResult;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Shared\ValueObjects\Polyline;
use Tests\TestCase;

/**
 * Unit test murni: tidak menyentuh database.
 *
 * PricingRule dibuat dengan `new` lalu diisi atributnya, bukan disimpan. Yang
 * diuji adalah aritmetikanya, dan menyentuh database untuk itu hanya
 * memperlambat tanpa menambah keyakinan.
 */
class FareCalculatorTest extends TestCase
{
    private FareCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new FareCalculator;
    }

    // -------------------------------------------------------------------------
    // Perhitungan dasar
    // -------------------------------------------------------------------------

    /**
     * Kasus lengkap yang bisa dihitung tangan.
     *
     * Tarif: base 6.000, per_km 2.200, per_menit 150, gratis 2.000 m
     * Rute : 5.000 m, 900 detik (15 menit)
     *
     *   jarak berbayar  = 5.000 - 2.000 = 3.000 m = 3 km
     *   biaya jarak     = 2.200 x 3     = 6.600
     *   biaya waktu     = 150 x 15      = 2.250
     *   tarif angkutan  = 6.000 + 6.600 + 2.250 = 14.850
     *   biaya aplikasi  = 1.000
     *   total           = 15.850
     *   komisi 15%      = 2.227  (dibulatkan ke bawah dari 2.227,5)
     *   driver          = 14.850 - 2.227 = 12.623
     */
    public function test_menghitung_ongkos_lengkap_dengan_angka_yang_bisa_dihitung_tangan(): void
    {
        $fare = $this->calculator->calculate(
            $this->route(distanceMeters: 5000, durationSeconds: 900),
            $this->rule(),
        );

        $this->assertSame(6000, $fare->baseFare->amount);
        $this->assertSame(6600, $fare->distanceFare->amount);
        $this->assertSame(2250, $fare->timeFare->amount);
        $this->assertSame(1000, $fare->platformFee->amount);
        $this->assertSame(15850, $fare->total->amount);
        $this->assertSame(2227, $fare->commission->amount);
        $this->assertSame(12623, $fare->driverEarning->amount);
    }

    /**
     * Jarak gratis harus dikurangi. Tanpa itu, kilometer pertama ditagih dua
     * kali: sekali di tarif buka pintu dan sekali di biaya per km.
     */
    public function test_jarak_gratis_dikurangi_dari_biaya_jarak(): void
    {
        $fare = $this->calculator->calculate(
            $this->route(distanceMeters: 2000, durationSeconds: 300),
            $this->rule(freeDistanceM: 2000),
        );

        $this->assertSame(
            0,
            $fare->distanceFare->amount,
            'Jarak yang seluruhnya masuk jarak gratis masih ditagih.',
        );
    }

    public function test_jarak_di_bawah_jarak_gratis_tidak_menghasilkan_biaya_negatif(): void
    {
        $fare = $this->calculator->calculate(
            $this->route(distanceMeters: 500, durationSeconds: 120),
            $this->rule(freeDistanceM: 2000),
        );

        $this->assertSame(0, $fare->distanceFare->amount);
        $this->assertTrue($fare->distanceFare->amount >= 0);
    }

    /**
     * Jarak dihitung proporsional per meter, bukan dibulatkan ke atas per km.
     *
     * Membulatkan 2.100 m menjadi 3 km menagih 43% lebih dari jarak tempuh
     * sebenarnya, dan itu keluhan yang wajar.
     */
    public function test_jarak_dihitung_proporsional_bukan_dibulatkan_per_kilometer(): void
    {
        $fare = $this->calculator->calculate(
            // 2.000 m gratis + 100 m berbayar = 0,1 km
            $this->route(distanceMeters: 2100, durationSeconds: 300),
            $this->rule(perKm: 2000, freeDistanceM: 2000, perMinute: 0),
        );

        // 0,1 km x 2.000 = 200, bukan 2.000 (1 km dibulatkan)
        $this->assertSame(200, $fare->distanceFare->amount);
    }

    public function test_waktu_dibulatkan_ke_atas_per_menit(): void
    {
        $fare = $this->calculator->calculate(
            // 12 menit 10 detik
            $this->route(distanceMeters: 3000, durationSeconds: 730),
            $this->rule(perMinute: 150),
        );

        // 13 menit x 150 = 1.950
        $this->assertSame(1950, $fare->timeFare->amount);
    }

    public function test_per_menit_nol_menghasilkan_biaya_waktu_nol(): void
    {
        $fare = $this->calculator->calculate(
            $this->route(distanceMeters: 5000, durationSeconds: 3600),
            $this->rule(perMinute: 0),
        );

        $this->assertSame(0, $fare->timeFare->amount);
    }

    // -------------------------------------------------------------------------
    // Surge
    // -------------------------------------------------------------------------

    public function test_surge_menaikkan_tarif_angkutan_saja(): void
    {
        $normal = $this->calculator->calculate(
            $this->route(distanceMeters: 5000, durationSeconds: 900),
            $this->rule(),
        );

        $surged = $this->calculator->calculate(
            $this->route(distanceMeters: 5000, durationSeconds: 900),
            $this->rule(),
            surgeMultiplier: '1.30',
        );

        // Tarif angkutan 14.850 x 1,30 = 19.305, jadi surge 4.455.
        $this->assertSame(4455, $surged->surgeAmount->amount);
        $this->assertSame('1.30', $surged->surgeMultiplier);
        $this->assertTrue($surged->hasSurge());

        // Biaya aplikasi TIDAK dikenai surge.
        $this->assertSame(
            $normal->platformFee->amount,
            $surged->platformFee->amount,
            'Biaya aplikasi ikut naik karena surge; seharusnya tetap.',
        );

        $this->assertSame(19305 + 1000, $surged->total->amount);
    }

    public function test_surge_satu_kali_tidak_menghasilkan_tambahan(): void
    {
        $fare = $this->calculator->calculate(
            $this->route(distanceMeters: 5000, durationSeconds: 900),
            $this->rule(),
            surgeMultiplier: '1.00',
        );

        $this->assertSame(0, $fare->surgeAmount->amount);
        $this->assertFalse($fare->hasSurge());
    }

    /**
     * Pengali dikirim sebagai string, jadi tidak ada tahap yang melewati float.
     *
     * 1.3 sebagai float PHP sebenarnya 1.2999999999999998, dan mengalikannya
     * dengan 14.850 menghasilkan 19.304,99999 yang lalu dibulatkan ke bawah
     * jadi 19.304 — satu rupiah hilang, setiap order, selamanya.
     */
    public function test_pengali_desimal_tidak_kehilangan_rupiah_karena_float(): void
    {
        $fare = $this->calculator->calculate(
            $this->route(distanceMeters: 5000, durationSeconds: 900),
            $this->rule(),
            surgeMultiplier: '1.30',
        );

        // Tepat 19.305, bukan 19.304.
        $this->assertSame(19305, $fare->baseFare->amount
            + $fare->distanceFare->amount
            + $fare->timeFare->amount
            + $fare->surgeAmount->amount);
    }

    // -------------------------------------------------------------------------
    // Minimum dan batas regulasi
    // -------------------------------------------------------------------------

    public function test_ongkos_dinaikkan_ke_tarif_minimum(): void
    {
        $fare = $this->calculator->calculate(
            // Perjalanan sangat pendek.
            $this->route(distanceMeters: 500, durationSeconds: 120),
            $this->rule(baseFare: 3000, perKm: 2000, perMinute: 0, minimumFare: 9000, freeDistanceM: 2000),
        );

        $this->assertTrue($fare->raisedToMinimum);
        // 9.000 tarif minimum + 1.000 biaya aplikasi
        $this->assertSame(10000, $fare->total->amount);
    }

    /**
     * Batas atas Kemenhub harus menang atas surge.
     *
     * Kalau surge diterapkan setelah klem, pengali jam sibuk bisa melompati
     * batas atas dan platform melanggar regulasi tanpa ada yang menyadari.
     */
    public function test_batas_atas_regulasi_menang_atas_surge(): void
    {
        $fare = $this->calculator->calculate(
            $this->route(distanceMeters: 50000, durationSeconds: 3600),
            $this->rule(maxFareRegulated: 100000),
            surgeMultiplier: '3.00',
        );

        $this->assertTrue($fare->clampedToRegulation);

        // Tarif angkutan diklem ke 100.000, ditambah biaya aplikasi 1.000.
        $this->assertSame(101000, $fare->total->amount);
    }

    public function test_batas_bawah_regulasi_menaikkan_ongkos(): void
    {
        $fare = $this->calculator->calculate(
            $this->route(distanceMeters: 300, durationSeconds: 60),
            $this->rule(
                baseFare: 2000,
                perKm: 1000,
                perMinute: 0,
                minimumFare: 2000,
                freeDistanceM: 2000,
                minFareRegulated: 7000,
            ),
        );

        $this->assertTrue($fare->clampedToRegulation);
        $this->assertSame(8000, $fare->total->amount);
    }

    /**
     * Pemotongan karena batas regulasi muncul sebagai baris tersendiri, dan
     * rincian tarif angkutan tetap menjumlah dengan tepat.
     *
     * Kasus ini yang menemukan kesalahan desain awal: batas atas bisa memotong
     * ongkos sampai di BAWAH jumlah komponennya sendiri. Rute 50 km menghasilkan
     * biaya jarak Rp 105.600 sementara batas atasnya Rp 100.000, jadi menyetel
     * ulang bagian surge tidak bisa menutup selisihnya — surge-nya nol pun,
     * jumlahnya masih melebihi batas.
     *
     * Pilihan yang ditolak: menskala turun biaya jarak dan waktu supaya pas.
     * Itu membuat biaya per km yang ditampilkan tidak lagi cocok dengan tarif
     * resmi yang dipublikasikan, dan menyembunyikan fakta bahwa regulasi sedang
     * memotong ongkos.
     */
    public function test_pemotongan_regulasi_muncul_sebagai_baris_tersendiri(): void
    {
        $fare = $this->calculator->calculate(
            $this->route(distanceMeters: 50000, durationSeconds: 3600),
            $this->rule(maxFareRegulated: 100000),
            surgeMultiplier: '3.00',
        );

        $this->assertTrue($fare->clampedToRegulation);

        // Penyesuaiannya negatif, karena ongkos dipotong.
        $this->assertTrue(
            $fare->regulatoryAdjustment->isNegative(),
            'Pemotongan batas atas tidak tercatat sebagai penyesuaian negatif.',
        );

        // Dan rincian tarif angkutan menjumlah dengan tepat ke batas atasnya.
        $this->assertSame(100000, $fare->transportFare()->amount);

        $sum = $fare->baseFare->amount
            + $fare->distanceFare->amount
            + $fare->timeFare->amount
            + $fare->surgeAmount->amount
            + $fare->regulatoryAdjustment->amount;

        $this->assertSame(100000, $sum);

        // Baris penyesuaiannya benar-benar muncul di rincian yang dilihat
        // penumpang, bukan hanya ada di data.
        $labels = array_column($fare->displayLines(), 'label');
        $this->assertContains('Penyesuaian batas tarif', $labels);
    }

    /**
     * Kenaikan ke tarif minimum juga muncul sebagai baris, dengan tanda positif.
     */
    public function test_kenaikan_ke_tarif_minimum_muncul_sebagai_baris_positif(): void
    {
        $fare = $this->calculator->calculate(
            $this->route(distanceMeters: 500, durationSeconds: 120),
            $this->rule(baseFare: 3000, perKm: 2000, perMinute: 0, minimumFare: 9000, freeDistanceM: 2000),
        );

        $this->assertTrue($fare->raisedToMinimum);
        $this->assertTrue($fare->regulatoryAdjustment->isPositive());

        $labels = array_column($fare->displayLines(), 'label');
        $this->assertContains('Penyesuaian tarif minimum', $labels);
    }

    /**
     * Rincian HARUS menjumlah ke total pada setiap kombinasi masukan.
     *
     * Rincian yang tidak menjumlah adalah pertanyaan pertama yang diajukan
     * penumpang yang teliti, dan pertanyaan yang tidak bisa dijawab CS.
     */
    public function test_rincian_selalu_menjumlah_ke_total_pada_seribu_kombinasi(): void
    {
        $violations = [];

        for ($i = 0; $i < 1000; $i++) {
            $fare = $this->calculator->calculate(
                $this->route(
                    distanceMeters: random_int(100, 80000),
                    durationSeconds: random_int(60, 7200),
                ),
                $this->rule(
                    baseFare: random_int(0, 20000),
                    perKm: random_int(500, 8000),
                    perMinute: random_int(0, 600),
                    minimumFare: random_int(0, 30000),
                    freeDistanceM: random_int(0, 3000),
                    platformFee: random_int(0, 5000),
                    minFareRegulated: random_int(0, 1) === 1 ? random_int(0, 20000) : null,
                    maxFareRegulated: random_int(0, 1) === 1 ? random_int(20000, 500000) : null,
                ),
                surgeMultiplier: number_format(random_int(100, 300) / 100, 2, '.', ''),
                discount: Money::of(random_int(0, 30000)),
            );

            if (! $fare->linesSumToTotal()) {
                $violations[] = sprintf(
                    'subtotal %d - diskon %d != total %d',
                    $fare->subtotal()->amount,
                    $fare->discount->amount,
                    $fare->total->amount,
                );
            }
        }

        $this->assertSame(
            [],
            $violations,
            count($violations)." dari 1000 kombinasi rinciannya tidak menjumlah:\n  "
            .implode("\n  ", array_slice($violations, 0, 5)),
        );
    }

    // -------------------------------------------------------------------------
    // Diskon
    // -------------------------------------------------------------------------

    public function test_diskon_mengurangi_total_yang_dibayar_penumpang(): void
    {
        $fare = $this->calculator->calculate(
            $this->route(distanceMeters: 5000, durationSeconds: 900),
            $this->rule(),
            discount: Money::of(5000),
        );

        $this->assertSame(5000, $fare->discount->amount);
        $this->assertSame(15850 - 5000, $fare->total->amount);
    }

    /**
     * Diskon TIDAK boleh mengurangi pendapatan driver.
     *
     * Promo adalah biaya pemasaran platform. Kalau driver ikut menanggungnya,
     * dia dihukum karena menerima order yang kebetulan memakai kode promo, dan
     * itu alasan pertama driver berhenti menerima order tertentu.
     */
    public function test_diskon_tidak_mengurangi_pendapatan_driver(): void
    {
        $tanpaDiskon = $this->calculator->calculate(
            $this->route(distanceMeters: 5000, durationSeconds: 900),
            $this->rule(),
        );

        $denganDiskon = $this->calculator->calculate(
            $this->route(distanceMeters: 5000, durationSeconds: 900),
            $this->rule(),
            discount: Money::of(5000),
        );

        $this->assertSame(
            $tanpaDiskon->driverEarning->amount,
            $denganDiskon->driverEarning->amount,
            'Pendapatan driver berkurang karena promo. Driver menanggung biaya pemasaran platform.',
        );

        $this->assertSame(
            $tanpaDiskon->commission->amount,
            $denganDiskon->commission->amount,
        );
    }

    /**
     * Diskon yang lebih besar dari ongkos menghasilkan ongkos nol, bukan minus.
     */
    public function test_diskon_melebihi_ongkos_menghasilkan_nol_bukan_minus(): void
    {
        $fare = $this->calculator->calculate(
            $this->route(distanceMeters: 2500, durationSeconds: 300),
            $this->rule(),
            discount: Money::of(999999),
        );

        $this->assertSame(0, $fare->total->amount);
        $this->assertFalse($fare->total->isNegative());

        // Yang dicatat sebagai diskon adalah yang benar-benar terpakai, bukan
        // nilai promo penuh. Kalau nilai penuh yang dicatat, laporan biaya
        // promo akan melebihkan pengeluaran platform.
        $this->assertLessThan(999999, $fare->discount->amount);
    }

    // -------------------------------------------------------------------------
    // Komisi
    // -------------------------------------------------------------------------

    /**
     * Komisi dihitung dari tarif angkutan, BUKAN dari total yang dibayar
     * penumpang.
     *
     * Kalau dari total, driver ikut menanggung biaya aplikasi yang bukan
     * bagiannya.
     */
    public function test_komisi_dihitung_dari_tarif_angkutan_bukan_total(): void
    {
        $fare = $this->calculator->calculate(
            $this->route(distanceMeters: 5000, durationSeconds: 900),
            $this->rule(commissionPercent: '20'),
        );

        // Tarif angkutan 14.850 x 20% = 2.970
        $this->assertSame(2970, $fare->commission->amount);

        // Bukan 15.850 (total) x 20% = 3.170
        $this->assertNotSame(3170, $fare->commission->amount);
    }

    /**
     * Sisa pembagian jatuh ke driver, bukan ke platform.
     *
     * Arah keberpihakannya harus ditentukan sekali dan konsisten. Satu rupiah
     * per order kali 500 order per hari adalah Rp 182.500 per tahun yang tidak
     * bisa dijelaskan asalnya.
     */
    public function test_sisa_pembulatan_komisi_jatuh_ke_driver(): void
    {
        $fare = $this->calculator->calculate(
            $this->route(distanceMeters: 5000, durationSeconds: 900),
            // 15% dari 14.850 = 2.227,5
            $this->rule(commissionPercent: '15'),
        );

        // Dibulatkan ke bawah, jadi platform dapat 2.227 dan driver dapat
        // setengah rupiah lebihnya.
        $this->assertSame(2227, $fare->commission->amount);
        $this->assertSame(14850 - 2227, $fare->driverEarning->amount);
    }

    public function test_komisi_nol_persen_menghasilkan_pendapatan_driver_penuh(): void
    {
        $fare = $this->calculator->calculate(
            $this->route(distanceMeters: 5000, durationSeconds: 900),
            $this->rule(commissionPercent: '0'),
        );

        $this->assertSame(0, $fare->commission->amount);
        $this->assertSame(14850, $fare->driverEarning->amount);
    }

    // -------------------------------------------------------------------------
    // Invariant pembagian uang
    // -------------------------------------------------------------------------

    /**
     * Pembagian uang harus utuh pada SETIAP kombinasi masukan.
     *
     * Ini invariant yang sama dengan CHECK constraint `orders_split_check` di
     * database. Yang di sini menangkapnya saat pengembangan; yang di database
     * adalah jaring yang tidak bisa dilewati.
     */
    public function test_pembagian_uang_utuh_pada_seribu_kombinasi_acak(): void
    {
        $violations = [];

        for ($i = 0; $i < 1000; $i++) {
            $fare = $this->calculator->calculate(
                $this->route(
                    distanceMeters: random_int(100, 80000),
                    durationSeconds: random_int(60, 7200),
                ),
                $this->rule(
                    baseFare: random_int(0, 20000),
                    perKm: random_int(500, 8000),
                    perMinute: random_int(0, 600),
                    minimumFare: random_int(0, 30000),
                    freeDistanceM: random_int(0, 3000),
                    platformFee: random_int(0, 5000),
                    commissionPercent: (string) random_int(0, 30),
                    minFareRegulated: random_int(0, 1) === 1 ? random_int(0, 20000) : null,
                    maxFareRegulated: random_int(0, 1) === 1 ? random_int(50000, 500000) : null,
                ),
                surgeMultiplier: number_format(random_int(100, 300) / 100, 2, '.', ''),
                discount: Money::of(random_int(0, 30000)),
            );

            if (! $fare->isBalanced()) {
                $violations[] = sprintf(
                    'driver %d + komisi %d > total %d + diskon %d',
                    $fare->driverEarning->amount,
                    $fare->commission->amount,
                    $fare->total->amount,
                    $fare->discount->amount,
                );
            }

            if ($fare->total->isNegative() || $fare->driverEarning->isNegative() || $fare->commission->isNegative()) {
                $violations[] = 'ada nilai negatif';
            }
        }

        $this->assertSame(
            [],
            $violations,
            count($violations)." dari 1000 kombinasi melanggar invariant:\n  "
            .implode("\n  ", array_slice($violations, 0, 5)),
        );
    }

    // -------------------------------------------------------------------------
    // Rincian tampilan
    // -------------------------------------------------------------------------

    public function test_rincian_tampilan_membuang_komponen_bernilai_nol(): void
    {
        $fare = $this->calculator->calculate(
            $this->route(distanceMeters: 5000, durationSeconds: 900),
            $this->rule(perMinute: 0),
            surgeMultiplier: '1.00',
        );

        $labels = array_column($fare->displayLines(), 'label');

        $this->assertNotContains('Biaya waktu', $labels, 'Baris bernilai nol ikut ditampilkan.');
        $this->assertNotContains('Jam sibuk', $labels);
        $this->assertContains('Tarif dasar', $labels);
        $this->assertContains('Biaya jarak', $labels);
    }

    public function test_diskon_ditampilkan_dengan_tanda_negatif(): void
    {
        $fare = $this->calculator->calculate(
            $this->route(distanceMeters: 5000, durationSeconds: 900),
            $this->rule(),
            discount: Money::of(3000),
        );

        $lines = $fare->displayLines();
        $discountLine = null;

        foreach ($lines as $line) {
            if ($line['label'] === 'Potongan promo') {
                $discountLine = $line;
            }
        }

        $this->assertNotNull($discountLine);
        $this->assertSame(-3000, $discountLine['amount']);
        $this->assertStringStartsWith('-Rp', $discountLine['formatted']);
    }

    public function test_kolom_snapshot_order_lengkap(): void
    {
        $fare = $this->calculator->calculate(
            $this->route(distanceMeters: 5000, durationSeconds: 900),
            $this->rule(),
            surgeMultiplier: '1.20',
        );

        $columns = $fare->toOrderColumns();

        foreach ([
            'base_fare', 'distance_fare', 'time_fare', 'surge_multiplier',
            'surge_amount', 'platform_fee', 'service_fee', 'discount_amount',
            'total_fare', 'driver_earning', 'commission_amount',
        ] as $column) {
            $this->assertArrayHasKey($column, $columns, "Kolom {$column} tidak ada di snapshot.");
        }

        $this->assertSame('1.20', $columns['surge_multiplier']);
    }

    // -------------------------------------------------------------------------
    // Pembantu
    // -------------------------------------------------------------------------

    private function route(int $distanceMeters, int $durationSeconds): RouteResult
    {
        return new RouteResult(
            distanceMeters: $distanceMeters,
            durationSeconds: $durationSeconds,
            polyline: Polyline::empty(),
        );
    }

    private function rule(
        int $baseFare = 6000,
        int $perKm = 2200,
        int $perMinute = 150,
        int $minimumFare = 9000,
        int $freeDistanceM = 2000,
        int $platformFee = 1000,
        string $commissionPercent = '15',
        ?int $minFareRegulated = null,
        ?int $maxFareRegulated = null,
        int $packagingFee = 0,
        int $insuranceFee = 0,
    ): PricingRule {
        $rule = new PricingRule;

        $rule->base_fare = $baseFare;
        $rule->per_km = $perKm;
        $rule->per_minute = $perMinute;
        $rule->minimum_fare = $minimumFare;
        $rule->free_distance_m = $freeDistanceM;
        $rule->platform_fee = $platformFee;
        $rule->commission_percent = $commissionPercent;
        $rule->min_fare_regulated = $minFareRegulated;
        $rule->max_fare_regulated = $maxFareRegulated;
        $rule->packaging_fee = $packagingFee;
        $rule->insurance_fee = $insuranceFee;

        return $rule;
    }
}
