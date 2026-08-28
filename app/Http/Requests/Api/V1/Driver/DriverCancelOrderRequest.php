<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Driver;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Driver membatalkan order yang sudah dia terima.
 *
 * Pembatalan oleh driver TIDAK pernah menagih penumpang, dan aturan itu ada di
 * Action. Yang ditegakkan di sini adalah bahwa kode alasannya memang milik
 * driver — tanpa filter `actor_type`, aplikasi driver bisa mengirim kode alasan
 * penumpang yang bertanda `charges_fee`, dan menagih biaya pembatalan kepada
 * penumpang atas pembatalan yang dia lakukan sendiri.
 */
class DriverCancelOrderRequest extends FormRequest
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
            'reason_code' => [
                'required',
                'string',
                Rule::exists('cancellation_reasons', 'code')
                    ->where('actor_type', 'driver')
                    ->where('is_active', true),
            ],

            /*
             * Catatan WAJIB untuk pembatalan oleh driver.
             *
             * Berbeda dari pembatalan penumpang, di mana catatan opsional.
             * Alasannya: pembatalan oleh driver merugikan penumpang yang sudah
             * menunggu, dan sebagian alasan bertanda `affects_driver_score`.
             * Angka yang menurunkan skor seseorang harus disertai keterangan
             * yang bisa dia bantah, bukan hanya kode.
             */
            'note' => ['required', 'string', 'min:5', 'max:500'],

            'lat' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'lng' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason_code.required' => 'Pilih alasan pembatalan.',
            'reason_code.exists' => 'Alasan pembatalan tidak dikenali.',
            'note.required' => 'Jelaskan singkat alasan pembatalan.',
            'note.min' => 'Keterangan terlalu singkat.',
        ];
    }
}
