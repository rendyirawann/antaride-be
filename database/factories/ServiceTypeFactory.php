<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Models\ServiceType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceType>
 *
 * `code` dibatasi CHECK constraint ke enam nilai yang sudah ditentukan, jadi
 * factory ini TIDAK boleh mengarang kode acak — yang keluar hanya penolakan
 * database dengan pesan yang tidak menjelaskan penyebabnya.
 *
 * Bawaannya `ride_bike` karena itu layanan yang dipakai hampir semua test:
 * paling sederhana (tanpa merchant, tanpa multi-stop) dan paling banyak
 * ordernya di kenyataan.
 */
class ServiceTypeFactory extends Factory
{
    protected $model = ServiceType::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'ride_bike',
            'name' => 'AntarRide Bike',
            'description' => 'Ojek online',
            'vehicle_class' => 'motorcycle',
            'is_active' => true,
            'sort_order' => 1,
            'requires_merchant' => false,
            'requires_multi_stop' => false,
            'requires_proof_photo' => false,
            'max_stops' => 1,
        ];
    }

    public function car(): static
    {
        return $this->state(fn (): array => [
            'code' => 'ride_car',
            'name' => 'AntarRide Car',
            'vehicle_class' => 'car_economy',
            'sort_order' => 2,
        ]);
    }

    public function food(): static
    {
        return $this->state(fn (): array => [
            'code' => 'food',
            'name' => 'AntarFood',
            'vehicle_class' => 'motorcycle',
            'requires_merchant' => true,
            'sort_order' => 3,
        ]);
    }

    public function send(): static
    {
        return $this->state(fn (): array => [
            'code' => 'send',
            'name' => 'AntarSend',
            'vehicle_class' => 'motorcycle',
            'requires_multi_stop' => true,
            'requires_proof_photo' => true,
            'max_stops' => 5,
            'max_weight_gram' => 20_000,
            'sort_order' => 4,
        ]);
    }
}
