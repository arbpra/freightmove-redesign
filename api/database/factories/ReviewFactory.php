<?php

namespace Database\Factories;

use App\Models\FreightJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Review>
 */
class ReviewFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_id' => FreightJob::factory(),
            'reviewer_id' => User::factory(),
            'reviewed_user_id' => User::factory(),
            'rating' => fake()->numberBetween(3, 5),
            'comment' => fake()->randomElement([
                'Picked up on time and kept me updated the whole way.',
                'Load arrived in perfect condition. Would book again.',
                'Good communication, driver was professional.',
                'Slight delay at pickup but delivered within the window.',
                'Straightforward to deal with, fair price.',
            ]),
        ];
    }
}
