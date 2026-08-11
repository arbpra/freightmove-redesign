<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\FreightJob;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Relisting — bumping an open load back to the top of the carrier board.
 *
 * Closes G6. The legacy site had no such action: a shipper resurfaced a load by
 * editing it, which touched `date_updated` and made "changed the pickup date"
 * indistinguishable from "wants attention" (docs/10-domain-rules.md R5). The
 * explicit action also gets an explicit cooldown, which legacy never had.
 */
class RelistTest extends TestCase
{
    use RefreshDatabase;

    private function shipper(): User
    {
        $user = User::factory()->create([
            'role' => UserRole::Shipper,
            'status' => UserStatus::Active,
        ]);
        UserProfile::factory()->create(['user_id' => $user->id]);

        return $user;
    }

    public function test_shipper_can_relist_an_open_load(): void
    {
        $shipper = $this->shipper();
        $job = FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Published,
            'relisted_at' => null,
        ]);

        $this->actingAs($shipper)
            ->postJson("/api/v1/shipper/jobs/{$job->id}/relist")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNotNull($job->fresh()->relisted_at);
    }

    public function test_relisting_moves_the_load_to_the_top_of_the_board(): void
    {
        $shipper = $this->shipper();

        // Posted first, so without a bump it sits underneath.
        $old = FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Published,
            'created_at' => now()->subDays(3),
        ]);
        FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Published,
            'created_at' => now()->subDay(),
        ]);

        $this->assertNotSame($old->id, FreightJob::boardOrder()->first()->id);

        $this->actingAs($shipper)
            ->postJson("/api/v1/shipper/jobs/{$old->id}/relist")
            ->assertOk();

        $this->assertSame($old->id, FreightJob::boardOrder()->first()->id);
    }

    public function test_relisting_restarts_the_recency_window(): void
    {
        $shipper = $this->shipper();
        $stale = FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Published,
            'created_at' => now()->subDays(30),
        ]);

        $this->assertSame(0, FreightJob::recent(7)->count());

        $this->actingAs($shipper)
            ->postJson("/api/v1/shipper/jobs/{$stale->id}/relist")
            ->assertOk();

        $this->assertSame(1, FreightJob::recent(7)->count());
    }

    public function test_relisting_again_within_the_cooldown_is_refused(): void
    {
        config(['freightmove.board.relist_cooldown_hours' => 24]);

        $shipper = $this->shipper();
        $job = FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Published,
            'relisted_at' => now()->subHours(2),
        ]);

        $this->actingAs($shipper)
            ->postJson("/api/v1/shipper/jobs/{$job->id}/relist")
            ->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['next_relist_at']]);
    }

    public function test_relisting_is_allowed_once_the_cooldown_has_passed(): void
    {
        config(['freightmove.board.relist_cooldown_hours' => 24]);

        $shipper = $this->shipper();
        $job = FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Published,
            'relisted_at' => now()->subHours(25),
        ]);

        $this->actingAs($shipper)
            ->postJson("/api/v1/shipper/jobs/{$job->id}/relist")
            ->assertOk();
    }

    public function test_cooldown_of_zero_allows_relisting_at_will(): void
    {
        config(['freightmove.board.relist_cooldown_hours' => 0]);

        $shipper = $this->shipper();
        $job = FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Published,
            'relisted_at' => now()->subMinute(),
        ]);

        $this->actingAs($shipper)
            ->postJson("/api/v1/shipper/jobs/{$job->id}/relist")
            ->assertOk();
    }

    public function test_another_shipper_cannot_relist_someone_elses_load(): void
    {
        $job = FreightJob::factory()->create([
            'shipper_id' => $this->shipper()->id,
            'status' => JobStatus::Published,
        ]);

        $this->actingAs($this->shipper())
            ->postJson("/api/v1/shipper/jobs/{$job->id}/relist")
            ->assertForbidden();

        $this->assertNull($job->fresh()->relisted_at);
    }

    /**
     * A draft is not on the board to be bumped, and a booked or cancelled load
     * must never reappear on it.
     */
    public function test_a_load_that_is_not_open_for_quotes_cannot_be_relisted(): void
    {
        $shipper = $this->shipper();

        foreach ([JobStatus::Draft, JobStatus::Accepted, JobStatus::Completed, JobStatus::Cancelled] as $status) {
            $job = FreightJob::factory()->create([
                'shipper_id' => $shipper->id,
                'status' => $status,
            ]);

            $this->actingAs($shipper)
                ->postJson("/api/v1/shipper/jobs/{$job->id}/relist")
                ->assertForbidden();

            $this->assertNull($job->fresh()->relisted_at, "{$status->value} should not be relistable");
        }
    }

    public function test_editing_a_load_does_not_bump_it(): void
    {
        $shipper = $this->shipper();
        $job = FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Published,
            'relisted_at' => null,
        ]);

        $this->actingAs($shipper)
            ->patchJson("/api/v1/shipper/jobs/{$job->id}", ['title' => 'Corrected title'])
            ->assertOk();

        $this->assertNull(
            $job->fresh()->relisted_at,
            'An edit must stay distinguishable from a deliberate bump.',
        );
    }
}
