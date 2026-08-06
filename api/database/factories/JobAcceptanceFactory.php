<?php

namespace Database\Factories;

use App\Models\FreightJob;
use App\Models\JobQuote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\JobAcceptance>
 */
class JobAcceptanceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_id' => FreightJob::factory(),
            'quote_id' => JobQuote::factory(),
            'carrier_id' => User::factory()->carrier(),
            'shipper_id' => User::factory()->shipper(),
            'accepted_at' => now(),
        ];
    }
}
