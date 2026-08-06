<?php

namespace Database\Factories;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SupportTicket>
 */
class SupportTicketFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'subject' => fake()->randomElement([
                'Cannot upload insurance certificate',
                'Quote disappeared from my dashboard',
                'How do I change my ABN?',
                'Shipper has not responded to accepted quote',
                'Notification emails are not arriving',
            ]),
            'message' => fake()->paragraph(3),
            'status' => TicketStatus::Open,
            'priority' => fake()->randomElement(TicketPriority::cases()),
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn (array $attributes) => ['status' => TicketStatus::Resolved]);
    }
}
