<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\FreightJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * An admin posting, editing and removing loads on a shipper's behalf.
 *
 * These routes point at the shipper's own controller rather than a parallel
 * admin one, because the validation, the taxonomy sync and the lifecycle rules
 * are the same job and a second copy would drift. FreightJobPolicy already
 * grants an admin every ability, so what is worth testing is the one thing
 * that is genuinely new — that `shipper_id` is read for an admin and for
 * nobody else.
 */
class AdminJobEditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
        ]);
    }

    private function shipper(): User
    {
        return User::factory()->create([
            'role' => UserRole::Shipper,
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
        ], $overrides);
    }

    // -- Posting for someone else ---------------------------------------------

    public function test_an_admin_can_post_a_load_for_a_shipper(): void
    {
        $shipper = $this->shipper();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/jobs', $this->payload(['shipper_id' => $shipper->id]))
            ->assertCreated();

        $job = FreightJob::sole();

        $this->assertSame($shipper->id, $job->shipper_id);

        // The author is recorded separately, so the trail still says who typed
        // it even though the load belongs to someone else.
        $this->assertSame($admin->id, $job->created_by);
    }

    /**
     * The rule that makes the shared endpoint safe.
     *
     * `shipper_id` is honoured for an admin only. Read from a shipper's
     * request it would let one shipper file loads against another's account.
     */
    public function test_a_shipper_cannot_post_a_load_onto_another_account(): void
    {
        $victim = $this->shipper();
        $shipper = $this->shipper();

        $this->actingAs($shipper)
            ->postJson('/api/v1/shipper/jobs', $this->payload(['shipper_id' => $victim->id]))
            ->assertCreated();

        $this->assertSame($shipper->id, FreightJob::sole()->shipper_id);
    }

    public function test_an_admin_posting_without_a_shipper_owns_it_themselves(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/jobs', $this->payload())
            ->assertCreated();

        $this->assertSame($admin->id, FreightJob::sole()->shipper_id);
    }

    public function test_the_named_shipper_must_actually_be_a_shipper(): void
    {
        $carrier = User::factory()->create(['role' => UserRole::Carrier]);

        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/jobs', $this->payload(['shipper_id' => $carrier->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('shipper_id');
    }

    // -- Editing and removing --------------------------------------------------

    public function test_an_admin_can_edit_a_load_they_do_not_own(): void
    {
        $job = FreightJob::factory()->create([
            'shipper_id' => $this->shipper()->id,
            'title' => 'Typo in the title',
        ]);

        $this->actingAs($this->admin())
            ->patchJson("/api/v1/admin/jobs/{$job->id}", ['title' => 'Corrected title'])
            ->assertOk();

        $this->assertSame('Corrected title', $job->fresh()->title);
    }

    public function test_an_admin_can_remove_a_load(): void
    {
        $job = FreightJob::factory()->create(['shipper_id' => $this->shipper()->id]);

        $this->actingAs($this->admin())
            ->deleteJson("/api/v1/admin/jobs/{$job->id}")
            ->assertOk();

        $this->assertSoftDeleted('freight_jobs', ['id' => $job->id]);
    }

    public function test_an_admin_can_open_any_load(): void
    {
        $job = FreightJob::factory()->create([
            'shipper_id' => $this->shipper()->id,
            'title' => 'Somebody else\'s load',
        ]);

        $this->actingAs($this->admin())
            ->getJson("/api/v1/admin/jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('data.title', 'Somebody else\'s load');
    }

    // -- Who may reach these ---------------------------------------------------

    public function test_a_shipper_cannot_reach_the_admin_routes(): void
    {
        $job = FreightJob::factory()->create(['shipper_id' => $this->shipper()->id]);

        $this->actingAs($this->shipper())
            ->patchJson("/api/v1/admin/jobs/{$job->id}", ['title' => 'Nope'])
            ->assertForbidden();
    }

    public function test_a_carrier_cannot_reach_the_admin_routes(): void
    {
        $job = FreightJob::factory()->create(['shipper_id' => $this->shipper()->id]);

        $this->actingAs(User::factory()->create(['role' => UserRole::Carrier]))
            ->deleteJson("/api/v1/admin/jobs/{$job->id}")
            ->assertForbidden();
    }

    /** One shipper still cannot touch another's, through the normal route. */
    public function test_a_shipper_still_cannot_edit_another_shippers_load(): void
    {
        $job = FreightJob::factory()->create([
            'shipper_id' => $this->shipper()->id,
            'status' => JobStatus::Published,
        ]);

        $this->actingAs($this->shipper())
            ->patchJson("/api/v1/shipper/jobs/{$job->id}", ['title' => 'Hijacked'])
            ->assertForbidden();

        $this->assertNotSame('Hijacked', $job->fresh()->title);
    }
}
