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

    /**
     * Verified says nothing about reputation.
     *
     * This state used to invent a rating between 3.6 and 5.0 and a job count up
     * to 180. Both are **derived** columns — `ReputationService` computes them
     * from reviews and completed loads — so those numbers were a reputation
     * with nothing behind it, displayed to shippers beside every quote. If a
     * test or a seeder needs a rated carrier, it should create the reviews that
     * earn the rating and let the service work it out.
     */
    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'verification_status' => VerificationStatus::Verified,
        ]);
    }

    public function awaitingVerification(): static
    {
        return $this->state(fn (array $attributes) => [
            'verification_status' => VerificationStatus::Pending,
        ]);
    }
}
