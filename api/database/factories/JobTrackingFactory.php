<?php

namespace Database\Factories;

use App\Models\FreightJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\JobTracking>
 */
class JobTrackingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_id' => FreightJob::factory(),
            'current_status' => fake()->randomElement([
                'awaiting_pickup', 'in_transit', 'at_depot', 'out_for_delivery', 'delivered',
            ]),
            'last_location' => fake()->randomElement([
                'Sydney, NSW', 'Goulburn, NSW', 'Albury, VIC', 'Melbourne, VIC', 'Dubbo, NSW',
            ]),
            'eta' => now()->addDays(fake()->numberBetween(1, 10)),
        ];
    }
}
