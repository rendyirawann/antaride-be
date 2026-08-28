<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Customer;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Penilaian penumpang untuk driver.
 */
class SubmitRatingRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Kepemilikan order diperiksa Action, bukan di sini — pemeriksaan yang
        // hanya ada di FormRequest tidak ikut terbawa ke pemanggil lain.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * 1 sampai 5, sama dengan CHECK constraint di database.
             *
             * Keduanya harus sepakat: yang di sini menghasilkan pesan yang bisa
             * dibaca, yang di database menjadi jaring terakhir untuk jalur lain —
             * seeder, panel admin, atau perbaikan data manual.
             */
            'score' => ['required', 'integer', 'between:1,5'],

            /*
             * Tag alasan, misalnya "kendaraan bersih" atau "mengebut".
             *
             * Daftar nilainya TIDAK dibatasi `in:` di sini. Tag adalah hal yang
             * akan bertambah seiring waktu, dan `in:` yang ditulis di sini berarti
             * setiap tag baru menuntut deploy backend — lalu aplikasi versi baru
             * mengirim tag yang backend lama tolak dengan 422.
             *
             * Yang dibatasi: jumlah dan panjangnya, supaya tidak jadi jalan
             * menitipkan data sembarang ke kolom jsonb.
             */
            'tags' => ['sometimes', 'array', 'max:5'],
            'tags.*' => ['string', 'max:40'],

            /*
             * Komentar opsional dan dibatasi 1000 karakter.
             *
             * Dibatasi karena isinya dibaca manusia di panel admin saat ada
             * sengketa — dan komentar sepuluh ribu karakter tidak akan dibaca
             * siapa pun, jadi membiarkannya masuk hanya menambah data yang tidak
             * berguna.
             */
            'comment' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'score.required' => 'Beri bintang 1 sampai 5 untuk driver.',
            'score.between' => 'Bintang harus antara 1 sampai 5.',
            'tags.max' => 'Maksimal 5 alasan yang bisa dipilih.',
            'comment.max' => 'Komentar maksimal 1000 karakter.',
        ];
    }

    /**
     * @return list<string>
     */
    public function tagList(): array
    {
        $tags = $this->validated('tags') ?? [];

        // Duplikat dibuang dan nilainya dinormalkan. Aplikasi bisa mengirim tag
        // yang sama dua kali kalau tombolnya ditekan cepat, dan tag ganda di
        // jsonb membuat hitungan di panel admin salah.
        return array_values(array_unique(array_map(
            static fn (string $tag): string => trim($tag),
            array_filter($tags, static fn (mixed $t): bool => is_string($t) && trim($t) !== ''),
        )));
    }
}
