<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Customer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Perbarui profil.
 *
 * ============================================================================
 *  NOMOR HP TIDAK BISA DIUBAH DI SINI
 * ============================================================================
 *  Nomor HP adalah identitas login. Mengubahnya menuntut verifikasi OTP ke nomor
 *  BARU — kalau tidak, siapa pun yang sempat memegang HP orang lain yang sudah
 *  login bisa memindahkan akunnya ke nomor sendiri, dan pemilik aslinya
 *  kehilangan akses beserta seluruh saldonya.
 *
 *  Alurnya ada di endpoint tersendiri dengan purpose `change_phone`.
 * ============================================================================
 */
class UpdateProfileRequest extends FormRequest
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
        $userId = $this->user()?->getKey();

        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],

            /*
             * Email unik, TAPI mengabaikan baris pengguna ini sendiri.
             *
             * Tanpa `ignore`, pengguna yang menyimpan profilnya tanpa mengubah
             * email akan ditolak dengan "email sudah dipakai" — oleh dirinya
             * sendiri. Itu bug yang selalu muncul di form profil dan selalu
             * terlihat seperti masalah data.
             *
             * Kolomnya citext, jadi keunikannya sudah tidak peka huruf besar
             * kecil di tingkat database.
             */
            'email' => [
                'sometimes',
                'nullable',
                'email:rfc',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],

            'gender' => ['sometimes', 'nullable', 'string', 'in:male,female,other'],

            /*
             * Tanggal lahir dibatasi ke rentang yang mungkin.
             *
             * Batas 17 tahun bukan pembatasan pemakaian — anak sekolah memakai
             * layanan ini dengan wajar — tapi penyaring salah ketik: tanggal
             * hari ini atau tahun 1900 hampir selalu berarti pengguna salah
             * memilih di kalender, dan menyimpannya akan membuat laporan
             * demografi tidak berarti.
             */
            'birth_date' => [
                'sometimes',
                'nullable',
                'date',
                'before:'.now()->subYears(10)->toDateString(),
                'after:'.now()->subYears(100)->toDateString(),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.min' => 'Nama terlalu pendek.',
            'email.email' => 'Format email tidak valid.',
            'email.unique' => 'Email ini sudah dipakai akun lain.',
            'birth_date.before' => 'Tanggal lahir tidak masuk akal.',
            'birth_date.after' => 'Tanggal lahir tidak masuk akal.',
        ];
    }
}
