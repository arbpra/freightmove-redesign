<?php

namespace Database\Factories;

use App\Enums\VerificationStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\UserProfile>
 */
class UserProfileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $city = fake()->randomElement([
            ['Sydney', 'NSW', '2000'],
            ['Melbourne', 'VIC', '3000'],
            ['Brisbane', 'QLD', '4000'],
            ['Perth', 'WA', '6000'],
            ['Adelaide', 'SA', '5000'],
            ['Newcastle', 'NSW', '2300'],
            ['Geelong', 'VIC', '3220'],
            ['Toowoomba', 'QLD', '4350'],
        ]);

        return [
            'user_id' => User::factory(),
            'company_name' => fake()->company().' '.fake()->randomElement(['Logistics', 'Transport', 'Freight', 'Haulage']),
            'abn_acn' => fake()->numerify('## ### ### ###'),
            'business_type' => fake()->randomElement(['Sole Trader', 'Partnership', 'Company', 'Trust']),
            'address_line_1' => fake()->buildingNumber().' '.fake()->streetName().' '.fake()->randomElement(['St', 'Rd', 'Ave', 'Hwy']),
            'city' => $city[0],
            'state' => $city[1],
            'postal_code' => $city[2],
            'country' => 'Australia',
            'bio' => fake()->sentences(2, true),
            'verification_status' => VerificationStatus::Unverified,
            'rating' => null,
            'completed_jobs_count' => 0,
        ];
    }

    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'verification_status' => VerificationStatus::Verified,
            'rating' => fake()->randomFloat(2, 3.6, 5.0),
            'completed_jobs_count' => fake()->numberBetween(4, 180),
        ]);
    }

    public function awaitingVerification(): static
    {
        return $this->state(fn (array $attributes) => [
            'verification_status' => VerificationStatus::Pending,
        ]);
    }
}
