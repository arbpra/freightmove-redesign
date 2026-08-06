<?php

namespace Database\Factories;

use App\Models\Carrier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\VehicleType>
 */
class VehicleTypeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $vehicle = fake()->randomElement([
            ['Semi Trailer', 'Flat Top', 25.0, '13.6m x 2.5m x 2.7m'],
            ['B-Double', 'Curtain Sider', 45.0, '26.0m x 2.5m x 2.7m'],
            ['Rigid Truck', 'Tautliner', 12.0, '8.0m x 2.4m x 2.5m'],
            ['Semi Trailer', 'Refrigerated', 22.0, '13.6m x 2.4m x 2.6m'],
            ['Prime Mover', 'Drop Deck', 28.0, '13.6m x 2.5m x 3.0m'],
            ['Road Train', 'Flat Top', 79.0, '36.5m x 2.5m x 2.7m'],
            ['Rigid Truck', 'Tipper', 15.0, '6.0m x 2.4m x 1.2m'],
            ['Car Carrier', 'Car Carrier', 18.0, '19.0m x 2.5m x 4.3m'],
        ]);

        return [
            'carrier_id' => Carrier::factory(),
            'name' => $vehicle[0],
            'trailer_type' => $vehicle[1],
            'max_weight_tons' => $vehicle[2],
            'dimensions' => $vehicle[3],
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
