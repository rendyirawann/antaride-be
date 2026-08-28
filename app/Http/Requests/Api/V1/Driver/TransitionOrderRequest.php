<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Driver;

use App\Domain\Shared\ValueObjects\Coordinate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Ubah status order oleh driver.
 *
 * ============================================================================
 *  KOORDINAT WAJIB, DAN ITU PILIHAN YANG DISENGAJA
 * ============================================================================
 *  Setiap transisi status oleh driver harus disertai posisinya. Itu satu-satunya
 *  cara menjawab pertanyaan yang paling sering muncul dalam sengketa:
 *
 *      "Apakah driver benar-benar ada di titik jemput saat menekan 'tiba'?"
 *
 *  Tanpa koordinat, jawabannya tidak ada di mana pun, dan yang tersisa hanya
 *  keterangan dua pihak yang bertentangan.
 *
 *  Posisi dari GPS bisa dipalsukan dengan aplikasi mock location, dan koordinat
 *  ini TIDAK diperlakukan sebagai bukti mutlak. Tapi bedanya besar: memalsukan
 *  posisi menuntut usaha yang terlihat di data (lompatan posisi, akurasi
 *  sempurna yang tidak wajar), sementara tanpa data sama sekali tidak ada yang
 *  bisa diperiksa.
 * ============================================================================
 */
class TransitionOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Hubungan driver dengan order diperiksa controller lewat
        // `assignedOrder()`.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * Status tujuan dibatasi ke transisi yang MASUK AKAL dari aplikasi
             * driver.
             *
             * `completed` dan `cancelled` sengaja TIDAK ada di sini: keduanya
             * punya endpoint sendiri karena membawa efek samping yang jauh lebih
             * besar — settlement uang dan pelepasan dana tertahan. Menyatukannya
             * ke endpoint transisi generik berarti satu request yang salah
             * status bisa memicu pembagian uang.
             */
            'status' => ['required', 'string', 'in:driver_arriving,driver_arrived'],

            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],

            // Akurasi dikirim supaya panel admin bisa menilai seberapa layak
            // posisinya dipercaya saat ada sengketa.
            'accuracy_m' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:10000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.in' => 'Perubahan status tidak dikenali.',
            'lat.required' => 'Posisi wajib dikirim.',
            'lng.required' => 'Posisi wajib dikirim.',
        ];
    }

    public function coordinate(): Coordinate
    {
        return Coordinate::of(
            (float) $this->validated('lat'),
            (float) $this->validated('lng'),
        );
    }
}
