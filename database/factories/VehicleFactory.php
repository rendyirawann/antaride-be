<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Driver\Models\Driver;
use App\Domain\Driver\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vehicle>
 */
class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'driver_id' => Driver::factory(),
            'type' => 'motorcycle',
            'brand' => fake()->randomElement(['Honda', 'Yamaha', 'Suzuki']),
            'model' => fake()->randomElement(['Vario 125', 'Beat', 'NMAX', 'Mio']),
            'year' => fake()->numberBetween(2018, 2025),
            'color' => fake()->randomElement(['Hitam', 'Putih', 'Merah', 'Biru']),

            // Plat Sumatera Utara. Bentuk yang benar penting: panel admin
            // mencari driver dengan plat nomor, dan pencarian trigram terhadap
            // format yang salah tidak akan pernah menemukan apa pun.
            'plate_number' => 'BK '.fake()->unique()->numberBetween(1000, 9999).' '
                .strtoupper(fake()->lexify('??')),

            'stnk_number' => (string) fake()->numerify('##############'),
            'stnk_expires_at' => now()->addYear(),
            'capacity' => 1,
            'is_active' => true,
        ];
    }

    public function car(): static
    {
        return $this->state(fn (): array => [
            'type' => 'car_economy',
            'brand' => fake()->randomElement(['Toyota', 'Daihatsu', 'Honda']),
            'model' => fake()->randomElement(['Avanza', 'Xenia', 'Brio', 'Calya']),
            'capacity' => 4,
            'plate_number' => 'BK '.fake()->unique()->numberBetween(1000, 9999).' '
                .strtoupper(fake()->lexify('??')),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
