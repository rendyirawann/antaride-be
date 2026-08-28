<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObjects;

use App\Domain\Shared\Exceptions\InvalidMoneyException;
use JsonSerializable;
use Stringable;

/**
 * Uang dalam Rupiah utuh, disimpan sebagai integer.
 *
 * Tidak ada sen, karena Rupiah tidak dipakai dalam pecahan sen di transaksi
 * seperti ini, dan pembulatan sen adalah sumber selisih yang tidak perlu.
 *
 * Kenapa value object dan bukan sekadar int:
 *
 *   1. Float untuk uang dilarang di seluruh proyek ini. Class ini membuat
 *      pelanggarannya gagal keras, bukan diam-diam menghasilkan 0.1 + 0.2.
 *   2. Pembagian selalu eksplisit soal ke mana sisanya pergi. Membagi
 *      Rp 25.000 dengan komisi 15% menghasilkan 3.750 tepat, tapi 17% pada
 *      Rp 12.345 tidak bulat, dan hasilnya harus ditentukan, bukan dibiarkan
 *      pada perilaku default pembulatan PHP.
 *   3. Operasi antar mata uang berbeda mustahil dilakukan tanpa sengaja.
 *
 * Immutable. Setiap operasi mengembalikan instance baru.
 */
final readonly class Money implements JsonSerializable, Stringable
{
    private function __construct(
        public int $amount,
        public string $currency = 'IDR',
    ) {}

    // -------------------------------------------------------------------------
    // Pembuatan
    // -------------------------------------------------------------------------

    public static function of(int $amount, string $currency = 'IDR'): self
    {
        return new self($amount, $currency);
    }

    public static function zero(string $currency = 'IDR'): self
    {
        return new self(0, $currency);
    }

    /**
     * Dari nilai yang datang dari luar (database, API, form).
     *
     * Menolak float secara sengaja. Kalau ada yang mengirim 12500.0, itu tanda
     * ada perhitungan uang yang lolos ke ranah float di tempat lain, dan yang
     * dibutuhkan adalah memperbaiki tempat itu, bukan menerima nilainya di sini.
     */
    public static function fromMixed(mixed $value, string $currency = 'IDR'): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_int($value)) {
            return new self($value, $currency);
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return new self((int) $value, $currency);
        }

        if (is_float($value)) {
            throw new InvalidMoneyException(
                'Nilai uang tidak boleh float. Ditemukan: '.var_export($value, true)
                .'. Perbaiki perhitungan di hulu supaya tetap integer Rupiah.'
            );
        }

        throw new InvalidMoneyException(
            'Nilai uang tidak dapat dibaca: '.get_debug_type($value)
        );
    }

    // -------------------------------------------------------------------------
    // Aritmetika
    // -------------------------------------------------------------------------

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount - $other->amount, $this->currency);
    }

    public function multipliedBy(int $factor): self
    {
        return new self($this->amount * $factor, $this->currency);
    }

    /**
     * Perkalian dengan pengali desimal, misal surge 1.3x.
     *
     * Pengali dikirim sebagai string supaya tidak ada float yang menyelinap
     * lewat literal seperti 1.3 yang sebenarnya 1.2999999999999998.
     */
    public function scaledBy(string $multiplier, RoundingMode $rounding = RoundingMode::HalfUp): self
    {
        if (preg_match('/^\d+(\.\d+)?$/', $multiplier) !== 1) {
            throw new InvalidMoneyException("Pengali tidak valid: {$multiplier}");
        }

        // Dihitung dengan bcmath supaya presisinya pasti, lalu dibulatkan sesuai
        // mode yang diminta. bcmath bekerja pada string, jadi tidak ada tahap
        // yang melewati float.
        $product = bcmul((string) $this->amount, $multiplier, 6);

        return new self($this->roundString($product, $rounding), $this->currency);
    }

    /**
     * Ambil persentase, misal komisi 15%.
     *
     * Pembulatan default ke bawah (Floor) untuk bagian platform. Ini keputusan
     * yang disengaja: kalau ada sisa satu rupiah yang tidak bisa dibagi rata,
     * lebih baik jatuh ke driver daripada ke platform. Sisa itu memang kecil,
     * tapi arah keberpihakannya harus ditentukan sekali dan konsisten, bukan
     * bergantung pada mode pembulatan default bahasa.
     */
    public function percentage(string $percent, RoundingMode $rounding = RoundingMode::Floor): self
    {
        if (preg_match('/^\d+(\.\d+)?$/', $percent) !== 1) {
            throw new InvalidMoneyException("Persentase tidak valid: {$percent}");
        }

        $result = bcdiv(bcmul((string) $this->amount, $percent, 6), '100', 6);

        return new self($this->roundString($result, $rounding), $this->currency);
    }

    /**
     * Bagi menjadi beberapa bagian tanpa kehilangan satu rupiah pun.
     *
     * Sisa pembagian didistribusikan satu-satu ke bagian pertama, sehingga
     * jumlah seluruh bagian SELALU sama dengan nilai aslinya. Ini yang membuat
     * pembagian ongkos ke beberapa pihak tidak pernah menghasilkan selisih.
     *
     * @return array<int, self>
     */
    public function allocate(int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidMoneyException('Jumlah bagian harus minimal 1.');
        }

        /*
         * Dihitung pada nilai mutlak, lalu tandanya dikembalikan.
         *
         * Versi pertama membagi langsung pada nilai bertanda, dan itu kehilangan
         * satu rupiah untuk nilai negatif — persis melanggar invariant yang
         * ditulis di docblock method ini sendiri. Penyebabnya intdiv() memotong
         * ke arah nol, sehingga sisanya juga negatif dan perbandingan
         * `$i < $remainder` tidak pernah benar:
         *
         *   allocate(-10, 3) lama -> [-3, -3, -3]  jumlah -9   SALAH
         *   allocate(-10, 3) baru -> [-4, -3, -3]  jumlah -10  benar
         */
        $sign = $this->amount < 0 ? -1 : 1;
        $absolute = abs($this->amount);

        $base = intdiv($absolute, $parts);
        $remainder = $absolute - ($base * $parts);

        $result = [];

        for ($i = 0; $i < $parts; $i++) {
            $result[] = new self(
                $sign * ($base + ($i < $remainder ? 1 : 0)),
                $this->currency,
            );
        }

        return $result;
    }

    /**
     * Klem ke rentang yang diizinkan.
     *
     * Dipakai untuk batas tarif Kementerian Perhubungan: hasil hitung apa pun
     * harus jatuh di antara min_fare_regulated dan max_fare_regulated.
     */
    public function clamp(?self $min, ?self $max): self
    {
        $result = $this;

        if ($min !== null && $result->isLessThan($min)) {
            $result = $min;
        }

        if ($max !== null && $result->isGreaterThan($max)) {
            $result = $max;
        }

        return $result;
    }

    public function negated(): self
    {
        return new self(-$this->amount, $this->currency);
    }

    public function absolute(): self
    {
        return new self(abs($this->amount), $this->currency);
    }

    // -------------------------------------------------------------------------
    // Perbandingan
    // -------------------------------------------------------------------------

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency
            && $this->amount === $other->amount;
    }

    public function isGreaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount > $other->amount;
    }

    public function isGreaterThanOrEqual(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount >= $other->amount;
    }

    public function isLessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount < $other->amount;
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isPositive(): bool
    {
        return $this->amount > 0;
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    // -------------------------------------------------------------------------
    // Penyajian
    // -------------------------------------------------------------------------

    /**
     * Format Rupiah untuk ditampilkan: "Rp 25.000", dan "-Rp 5.600".
     *
     * Pemisah ribuan titik, sesuai kaidah Indonesia.
     *
     * Tanda minus ditaruh SEBELUM "Rp", bukan di antara "Rp" dan angkanya.
     * `number_format(-5600)` menghasilkan "Rp -5.600", dan bentuk itu terbaca
     * seperti salah cetak di struk. Struk dan laporan keuangan Indonesia
     * menulis "-Rp 5.600".
     *
     * Ini bukan soal selera: satu-satunya nilai negatif yang tampil ke
     * penumpang adalah baris penyesuaian tarif regulasi — baris yang justru
     * menguntungkan dia. Kalau bentuknya membingungkan, yang muncul adalah
     * pertanyaan ke CS tentang potongan yang sebenarnya sudah benar.
     */
    public function format(bool $withPrefix = true): string
    {
        $formatted = number_format(abs($this->amount), 0, ',', '.');
        $sign = $this->amount < 0 ? '-' : '';

        return $withPrefix
            ? "{$sign}Rp {$formatted}"
            : "{$sign}{$formatted}";
    }

    public function __toString(): string
    {
        return $this->format();
    }

    /**
     * Ke API selalu dikirim sebagai integer plus versi terformat.
     *
     * Alasannya: app butuh angkanya untuk berhitung, dan butuh string
     * terformat untuk ditampilkan. Membiarkan app memformat sendiri berarti
     * tiga aplikasi Flutter harus sepakat soal pemisah ribuan, dan salah satu
     * akan berbeda.
     *
     * @return array{amount: int, currency: string, formatted: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
            'formatted' => $this->format(),
        ];
    }

    // -------------------------------------------------------------------------

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidMoneyException(
                "Tidak bisa mengoperasikan {$this->currency} dengan {$other->currency}."
            );
        }
    }

    /**
     * ========================================================================
     *  BCMATH MEMOTONG KE ARAH NOL, BUKAN KE BAWAH
     * ========================================================================
     *  `bcadd($v, '0', 0)` memotong, dan untuk nilai negatif memotong ke arah
     *  nol: -3,7 menjadi -3, padahal floor(-3,7) adalah -4. Versi pertama
     *  method ini menganggap keduanya sama, dan akibatnya ketiga mode
     *  pembulatan berperilaku salah begitu nilainya negatif:
     *
     *    Floor(-3,7)    -> -3   seharusnya -4
     *    HalfUp(-3,2)   -> -2   seharusnya -3
     *    HalfUp(-1,0)   ->  0   seharusnya -1  (satu rupiah HILANG dari
     *                                           nilai yang bahkan tidak punya
     *                                           bagian pecahan)
     *
     *  Nilai negatif bukan kasus teoretis: `regulatory_adjustment` selalu
     *  negatif, dan itu satu-satunya komponen ongkos yang mengurangi.
     *
     *  Untuk HalfUp dipilih "setengah MENJAUH dari nol", bukan "setengah ke
     *  arah plus tak hingga". Alasannya bisnis, bukan matematis: nilai negatif
     *  di sistem ini selalu berarti pengurangan yang menguntungkan penumpang,
     *  dan membulatkannya menjauh dari nol berarti pengurangannya tidak pernah
     *  dikecilkan. Arah keberpihakan ditentukan sekali, di sini, dan berlaku
     *  untuk seluruh sistem.
     * ========================================================================
     */
    private function roundString(string $value, RoundingMode $mode): int
    {
        $truncated = bcadd($value, '0', 0);
        $hasFraction = bccomp($value, $truncated, 6) !== 0;
        $isNegative = bccomp($value, '0', 6) < 0;

        return (int) match ($mode) {
            RoundingMode::Floor => $hasFraction && $isNegative
                ? bcsub($truncated, '1', 0)
                : $truncated,

            RoundingMode::Ceiling => $hasFraction && ! $isNegative
                ? bcadd($truncated, '1', 0)
                : $truncated,

            RoundingMode::HalfUp => $isNegative
                ? bcsub($value, '0.5', 0)
                : bcadd($value, '0.5', 0),
        };
    }
}
