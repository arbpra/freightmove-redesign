<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Carrier>
 */
class CarrierFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->carrier(),
            'fleet_size' => fake()->numberBetween(1, 40),
            'service_radius_km' => fake()->randomElement([150, 300, 500, 800, 1500, 3000]),
            'preferred_regions' => fake()->randomElements(
                ['NSW', 'VIC', 'QLD', 'WA', 'SA', 'TAS', 'NT', 'ACT'],
                fake()->numberBetween(1, 4)
            ),
            'insurance_provider' => fake()->randomElement([
                'National Transport Insurance', 'Zurich Australia', 'QBE', 'Allianz', 'GT Insurance',
            ]),
            'insurance_policy_number' => strtoupper(fake()->bothify('??-########')),
            'operating_since' => fake()->numberBetween(1985, 2024),
        ];
    }
}
