<?php

declare(strict_types=1);

namespace App\Http\Requests\Backend\Pricing;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Tarif baru.
 *
 * ============================================================================
 *  SELURUH NOMINAL DIMASUKKAN DALAM RUPIAH UTUH
 * ============================================================================
 *  Tidak ada rupiah desimal di sistem ini, dan form-nya tidak boleh menerimanya.
 *  Aturan `integer` di sini yang menegakkannya — kalau `numeric`, angka 4500.75
 *  akan lolos dan dipotong secara diam-diam saat disimpan ke BIGINT.
 *
 *  Yang terjadi kalau dibiarkan: staf ops mengetik "4.500,50" dengan pemisah
 *  ribuan, PHP membacanya sebagai 4.5, dan tarif dasarnya menjadi Rp 4 untuk
 *  seluruh kota.
 * ============================================================================
 */
class StorePricingRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Otorisasi ditegakkan `can:pricing.propose` di route.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'service_type_id' => ['required', 'integer', 'exists:service_types,id'],

            // NULL berarti berlaku untuk semua zona. Tarif spesifik zona menang
            // atas yang ini — lihat ResolvePricingRule.
            'zone_id' => ['nullable', 'integer', 'exists:zones,id'],

            'base_fare' => ['required', 'integer', 'min:0', 'max:1000000'],
            'per_km' => ['required', 'integer', 'min:0', 'max:100000'],
            'per_minute' => ['required', 'integer', 'min:0', 'max:100000'],
            'minimum_fare' => ['required', 'integer', 'min:0', 'max:1000000'],

            /*
             * Jarak gratis: bagian awal perjalanan yang tidak dikenai tarif
             * per-km.
             *
             * Dibatasi 5 km. Di atas itu, tarif per-km hampir tidak berpengaruh
             * untuk order biasa — dan yang terjadi adalah tarif yang terlihat
             * dikonfigurasi tapi tidak berefek, yang jauh lebih membingungkan
             * daripada tarif yang salah.
             */
            'free_distance_m' => ['required', 'integer', 'min:0', 'max:5000'],

            'platform_fee' => ['required', 'integer', 'min:0', 'max:100000'],

            'commission_percent' => ['required', 'numeric', 'min:0', 'max:50'],

            /*
             * Batas tarif yang diatur pemerintah.
             *
             * Keduanya opsional karena tidak semua layanan diatur. Yang diatur
             * (ojek dan taksi online) punya batas Permenhub per kilometer, dan
             * angka di sini adalah batas untuk SELURUH ongkos transport, bukan
             * per km.
             */
            'min_fare_regulated' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'max_fare_regulated' => ['nullable', 'integer', 'min:0', 'max:10000000'],

            'packaging_fee' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'insurance_fee' => ['nullable', 'integer', 'min:0', 'max:100000'],

            /*
             * Mulai berlaku boleh di masa depan, TIDAK boleh di masa lalu.
             *
             * Tarif yang berlaku retroaktif berarti order yang sudah selesai
             * dihitung dengan tarif yang belum ada saat itu — dan ongkos yang
             * sudah ditagih tidak bisa diubah. Yang tersisa hanya laporan yang
             * tidak cocok dengan struk penumpang.
             */
            'effective_from' => ['required', 'date', 'after_or_equal:now'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'service_type_id.required' => 'Pilih layanan.',
            'base_fare.integer' => 'Tarif dasar harus rupiah utuh, tanpa desimal.',
            'per_km.integer' => 'Tarif per km harus rupiah utuh, tanpa desimal.',
            'commission_percent.max' => 'Komisi di atas 50% tidak akan diterima driver mana pun.',
            'effective_from.after_or_equal' => 'Tarif tidak boleh berlaku retroaktif. '
                .'Order yang sudah selesai ongkosnya tidak bisa diubah.',
            'free_distance_m.max' => 'Jarak gratis maksimal 5 km.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $data = $validator->getData();

            /*
             * Batas atas regulasi harus di atas batas bawahnya.
             *
             * Kalau terbalik, `Money::clamp` akan menaikkan ke minimum lalu
             * menurunkan ke maksimum — dan hasil akhirnya adalah nilai maksimum
             * untuk SETIAP order, sebesar apa pun jaraknya. Tarif per-km berhenti
             * berpengaruh sama sekali, tanpa satu pun error.
             */
            $min = $data['min_fare_regulated'] ?? null;
            $max = $data['max_fare_regulated'] ?? null;

            if ($min !== null && $max !== null && (int) $max <= (int) $min) {
                $validator->errors()->add(
                    'max_fare_regulated',
                    'Batas atas regulasi harus lebih besar dari batas bawahnya. '
                    .'Kalau terbalik, setiap order akan ditagih sebesar batas atasnya.'
                );
            }

            /*
             * Tarif minimum tidak boleh di bawah tarif dasar.
             *
             * Bukan kesalahan fatal — `FareCalculator` menaikkan ongkos ke tarif
             * minimum setelah surge, jadi minimum di bawah dasar hanya berarti
             * tidak pernah berpengaruh. Tapi itu berarti kolomnya terlihat
             * dikonfigurasi padahal tidak melakukan apa pun, dan orang
             * berikutnya akan menghabiskan waktu mencari kenapa mengubahnya
             * tidak berefek.
             */
            $dasar = $data['base_fare'] ?? null;
            $minimum = $data['minimum_fare'] ?? null;

            if ($dasar !== null && $minimum !== null && (int) $minimum < (int) $dasar) {
                $validator->errors()->add(
                    'minimum_fare',
                    'Tarif minimum di bawah tarif dasar tidak akan pernah berpengaruh, '
                    .'karena ongkos selalu dimulai dari tarif dasar.'
                );
            }
        });
    }
}
