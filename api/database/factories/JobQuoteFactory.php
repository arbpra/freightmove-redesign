<?php

namespace Database\Factories;

use App\Enums\QuoteStatus;
use App\Models\FreightJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\JobQuote>
 */
class JobQuoteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_id' => FreightJob::factory(),
            'carrier_id' => User::factory()->carrier(),
            'amount' => fake()->numberBetween(650, 8500),
            'currency' => 'AUD',
            'estimated_delivery_date' => fake()->dateTimeBetween('+3 days', '+6 weeks'),
            'notes' => fake()->randomElement([
                'Can collect same day if booked before noon.',
                'Price includes tailgate loading and transit insurance.',
                'Return leg available, happy to discuss a discount.',
                'Refrigerated unit maintained at -18C for the full run.',
                null,
            ]),
            'status' => QuoteStatus::Pending,
            'match_score' => fake()->randomFloat(2, 45, 99),
            'expires_at' => now()->addDays(fake()->numberBetween(3, 14)),
        ];
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => ['status' => QuoteStatus::Accepted]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => ['status' => QuoteStatus::Rejected]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => QuoteStatus::Expired,
            'expires_at' => now()->subDays(fake()->numberBetween(1, 20)),
        ]);
    }
}
