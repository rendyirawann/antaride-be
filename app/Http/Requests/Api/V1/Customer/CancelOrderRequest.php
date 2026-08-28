<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Customer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Batalkan order.
 */
class CancelOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Kepemilikan order diperiksa controller lewat `ownedOrder()`.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * Alasan WAJIB, dan harus salah satu dari daftar milik penumpang.
             *
             * Wajib karena tanpa alasan, tidak ada cara mengetahui kenapa
             * pembatalan di satu zona naik dua kali lipat minggu ini — dan itu
             * salah satu angka yang paling penting untuk kelangsungan platform.
             *
             * `actor_type = user` di aturan `exists` yang mencegah aplikasi
             * mengirim kode alasan milik driver atau admin. Action juga
             * memeriksanya, tapi ditolak di sini menghasilkan 422 dengan pesan
             * yang jelas alih-alih diabaikan diam-diam.
             */
            'reason_code' => [
                'required',
                'string',
                Rule::exists('cancellation_reasons', 'code')
                    ->where('actor_type', 'user')
                    ->where('is_active', true),
            ],

            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
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
        ];
    }
}
