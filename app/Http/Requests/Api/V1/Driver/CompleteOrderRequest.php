<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Driver;

use App\Domain\Shared\ValueObjects\Coordinate;
use App\Domain\Shared\ValueObjects\Polyline;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Driver menyelesaikan order.
 */
class CompleteOrderRequest extends FormRequest
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
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],

            /*
             * Jejak GPS perjalanan, dikirim sebagai polyline terenkode.
             *
             * Opsional karena bisa hilang: aplikasi yang dimatikan paksa di
             * tengah perjalanan kehilangan bufernya, dan menolak penyelesaian
             * order karena itu berarti perjalanan yang sudah selesai tidak bisa
             * ditutup — dan driver terjebak, karena partial unique index
             * melarangnya menerima order berikutnya.
             */
            'actual_polyline' => ['sometimes', 'nullable', 'string', 'max:65535'],

            /*
             * Jarak yang dilaporkan aplikasi.
             *
             * Diterima, tapi hanya dipakai kalau polyline tidak ada. Angka ini
             * yang paling menarik untuk dipalsukan — jarak lebih besar berarti
             * order masuk antrean review dan ongkosnya bisa dinaikkan — jadi
             * yang lebih dipercaya adalah jejak titiknya. Lihat CompleteOrder.
             */
            'actual_distance_m' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:2000000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lat.required' => 'Posisi wajib dikirim saat menyelesaikan order.',
            'lng.required' => 'Posisi wajib dikirim saat menyelesaikan order.',
        ];
    }

    public function coordinate(): Coordinate
    {
        return Coordinate::of(
            (float) $this->validated('lat'),
            (float) $this->validated('lng'),
        );
    }

    /**
     * Polyline yang sudah didekode, atau null.
     *
     * Polyline yang tidak bisa didekode diperlakukan sebagai TIDAK ADA, bukan
     * sebagai error. Alasannya sama seperti kenapa fieldnya opsional: order yang
     * sudah selesai harus bisa ditutup. Yang hilang hanya jejak petanya, dan
     * jarak aktualnya jatuh ke angka yang dilaporkan aplikasi.
     */
    public function actualRoute(): ?Polyline
    {
        $encoded = $this->validated('actual_polyline');

        if ($encoded === null || $encoded === '') {
            return null;
        }

        try {
            $polyline = Polyline::decode($encoded);
        } catch (\Throwable) {
            return null;
        }

        return $polyline->isEmpty() ? null : $polyline;
    }
}
