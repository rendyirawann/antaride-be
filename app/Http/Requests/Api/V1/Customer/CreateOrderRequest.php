<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Customer;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Buat order baru.
 *
 * ============================================================================
 *  TIDAK ADA SATU PUN FIELD HARGA DI SINI
 * ============================================================================
 *  Yang dikirim client: `quote_id` dan `service_code`. Harganya dibaca backend
 *  dari quote di Redis.
 *
 *  Kalau ada field harga di sini — bahkan hanya `expected_total` "untuk
 *  dibandingkan" — akan ada kode berikutnya yang memakainya, dan sejak saat itu
 *  harga bisa datang dari client. Aplikasi mobile bisa dibongkar, dan HTTPS
 *  tidak melindungi apa pun dari pemilik perangkatnya sendiri.
 *
 *  Batas ini lebih mudah dijaga kalau fieldnya memang tidak pernah ada.
 * ============================================================================
 *
 *  Koordinat juga TIDAK diterima di sini. Titik penjemputan dan tujuan sudah
 *  ada di dalam quote; menerimanya lagi berarti dua sumber untuk hal yang sama,
 *  dan yang dari client bisa berbeda dari yang dipakai menghitung harga —
 *  artinya order 20 km yang dibayar dengan harga 2 km.
 */
class CreateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Kepemilikan quote diperiksa di Action, yang punya quote-nya.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'quote_id' => ['required', 'string', 'uuid'],
            'service_code' => ['required', 'string', 'in:ride_bike,ride_car,food,send,mart,shop'],
            'payment_method' => ['required', 'string', 'in:cash,wallet'],

            // Alamat dikirim client karena inilah yang ditulis penumpang sendiri
            // — hasil autocomplete atau koreksi manual. Yang TIDAK dipercaya
            // adalah koordinatnya, dan itu memang tidak diterima di sini.
            'pickup_address' => ['required', 'string', 'max:500'],
            'destination_address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'pickup_note' => ['sometimes', 'nullable', 'string', 'max:255'],

            'promo_code' => ['sometimes', 'nullable', 'string', 'max:40'],

            // Titik antar tambahan untuk layanan kirim barang.
            'stops' => ['sometimes', 'array', 'max:5'],
            'stops.*.address' => ['required_with:stops', 'string', 'max:500'],
            'stops.*.recipient_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'stops.*.recipient_phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'stops.*.note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quote_id.required' => 'Estimasi harga wajib disertakan.',
            'quote_id.uuid' => 'Estimasi harga tidak valid.',
            'service_code.required' => 'Pilih layanan.',
            'payment_method.required' => 'Pilih metode pembayaran.',
            'payment_method.in' => 'Metode pembayaran tidak dikenali.',
            'pickup_address.required' => 'Alamat penjemputan wajib diisi.',
            'stops.max' => 'Maksimal 5 titik antar.',
        ];
    }
}
