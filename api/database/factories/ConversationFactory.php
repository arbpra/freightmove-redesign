<?php

namespace Database\Factories;

use App\Models\FreightJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Conversation>
 */
class ConversationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_id' => FreightJob::factory(),
            'participant_one_id' => User::factory()->shipper(),
            'participant_two_id' => User::factory()->carrier(),
        ];
    }
}
