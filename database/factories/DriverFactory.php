<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Driver\Models\Driver;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Driver>
 *
 * Nilai bawaannya adalah driver yang LAYAK MENERIMA ORDER: sudah aktif,
 * terverifikasi, rating dan acceptance_rate sehat.
 *
 * Ini pilihan sadar. Sebagian besar test tentang matching, penawaran, dan order
 * membutuhkan driver yang bisa bekerja, dan kalau bawaannya `pending_review`
 * setiap test harus menambahkan tiga baris penyesuaian sebelum bisa mulai. Yang
 * terjadi berikutnya adalah orang menyalin blok penyesuaian itu, dan satu di
 * antaranya akan salah.
 *
 * Keadaan yang TIDAK layak dinyatakan eksplisit lewat state: pendingReview(),
 * suspended(), newcomer(), unreliable().
 */
class DriverFactory extends Factory
{
    protected $model = Driver::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'full_name' => fake()->name(),
            'nik' => (string) fake()->unique()->numerify('################'),
            'address' => fake()->streetAddress(),
            'city' => 'Medan',
            'emergency_contact_name' => fake()->name(),
            'emergency_contact_phone' => '628'.fake()->numerify('##########'),

            'status' => 'active',
            'verified_at' => now()->subMonths(3),

            // Angka yang sehat tapi bukan sempurna. Nilai sempurna (5.00 / 100)
            // menyembunyikan bug pembulatan dan perbandingan batas, karena
            // hasilnya sama untuk `>` maupun `>=`.
            'rating_avg' => 4.75,
            'rating_count' => 128,
            'acceptance_rate' => 88.50,
            'cancellation_rate' => 3.20,
            'completed_orders' => 240,

            'joined_at' => now()->subMonths(3),
        ];
    }

    /**
     * Driver baru: belum punya riwayat sama sekali.
     *
     * Rating 5,00 dengan nol penilaian, acceptance_rate 100 tanpa satu pun
     * penawaran. Ini keadaan yang paling mudah salah diperlakukan skoring —
     * angkanya terlihat sempurna padahal tidak berarti apa pun.
     */
    public function newcomer(): static
    {
        return $this->state(fn (): array => [
            'rating_avg' => 5.00,
            'rating_count' => 0,
            'acceptance_rate' => 100.00,
            'cancellation_rate' => 0.00,
            'completed_orders' => 0,
            'joined_at' => now()->subDays(2),
            'verified_at' => now()->subDays(2),
        ]);
    }

    /**
     * Driver yang sering membatalkan. Harus berada di urutan bawah skoring.
     */
    public function unreliable(): static
    {
        return $this->state(fn (): array => [
            'rating_avg' => 3.10,
            'rating_count' => 64,
            'acceptance_rate' => 32.00,
            'cancellation_rate' => 41.00,
        ]);
    }

    public function pendingReview(): static
    {
        return $this->state(fn (): array => [
            'status' => 'pending_review',
            'verified_at' => null,
            'completed_orders' => 0,
            'rating_count' => 0,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => ['status' => 'suspended']);
    }

    public function banned(): static
    {
        return $this->state(fn (): array => ['status' => 'banned']);
    }
}
