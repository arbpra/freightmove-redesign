<?php

namespace Tests\Feature;

use App\Enums\LoadAvailability;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Category;
use App\Models\TruckType;
use App\Models\User;
use Database\Seeders\FreightTaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gaps G1 and G2 (docs/10-domain-rules.md).
 *
 * The rule these defend: a load may carry several categories and several truck
 * types. Two-thirds of live loads do, and the old single-value columns silently
 * kept only the first.
 */
class FreightTaxonomyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FreightTaxonomySeeder::class);
    }

    private function shipper(): User
    {
        return User::factory()->create([
            'role' => UserRole::Shipper,
            'status' => UserStatus::Active,
        ]);
    }

    // -- The vocabulary --------------------------------------------------------

    public function test_the_vocabulary_is_seeded_from_real_legacy_values(): void
    {
        // Every distinct value found in old_freightmove.load_master.
        $this->assertSame(13, Category::count());
        $this->assertSame(19, TruckType::count());

        $this->assertDatabaseHas('categories', ['name' => 'Machinery (Mobile)']);
        $this->assertDatabaseHas('truck_types', ['name' => 'Drop Deck']);
        // Found only by the importer's unmatched-value guard, not the first sweep.
        $this->assertDatabaseHas('truck_types', ['name' => 'Refrigerated']);
    }

    public function test_the_vocabulary_is_public(): void
    {
        $this->getJson('/api/v1/public/taxonomy')
            ->assertOk()
            ->assertJsonCount(13, 'data.categories')
            ->assertJsonCount(19, 'data.truck_types')
            ->assertJsonCount(4, 'data.availability');
    }

    // -- Many per load ---------------------------------------------------------

    public function test_a_load_can_carry_several_categories_and_truck_types(): void
    {
        $categories = Category::query()->take(3)->pluck('id')->all();
        $truckTypes = TruckType::query()->take(5)->pluck('id')->all();

        $response = $this->actingAs($this->shipper())->postJson('/api/v1/shipper/jobs', [
            'title' => 'Mixed load',
            'pickup_location' => 'Brisbane, QLD',
            'delivery_location' => 'Perth, WA',
            'availability' => 'asap',
            'category_ids' => $categories,
            'truck_type_ids' => $truckTypes,
        ]);

        $response->assertCreated()
            ->assertJsonCount(3, 'data.categories')
            ->assertJsonCount(5, 'data.truck_types')
            ->assertJsonPath('data.availability', 'asap');

        $id = $response->json('data.id');
        $this->assertDatabaseCount('category_freight_job', 3);
        $this->assertDatabaseCount('freight_job_truck_type', 5);

        // The denormalised singular columns still get a value for list rows.
        $this->assertNotNull(\App\Models\FreightJob::find($id)->trailer_type_required);
    }

    public function test_availability_is_optional_but_validated(): void
    {
        $this->actingAs($this->shipper())->postJson('/api/v1/shipper/jobs', [
            'title' => 'Bad availability',
            'pickup_location' => 'A',
            'delivery_location' => 'B',
            'availability' => 'whenever',
        ])->assertStatus(422)->assertJsonValidationErrors('availability');
    }

    public function test_unknown_taxonomy_ids_are_rejected(): void
    {
        $this->actingAs($this->shipper())->postJson('/api/v1/shipper/jobs', [
            'title' => 'Bogus ids',
            'pickup_location' => 'A',
            'delivery_location' => 'B',
            'truck_type_ids' => [999999],
        ])->assertStatus(422)->assertJsonValidationErrors('truck_type_ids.0');
    }

    // -- Editing ---------------------------------------------------------------

    public function test_editing_replaces_the_selection(): void
    {
        $shipper = $this->shipper();
        $first = TruckType::query()->take(4)->pluck('id')->all();

        $id = $this->actingAs($shipper)->postJson('/api/v1/shipper/jobs', [
            'title' => 'Replaceable',
            'pickup_location' => 'A',
            'delivery_location' => 'B',
            'truck_type_ids' => $first,
        ])->json('data.id');

        $this->assertDatabaseCount('freight_job_truck_type', 4);

        $this->actingAs($shipper)->patchJson("/api/v1/shipper/jobs/{$id}", [
            'truck_type_ids' => [TruckType::query()->first()->id],
        ])->assertOk()->assertJsonCount(1, 'data.truck_types');

        $this->assertDatabaseCount('freight_job_truck_type', 1);
    }

    /**
     * A PATCH that says nothing about truck types must leave them alone —
     * otherwise editing a title would quietly wipe the selection.
     */
    public function test_an_unrelated_edit_leaves_the_selection_intact(): void
    {
        $shipper = $this->shipper();

        $id = $this->actingAs($shipper)->postJson('/api/v1/shipper/jobs', [
            'title' => 'Keep my trailers',
            'pickup_location' => 'A',
            'delivery_location' => 'B',
            'truck_type_ids' => TruckType::query()->take(3)->pluck('id')->all(),
        ])->json('data.id');

        $this->actingAs($shipper)
            ->patchJson("/api/v1/shipper/jobs/{$id}", ['title' => 'Renamed'])
            ->assertOk();

        $this->assertDatabaseCount('freight_job_truck_type', 3);
    }

    public function test_every_legacy_availability_code_maps(): void
    {
        $this->assertSame(LoadAvailability::Asap, LoadAvailability::fromLegacy(1));
        $this->assertSame(LoadAvailability::ReadyNow, LoadAvailability::fromLegacy(2));
        $this->assertSame(LoadAvailability::AvailableFrom, LoadAvailability::fromLegacy(3));
        $this->assertSame(LoadAvailability::Planning, LoadAvailability::fromLegacy(4));
        // An unrecognised code becomes null rather than a guess.
        $this->assertNull(LoadAvailability::fromLegacy(9));
        $this->assertNull(LoadAvailability::fromLegacy(null));
    }
}
