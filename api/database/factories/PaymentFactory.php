<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\FreightJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_id' => FreightJob::factory(),
            'payer_id' => User::factory()->shipper(),
            'payee_id' => User::factory()->carrier(),
            'amount' => fake()->numberBetween(650, 8500),
            'currency' => 'AUD',
            'status' => PaymentStatus::Pending,
            'gateway_reference' => null,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::Paid,
            'gateway_reference' => 'ch_'.fake()->bothify('##??##??##??'),
        ]);
    }
}
