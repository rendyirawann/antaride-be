<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 *
 * ============================================================================
 *  KENAPA FACTORY INI DITULIS ULANG SEPENUHNYA
 * ============================================================================
 *  Isi sebelumnya adalah bawaan Laravel: name, email, email_verified_at,
 *  password, remember_token — dan referensi ke `App\Models\User` yang sudah
 *  tidak ada di proyek ini.
 *
 *  Akibatnya `User::factory()->create()` gagal dengan SQL error, bukan dengan
 *  pesan yang menjelaskan apa pun:
 *
 *    - `email_verified_at` tidak ada di tabel; yang ada `phone_verified_at`,
 *      karena identitas utama di Indonesia adalah nomor HP, bukan email.
 *    - `phone` NOT NULL dan UNIQUE, dan tidak diisi sama sekali.
 *    - `name` NOT NULL, dan panjangnya dibatasi 120.
 *
 *  Yang membuatnya mahal: factory yang rusak bukan sekadar merepotkan, dia
 *  mengubah cara test ditulis. Orang berhenti memakai factory dan mulai
 *  menyalin blok `DB::table('users')->insert([...])` dua puluh baris ke setiap
 *  test — dan setiap kali skema berubah, dua puluh tempat harus ikut berubah.
 * ============================================================================
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * Password yang sama dipakai untuk seluruh factory dalam satu proses.
     *
     * Hash bcrypt itu mahal secara sengaja. Menghitungnya ulang untuk setiap
     * user yang dibuat akan membuat test suite lambat tanpa alasan.
     */
    protected static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'phone' => $this->indonesianPhone(),
            'name' => fake()->name(),

            // Email opsional, dan itu memang bentuk nyatanya: sebagian besar
            // pengguna mendaftar hanya dengan nomor HP.
            'email' => fake()->unique()->safeEmail(),

            'password' => static::$password ??= Hash::make('password'),
            'status' => 'active',
            'phone_verified_at' => now(),
            'referral_code' => strtoupper(Str::random(8)),

            /*
             * Kolom nullable ini disebut EKSPLISIT walaupun nilainya null.
             *
             * `Model::preventAccessingMissingAttributes()` aktif di luar
             * produksi, dan model hasil `create()` hanya memuat atribut yang
             * memang disebut. Kolom yang tidak disebut TIDAK ada di array
             * atributnya, jadi mengaksesnya melempar MissingAttributeException —
             * bukan mengembalikan null.
             *
             * Akibatnya nyata dan membingungkan: kode yang berjalan baik di
             * produksi (di mana model dibaca dari database dan punya semua
             * kolom) gagal dengan 500 di test. Yang gagal bukan kodenya, tapi
             * bentuk model buatan factory.
             *
             * Menyebutkannya di sini membuat model buatan factory berperilaku
             * sama dengan model yang dibaca dari database.
             */
            'photo_url' => null,
            'gender' => null,
            'birth_date' => null,
            'referred_by_user_id' => null,
            'deletion_requested_at' => null,
        ];
    }

    /**
     * Nomor HP Indonesia dalam bentuk E.164 tanpa tanda plus: 62812xxxxxxx.
     *
     * Bentuk ini yang dipakai seluruh sistem, dan normalisasinya ada di
     * Identity. Factory harus menghasilkan bentuk yang sudah normal, bukan
     * bentuk yang perlu dinormalkan — kalau tidak, test tidak akan pernah
     * menyentuh data yang bentuknya sama dengan produksi.
     */
    private function indonesianPhone(): string
    {
        $prefixes = ['811', '812', '813', '821', '822', '852', '853', '857', '858', '895'];

        return '62'
            .fake()->randomElement($prefixes)
            .fake()->unique()->numerify('#######');
    }

    /**
     * Nomor HP belum diverifikasi OTP.
     *
     * Pengguna seperti ini boleh ada di database — dia sudah mendaftar tapi
     * belum menyelesaikan OTP — dan tidak boleh bisa membuat order.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'phone_verified_at' => null,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'suspended',
        ]);
    }

    /**
     * Pengguna tanpa email, yang merupakan mayoritas.
     */
    public function phoneOnly(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email' => null,
            'password' => null,
        ]);
    }
}
