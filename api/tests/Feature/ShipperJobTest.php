<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\FreightJob;
use App\Models\JobQuote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShipperJobTest extends TestCase
{
    use RefreshDatabase;

    private function shipper(): User
    {
        return User::factory()->create([
            'role' => UserRole::Shipper,
            'status' => UserStatus::Active,
        ]);
    }

    private function carrier(): User
    {
        return User::factory()->create([
            'role' => UserRole::Carrier,
            'status' => UserStatus::Active,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Excavator, Brisbane to Perth',
            'pickup_location' => 'Brisbane, QLD',
            'delivery_location' => 'Perth, WA',
            'load_category' => 'Machinery (Mobile)',
            'weight_tons' => 13.85,
        ], $overrides);
    }

    // -- Creating -------------------------------------------------------------

    public function test_a_shipper_can_post_a_load(): void
    {
        $shipper = $this->shipper();

        $response = $this->actingAs($shipper)
            ->postJson('/api/v1/shipper/jobs', $this->payload(['status' => 'published']));

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'Excavator, Brisbane to Perth')
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.weight_tons', 13.85);

        $this->assertDatabaseHas('freight_jobs', [
            'shipper_id' => $shipper->id,
            'title' => 'Excavator, Brisbane to Perth',
            'created_by' => $shipper->id,
        ]);
    }

    public function test_a_load_defaults_to_draft(): void
    {
        $this->actingAs($this->shipper())
            ->postJson('/api/v1/shipper/jobs', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft');
    }

    public function test_a_shipper_cannot_create_a_load_in_an_arbitrary_status(): void
    {
        // Statuses beyond draft/published are reached through the lifecycle
        // endpoints, never by asking for them at creation.
        $this->actingAs($this->shipper())
            ->postJson('/api/v1/shipper/jobs', $this->payload(['status' => 'completed']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_delivery_cannot_precede_pickup(): void
    {
        $this->actingAs($this->shipper())
            ->postJson('/api/v1/shipper/jobs', $this->payload([
                'pickup_date' => '2026-09-10',
                'delivery_date' => '2026-09-01',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('delivery_date');
    }

    public function test_the_maximum_budget_cannot_undercut_the_minimum(): void
    {
        $this->actingAs($this->shipper())
            ->postJson('/api/v1/shipper/jobs', $this->payload([
                'budget_min' => 5000,
                'budget_max' => 2000,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('budget_max');
    }

    // -- Listing --------------------------------------------------------------

    public function test_the_list_only_returns_the_signed_in_shippers_loads(): void
    {
        $mine = $this->shipper();
        $theirs = $this->shipper();

        FreightJob::factory()->count(2)->create(['shipper_id' => $mine->id]);
        FreightJob::factory()->count(3)->create(['shipper_id' => $theirs->id]);

        $response = $this->actingAs($mine)->getJson('/api/v1/shipper/jobs');

        $response->assertOk()
            ->assertJsonPath('data.meta.total', 2)
            ->assertJsonCount(2, 'data.items');
    }

    public function test_the_list_can_be_filtered_by_status(): void
    {
        $shipper = $this->shipper();
        FreightJob::factory()->create(['shipper_id' => $shipper->id, 'status' => JobStatus::Draft]);
        FreightJob::factory()->count(2)->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Published,
        ]);

        $this->actingAs($shipper)
            ->getJson('/api/v1/shipper/jobs?status=published')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 2);
    }

    public function test_the_list_reports_how_many_quotes_each_load_has(): void
    {
        $shipper = $this->shipper();
        $job = FreightJob::factory()->create(['shipper_id' => $shipper->id]);
        JobQuote::factory()->count(3)->create(['job_id' => $job->id]);

        $this->actingAs($shipper)
            ->getJson('/api/v1/shipper/jobs')
            ->assertOk()
            ->assertJsonPath('data.items.0.quotes_count', 3);
    }

    // -- Access control -------------------------------------------------------

    public function test_a_shipper_cannot_read_another_shippers_load(): void
    {
        $job = FreightJob::factory()->create(['shipper_id' => $this->shipper()->id]);

        $this->actingAs($this->shipper())
            ->getJson("/api/v1/shipper/jobs/{$job->id}")
            ->assertForbidden();
    }

    public function test_a_shipper_cannot_edit_another_shippers_load(): void
    {
        $job = FreightJob::factory()->create(['shipper_id' => $this->shipper()->id]);

        $this->actingAs($this->shipper())
            ->patchJson("/api/v1/shipper/jobs/{$job->id}", ['title' => 'Hijacked'])
            ->assertForbidden();

        $this->assertDatabaseMissing('freight_jobs', ['title' => 'Hijacked']);
    }

    public function test_carriers_are_kept_out_of_the_shipper_area(): void
    {
        $this->actingAs($this->carrier())
            ->getJson('/api/v1/shipper/jobs')
            ->assertForbidden();
    }

    public function test_guests_are_refused(): void
    {
        $this->getJson('/api/v1/shipper/jobs')->assertUnauthorized();
    }

    // -- Lifecycle ------------------------------------------------------------

    public function test_a_draft_can_be_published(): void
    {
        $shipper = $this->shipper();
        $job = FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Draft,
        ]);

        $this->actingAs($shipper)
            ->postJson("/api/v1/shipper/jobs/{$job->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published');
    }

    public function test_an_accepted_load_can_no_longer_be_edited(): void
    {
        // A carrier is already planning around these details.
        $shipper = $this->shipper();
        $job = FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Accepted,
        ]);

        $this->actingAs($shipper)
            ->patchJson("/api/v1/shipper/jobs/{$job->id}", ['pickup_location' => 'Somewhere else'])
            ->assertForbidden();
    }

    public function test_a_completed_load_cannot_be_cancelled(): void
    {
        $shipper = $this->shipper();
        $job = FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Completed,
        ]);

        $this->actingAs($shipper)
            ->postJson("/api/v1/shipper/jobs/{$job->id}/cancel")
            ->assertForbidden();
    }

    public function test_deleting_a_load_soft_deletes_it(): void
    {
        $shipper = $this->shipper();
        $job = FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Draft,
        ]);

        $this->actingAs($shipper)
            ->deleteJson("/api/v1/shipper/jobs/{$job->id}")
            ->assertOk();

        // Retained for carriers who quoted, and for dispute history.
        $this->assertSoftDeleted('freight_jobs', ['id' => $job->id]);
    }
}
