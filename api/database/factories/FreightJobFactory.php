<?php

namespace Database\Factories;

use App\Enums\JobStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\FreightJob>
 */
class FreightJobFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $locations = [
            'Sydney, NSW', 'Melbourne, VIC', 'Brisbane, QLD', 'Perth, WA', 'Adelaide, SA',
            'Newcastle, NSW', 'Geelong, VIC', 'Toowoomba, QLD', 'Wollongong, NSW',
            'Townsville, QLD', 'Ballarat, VIC', 'Darwin, NT',
        ];

        $pickup = fake()->randomElement($locations);
        $delivery = fake()->randomElement(array_diff($locations, [$pickup]));

        $category = fake()->randomElement([
            'General Freight', 'Palletised Goods', 'Refrigerated', 'Machinery',
            'Building Materials', 'Bulk Grain', 'Vehicles', 'Furniture',
        ]);

        $pickupDate = fake()->dateTimeBetween('+2 days', '+5 weeks');
        $budgetMin = fake()->numberBetween(600, 6000);

        return [
            'shipper_id' => User::factory()->shipper(),
            'title' => $category.' — '.explode(',', $pickup)[0].' to '.explode(',', $delivery)[0],
            'description' => fake()->paragraph(3),
            'pickup_location' => $pickup,
            'delivery_location' => $delivery,
            'pickup_date' => $pickupDate,
            'delivery_date' => fake()->dateTimeBetween($pickupDate, '+7 weeks'),
            'load_category' => $category,
            'weight_kg' => fake()->numberBetween(500, 42000),
            'quantity' => fake()->randomElement(['1', '2 pallets', '3', '4 crates', null]),
            'length_mm' => fake()->randomElement([null, 2400, 6000, 12000]),
            'width_mm' => fake()->randomElement([null, 1200, 2400, 2500]),
            'height_mm' => fake()->randomElement([null, 1000, 2200, 2900]),
            'vehicle_type_required' => fake()->randomElement([
                'Semi Trailer', 'B-Double', 'Rigid Truck', 'Prime Mover', 'Road Train',
            ]),
            'trailer_type_required' => fake()->randomElement([
                'Flat Top', 'Curtain Sider', 'Tautliner', 'Refrigerated', 'Drop Deck', 'Tipper',
            ]),
            'budget_min' => $budgetMin,
            'budget_max' => $budgetMin + fake()->numberBetween(200, 2500),
            'status' => JobStatus::Published,
            'visibility' => 'public',
            'images_json' => null,
            'documents_json' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => ['status' => JobStatus::Draft]);
    }

    public function status(JobStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }
}
