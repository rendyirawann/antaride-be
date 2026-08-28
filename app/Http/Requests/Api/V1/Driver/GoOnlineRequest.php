<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Driver;

use App\Domain\Shared\ValueObjects\Coordinate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Driver menyatakan diri siap menerima order.
 */
class GoOnlineRequest extends FormRequest
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
            // Posisi WAJIB: tanpa itu tidak ada zona yang bisa ditentukan, dan
            // tanpa zona driver tidak bisa didaftarkan di indeks ketersediaan.
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],

            /*
             * Kendaraan opsional.
             *
             * Sebagian besar driver hanya punya satu, dan menuntutnya berarti
             * satu langkah tambahan setiap kali online untuk pilihan yang cuma
             * ada satu. Kalau tidak disebut, kendaraan aktif pertamanya yang
             * dipakai.
             *
             * Kepemilikan kendaraan diperiksa Action, bukan di sini: aturan
             * `exists` biasa akan menerima kendaraan milik driver LAIN.
             */
            'vehicle_id' => ['sometimes', 'nullable', 'integer', 'min:1'],

            // Kalau tidak disebut, semua layanan yang dia berhak dan dia
            // aktifkan.
            'service_codes' => ['sometimes', 'nullable', 'array'],
            'service_codes.*' => ['string', 'in:ride_bike,ride_car,food,send,mart,shop'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lat.required' => 'Posisi wajib dikirim untuk mulai bekerja.',
            'lng.required' => 'Posisi wajib dikirim untuk mulai bekerja.',
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
