<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Notification>
 */
class NotificationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => fake()->randomElement([
                'quote_received', 'quote_accepted', 'job_matched', 'message_received', 'verification_approved',
            ]),
            'title' => fake()->sentence(4),
            'body' => fake()->sentence(10),
            'is_read' => false,
            'related_type' => null,
            'related_id' => null,
        ];
    }

    public function read(): static
    {
        return $this->state(fn (array $attributes) => ['is_read' => true]);
    }
}
