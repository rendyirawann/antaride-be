<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Minta kode OTP.
 */
class RequestOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Endpoint publik: yang belum punya akun pun harus bisa memakainya.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * Validasi bentuknya SENGAJA longgar di sini.
             *
             * Normalisasi dan pemeriksaan bentuk yang sebenarnya ada di
             * `PhoneNumber::normalize()`, dan itu satu-satunya tempat yang tahu
             * bentuk kanoniknya. Menuliskan regex nomor HP di sini berarti ada
             * DUA definisi "nomor HP yang valid" yang harus sepakat — dan yang
             * di FormRequest akan tertinggal, sehingga nomor yang sah ditolak
             * sebelum Action-nya sempat menormalkannya.
             *
             * Yang diperiksa di sini hanya yang tidak mungkin: terlalu pendek
             * untuk nomor apa pun, atau terlalu panjang untuk masuk akal.
             */
            'phone' => ['required', 'string', 'min:9', 'max:20'],

            'purpose' => ['sometimes', 'string', 'in:login,register,change_phone'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.required' => 'Nomor HP wajib diisi.',
            'phone.min' => 'Nomor HP terlalu pendek.',
            'phone.max' => 'Nomor HP terlalu panjang.',
        ];
    }

    public function purpose(): string
    {
        return (string) ($this->validated('purpose') ?? 'login');
    }
}
