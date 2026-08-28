<?php

declare(strict_types=1);

namespace App\Domain\Pricing\DTOs;

use App\Domain\Shared\ValueObjects\Money;
use JsonSerializable;

/**
 * Rincian ongkos, komponen per komponen.
 *
 * Bukan sekadar total. Rincian ini dikirim ke aplikasi dan disimpan sebagai
 * snapshot di tabel orders, karena tiga alasan yang semuanya praktis:
 *
 *   1. Penumpang yang melihat ongkos naik berhak tahu bagian mana yang naik.
 *      "Rp 18.000" memancing keluhan; "biaya jarak Rp 11.000, jam sibuk
 *      Rp 3.400" menjawabnya sendiri.
 *   2. CS yang menangani sengketa perlu bisa menjelaskan angkanya tanpa
 *      menebak.
 *   3. Rekonsiliasi keuangan harus bisa menjumlahkan komisi terpisah dari
 *      pendapatan driver.
 *
 * Semua nilai Money, jadi tidak ada tahap yang melewati float.
 */
final readonly class FareBreakdown implements JsonSerializable
{
    public function __construct(
        /** Tarif buka pintu. */
        public Money $baseFare,
        /** Biaya jarak, setelah jarak gratis dikurangi. */
        public Money $distanceFare,
        /** Biaya waktu tempuh. */
        public Money $timeFare,
        /** Pengali jam sibuk, sebagai string supaya tidak melewati float. */
        public string $surgeMultiplier,
        /** Tambahan akibat surge. Nol kalau pengalinya 1.00. */
        public Money $surgeAmount,
        /**
         * Penyesuaian akibat tarif minimum atau batas regulasi Kemenhub.
         *
         * NEGATIF kalau ongkos dipotong batas atas, POSITIF kalau dinaikkan ke
         * tarif minimum atau batas bawah, nol kalau tidak ada penyesuaian.
         *
         * Kolom ini ada karena batas atas bisa memotong ongkos sampai di bawah
         * jumlah komponennya sendiri. Rute 50 km bisa menghasilkan biaya jarak
         * Rp 105.600 sementara batas atasnya Rp 100.000, dan tanpa baris ini
         * rincian yang dilihat penumpang tidak akan menjumlah ke total.
         *
         * Pilihan lain adalah menskala turun biaya jarak dan waktu supaya
         * jumlahnya pas. Itu ditolak: angka per km yang ditampilkan harus tetap
         * cocok dengan tarif resmi yang dipublikasikan, dan pemotongan karena
         * regulasi justru perlu terlihat, bukan disembunyikan.
         */
        public Money $regulatoryAdjustment,
        /** Biaya jasa aplikasi yang ditagih ke penumpang. */
        public Money $platformFee,
        /** Biaya tambahan khusus vertikal: kemasan, asuransi. */
        public Money $serviceFee,
        /** Potongan promo. */
        public Money $discount,
        /** Yang dibayar penumpang. */
        public Money $total,
        /** Yang diterima driver. */
        public Money $driverEarning,
        /** Potongan platform dari pendapatan driver. */
        public Money $commission,
        /** Apakah total sudah diklem ke batas regulasi Kemenhub. */
        public bool $clampedToRegulation = false,
        /** Apakah total sudah dinaikkan ke tarif minimum. */
        public bool $raisedToMinimum = false,
    ) {}

    /**
     * Jumlah komponen sebelum diskon.
     */
    public function subtotal(): Money
    {
        return $this->transportFare()
            ->plus($this->platformFee)
            ->plus($this->serviceFee);
    }

    /**
     * Tarif angkutan setelah surge dan penyesuaian regulasi.
     *
     * Ini bagian yang diatur Kemenhub, dan dasar perhitungan komisi. Biaya
     * aplikasi tidak termasuk.
     */
    public function transportFare(): Money
    {
        return $this->baseFare
            ->plus($this->distanceFare)
            ->plus($this->timeFare)
            ->plus($this->surgeAmount)
            ->plus($this->regulatoryAdjustment);
    }

    /**
     * Apakah rincian yang ditampilkan benar-benar menjumlah ke total.
     *
     * Diuji secara acak pada ribuan kombinasi. Rincian yang tidak menjumlah
     * adalah pertanyaan pertama yang diajukan penumpang yang teliti, dan
     * pertanyaan yang tidak bisa dijawab CS.
     */
    public function linesSumToTotal(): bool
    {
        return $this->subtotal()->minus($this->discount)->equals($this->total);
    }

    /**
     * Apakah pembagian uangnya utuh.
     *
     * Yang diterima driver ditambah potongan platform tidak boleh melebihi yang
     * dibayar penumpang ditambah diskon yang ditanggung platform.
     *
     * Diperiksa di sini DAN oleh CHECK constraint `orders_split_check` di
     * database. Yang di sini memberi pesan yang bisa dibaca saat pengembangan;
     * yang di database adalah jaring yang tidak bisa dilewati.
     */
    public function isBalanced(): bool
    {
        return $this->driverEarning->plus($this->commission)->amount
            <= $this->total->plus($this->discount)->amount;
    }

    public function hasSurge(): bool
    {
        return $this->surgeMultiplier !== '1.00' && ! $this->surgeAmount->isZero();
    }

    /**
     * Rincian untuk ditampilkan di aplikasi.
     *
     * Komponen bernilai nol dibuang, supaya penumpang tidak melihat baris
     * "Jam sibuk Rp 0" yang justru membingungkan.
     *
     * @return array<int, array{label: string, amount: int, formatted: string}>
     */
    public function displayLines(): array
    {
        $lines = [
            ['label' => 'Tarif dasar', 'money' => $this->baseFare],
            ['label' => 'Biaya jarak', 'money' => $this->distanceFare],
            ['label' => 'Biaya waktu', 'money' => $this->timeFare],
            ['label' => 'Jam sibuk', 'money' => $this->surgeAmount],
        ];

        $result = [];

        foreach ($lines as $line) {
            /** @var Money $money */
            $money = $line['money'];

            if ($money->isZero()) {
                continue;
            }

            $result[] = [
                'label' => $line['label'],
                'amount' => $money->amount,
                'formatted' => $money->format(),
            ];
        }

        // Penyesuaian regulasi. Labelnya berbeda tergantung arahnya, karena
        // "penyesuaian batas tarif" pada angka positif membingungkan: penumpang
        // perlu tahu apakah ongkosnya dipotong atau dinaikkan.
        if (! $this->regulatoryAdjustment->isZero()) {
            $isReduction = $this->regulatoryAdjustment->isNegative();

            $result[] = [
                'label' => $isReduction
                    ? 'Penyesuaian batas tarif'
                    : ($this->raisedToMinimum ? 'Penyesuaian tarif minimum' : 'Penyesuaian batas tarif'),
                'amount' => $this->regulatoryAdjustment->amount,
                'formatted' => ($isReduction ? '-' : '+')
                    .$this->regulatoryAdjustment->absolute()->format(),
            ];
        }

        foreach ([
            ['label' => 'Biaya layanan', 'money' => $this->platformFee],
            ['label' => 'Biaya tambahan', 'money' => $this->serviceFee],
        ] as $line) {
            /** @var Money $money */
            $money = $line['money'];

            if ($money->isZero()) {
                continue;
            }

            $result[] = [
                'label' => $line['label'],
                'amount' => $money->amount,
                'formatted' => $money->format(),
            ];
        }

        // Diskon selalu ditampilkan kalau ada, dengan tanda negatif.
        if (! $this->discount->isZero()) {
            $result[] = [
                'label' => 'Potongan promo',
                'amount' => -$this->discount->amount,
                'formatted' => '-'.$this->discount->format(),
            ];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'base_fare' => $this->baseFare->amount,
            'distance_fare' => $this->distanceFare->amount,
            'time_fare' => $this->timeFare->amount,
            'surge_multiplier' => $this->surgeMultiplier,
            'surge_amount' => $this->surgeAmount->amount,
            'regulatory_adjustment' => $this->regulatoryAdjustment->amount,
            'platform_fee' => $this->platformFee->amount,
            'service_fee' => $this->serviceFee->amount,
            'discount_amount' => $this->discount->amount,
            'total_fare' => $this->total->amount,
            'driver_earning' => $this->driverEarning->amount,
            'commission_amount' => $this->commission->amount,
            'total_formatted' => $this->total->format(),
            'lines' => $this->displayLines(),
            'has_surge' => $this->hasSurge(),
        ];
    }

    /**
     * Kolom snapshot untuk tabel orders.
     *
     * @return array<string, int|string>
     */
    public function toOrderColumns(): array
    {
        return [
            'base_fare' => $this->baseFare->amount,
            'distance_fare' => $this->distanceFare->amount,
            'time_fare' => $this->timeFare->amount,
            'surge_multiplier' => $this->surgeMultiplier,
            'surge_amount' => $this->surgeAmount->amount,
            'regulatory_adjustment' => $this->regulatoryAdjustment->amount,
            'platform_fee' => $this->platformFee->amount,
            'service_fee' => $this->serviceFee->amount,
            'discount_amount' => $this->discount->amount,
            'total_fare' => $this->total->amount,
            'driver_earning' => $this->driverEarning->amount,
            'commission_amount' => $this->commission->amount,
        ];
    }
}
