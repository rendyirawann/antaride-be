<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Verifikasi kode OTP, lalu masuk atau mendaftar.
 */
class VerifyOtpRequest extends FormRequest
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
        $length = (int) config('antaride.otp.length', 4);

        return [
            'phone' => ['required', 'string', 'min:9', 'max:20'],

            // Panjangnya dibaca dari config, bukan ditulis 4. Kalau nanti
            // panjang OTP diubah menjadi 6, aturan di sini ikut berubah tanpa
            // ada yang perlu ingat memperbaruinya — dan tanpa itu, seluruh
            // verifikasi akan ditolak validasi sebelum sempat diperiksa.
            'code' => ['required', 'string', 'digits:'.$length],

            'purpose' => ['sometimes', 'string', 'in:login,register,change_phone'],

            /*
             * Identitas perangkat, semuanya opsional.
             *
             * Dipakai untuk notifikasi dan daftar "perangkat yang masuk" di
             * halaman keamanan. Opsional karena versi aplikasi lama tidak
             * mengirimkannya, dan menolak login karena itu berarti memaksa
             * seluruh pengguna memperbarui aplikasi sebelum bisa masuk.
             */
            'device_id' => ['sometimes', 'nullable', 'string', 'max:120'],
            'platform' => ['sometimes', 'nullable', 'string', 'in:android,ios,web,unknown'],
            'fcm_token' => ['sometimes', 'nullable', 'string', 'max:500'],
            'app_version' => ['sometimes', 'nullable', 'string', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $length = (int) config('antaride.otp.length', 4);

        return [
            'phone.required' => 'Nomor HP wajib diisi.',
            'code.required' => 'Kode wajib diisi.',
            'code.digits' => "Kode harus {$length} angka.",
        ];
    }

    public function purpose(): string
    {
        return (string) ($this->validated('purpose') ?? 'login');
    }
}
