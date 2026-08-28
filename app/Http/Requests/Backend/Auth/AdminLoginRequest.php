<?php

declare(strict_types=1);

namespace App\Http\Requests\Backend\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Masuk panel admin.
 */
class AdminLoginRequest extends FormRequest
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
             * Email divalidasi bentuknya, TIDAK keberadaannya.
             *
             * Aturan `exists:admins,email` akan membuat pesan validasi
             * membedakan email yang terdaftar dari yang tidak — dan itu
             * membocorkan daftar staf ke siapa pun yang mencoba. Keberadaan akun
             * diperiksa di controller, dengan pesan yang sama untuk kedua kasus.
             */
            'email' => ['required', 'string', 'email:rfc', 'max:255'],

            /*
             * Kata sandi hanya diperiksa keberadaannya di sini.
             *
             * Tanpa aturan panjang minimum, dan itu disengaja: aturan panjang di
             * halaman LOGIN memberi tahu penyerang berapa panjang minimum kata
             * sandi di sistem ini, dan menyempitkan ruang tebakannya. Aturan
             * kekuatan kata sandi ada di tempat yang benar — halaman pembuatan
             * dan penggantian kata sandi.
             */
            'password' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Email wajib diisi.',
            'email.email' => 'Format email tidak valid.',
            'password.required' => 'Kata sandi wajib diisi.',
        ];
    }
}
