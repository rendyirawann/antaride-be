<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Customer;

use App\Domain\Shared\ValueObjects\Coordinate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Minta estimasi harga.
 */
class CreateQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * Rentang koordinatnya dibatasi ke rentang yang sah secara global,
             * bukan ke kotak Indonesia.
             *
             * Yang menentukan apakah sebuah titik terlayani adalah resolusi
             * zona, dan itu satu-satunya tempat yang tahu batas area layanan.
             * Membatasi ke kotak Indonesia di sini berarti ada DUA definisi
             * "area yang dilayani", dan yang di FormRequest akan tertinggal
             * begitu Antaride membuka kota di luar kotak yang saya tulis
             * hari ini.
             *
             * Pesan errornya juga berbeda kelas: ditolak resolusi zona
             * menghasilkan "di luar area layanan Antaride", ditolak di sini
             * menghasilkan "lat harus antara ... dan ..." yang tidak berarti
             * apa pun bagi penumpang.
             */
            'pickup' => ['required', 'array'],
            'pickup.lat' => ['required', 'numeric', 'between:-90,90'],
            'pickup.lng' => ['required', 'numeric', 'between:-180,180'],

            'destination' => ['required', 'array'],
            'destination.lat' => ['required', 'numeric', 'between:-90,90'],
            'destination.lng' => ['required', 'numeric', 'between:-180,180'],

            'stops' => ['sometimes', 'array', 'max:5'],
            'stops.*.lat' => ['required_with:stops', 'numeric', 'between:-90,90'],
            'stops.*.lng' => ['required_with:stops', 'numeric', 'between:-180,180'],

            // Kalau tidak disebut, seluruh layanan yang tersedia dihitung. Itu
            // yang dipakai layar utama; layar "pesan ulang" menyebut satu
            // layanan saja supaya tidak menghitung tarif yang tidak dilihat.
            'service_codes' => ['sometimes', 'array'],
            'service_codes.*' => ['string', 'in:ride_bike,ride_car,food,send,mart,shop'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pickup.required' => 'Titik penjemputan wajib diisi.',
            'destination.required' => 'Titik tujuan wajib diisi.',
            'stops.max' => 'Maksimal 5 titik antar.',
        ];
    }

    /**
     * Titik antar sebagai Coordinate.
     *
     * @return array<int, Coordinate>
     */
    public function stopCoordinates(): array
    {
        return array_map(
            static fn (array $stop): Coordinate => Coordinate::of(
                (float) $stop['lat'],
                (float) $stop['lng'],
            ),
            $this->validated('stops') ?? [],
        );
    }
}
