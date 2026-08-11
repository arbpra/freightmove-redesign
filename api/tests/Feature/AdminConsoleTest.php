<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\FreightJob;
use App\Models\JobQuote;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin console: account oversight, load oversight, and the numbers.
 *
 * The interesting cases are the ones that stop an admin doing damage —
 * to themselves, to each other, or to the privilege boundary.
 */
class AdminConsoleTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
        ]);
    }

    private function shipper(array $profile = []): User
    {
        $user = User::factory()->create([
            'role' => UserRole::Shipper,
            'status' => UserStatus::Active,
        ]);
        UserProfile::factory()->create(['user_id' => $user->id, ...$profile]);

        return $user;
    }

    // -- Users ---------------------------------------------------------------

    public function test_the_user_list_is_searchable_and_filterable(): void
    {
        $admin = $this->admin();
        $this->shipper(['company_name' => 'Whitfield Haulage']);
        User::factory()->create(['role' => UserRole::Carrier, 'name' => 'Bruno Katsav']);

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/users?role=carrier')
            ->assertOk()
            ->assertJsonPath('data.items.0.name', 'Bruno Katsav');

        $byCompany = $this->actingAs($admin)
            ->getJson('/api/v1/admin/users?search=Whitfield')
            ->assertOk()
            ->json('data.items');

        $this->assertCount(1, $byCompany);
        $this->assertSame('Whitfield Haulage', $byCompany[0]['company_name']);
    }

    public function test_migrated_accounts_can_be_singled_out(): void
    {
        $admin = $this->admin();
        User::factory()->create(['role' => UserRole::Carrier, 'legacy_id' => '99887766']);
        User::factory()->create(['role' => UserRole::Carrier, 'legacy_id' => null]);

        $legacy = $this->actingAs($admin)
            ->getJson('/api/v1/admin/users?legacy=1')
            ->assertOk()
            ->json('data.items');

        $this->assertCount(1, $legacy);
        $this->assertTrue($legacy[0]['is_legacy']);
    }

    public function test_search_wildcards_are_escaped(): void
    {
        $admin = $this->admin();
        $this->shipper();

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/users?search=%25')
            ->assertOk()
            ->assertJsonCount(0, 'data.items');
    }

    public function test_an_account_can_be_suspended_and_reinstated(): void
    {
        $admin = $this->admin();
        $shipper = $this->shipper();

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/users/{$shipper->id}/status", ['status' => 'suspended'])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');

        $this->assertSame(UserStatus::Suspended, $shipper->fresh()->status);

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/users/{$shipper->id}/status", ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
    }

    /**
     * A token in the suspended user's browser must stop working now, not
     * whenever it happens to expire.
     */
    public function test_suspending_revokes_live_sessions(): void
    {
        $admin = $this->admin();
        $shipper = $this->shipper();
        $shipper->createToken('their-laptop');

        $this->assertSame(1, $shipper->tokens()->count());

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/users/{$shipper->id}/status", ['status' => 'suspended'])
            ->assertOk();

        $this->assertSame(0, $shipper->tokens()->count());
    }

    public function test_reinstating_does_not_revoke_anything(): void
    {
        $admin = $this->admin();
        $shipper = $this->shipper();
        $shipper->forceFill(['status' => UserStatus::Suspended])->save();
        $shipper->createToken('a-fresh-session');

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/users/{$shipper->id}/status", ['status' => 'active'])
            ->assertOk();

        $this->assertSame(1, $shipper->tokens()->count());
    }

    /** Not recoverable from inside the application, so it is refused. */
    public function test_an_admin_cannot_suspend_themselves(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/users/{$admin->id}/status", ['status' => 'suspended'])
            ->assertStatus(422);

        $this->assertSame(UserStatus::Active, $admin->fresh()->status);
    }

    /**
     * Otherwise a single compromised admin account can disable everyone who
     * could stop it.
     */
    public function test_one_admin_cannot_suspend_another(): void
    {
        $other = $this->admin();

        $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/users/{$other->id}/status", ['status' => 'suspended'])
            ->assertStatus(422);

        $this->assertSame(UserStatus::Active, $other->fresh()->status);
    }

    /**
     * Role is the boundary the whole authorisation layer rests on. Nothing in
     * the admin console may cross it.
     */
    public function test_the_status_endpoint_cannot_change_a_role(): void
    {
        $admin = $this->admin();
        $shipper = $this->shipper();

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/users/{$shipper->id}/status", [
                'status' => 'active',
                'role' => 'admin',
            ])
            ->assertOk();

        $this->assertSame(UserRole::Shipper, $shipper->fresh()->role);
    }

    public function test_an_arbitrary_status_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/users/{$this->shipper()->id}/status", ['status' => 'wizard'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    // -- Loads ---------------------------------------------------------------

    public function test_the_job_list_spans_every_shipper(): void
    {
        $admin = $this->admin();

        FreightJob::factory()->create([
            'shipper_id' => $this->shipper()->id,
            'status' => JobStatus::Published,
        ]);
        FreightJob::factory()->create([
            'shipper_id' => $this->shipper()->id,
            'status' => JobStatus::Completed,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/jobs')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 2);

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/jobs?status=completed')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1);
    }

    // -- Analytics -----------------------------------------------------------

    public function test_the_overview_reports_marketplace_health(): void
    {
        $admin = $this->admin();
        $shipper = $this->shipper();
        $carrier = User::factory()->create(['role' => UserRole::Carrier]);

        $quoted = FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Published,
        ]);
        JobQuote::factory()->create(['job_id' => $quoted->id, 'carrier_id' => $carrier->id]);

        // Posted, but nobody has quoted — the number that matters most.
        FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Published,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/overview')
            ->assertOk()
            ->assertJsonPath('data.marketplace.loads_posted', 2)
            ->assertJsonPath('data.marketplace.loads_with_a_quote', 1)
            ->assertJsonPath('data.marketplace.quote_rate', 50)
            ->assertJsonPath('data.marketplace.open_without_quotes', 1);
    }

    public function test_the_overview_reports_migration_progress(): void
    {
        $admin = $this->admin();
        User::factory()->create(['role' => UserRole::Carrier, 'legacy_id' => '123']);
        User::factory()->create([
            'role' => UserRole::Carrier,
            'legacy_id' => '456',
            'password_changed_at' => now(),
        ]);

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/overview')
            ->assertOk()
            ->assertJsonPath('data.migration.legacy_users', 2)
            ->assertJsonPath('data.migration.legacy_users_signed_in', 1)
            // The gates are surfaced because their default depends on these.
            ->assertJsonPath('data.migration.gates.verification_required_to_quote', false)
            ->assertJsonPath('data.migration.gates.subscription_required_to_quote', false);
    }

    /** A rate is meaningless with no denominator, and must not divide by zero. */
    public function test_an_empty_marketplace_reports_zero_not_an_error(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/overview')
            ->assertOk()
            ->assertJsonPath('data.marketplace.quote_rate', 0)
            ->assertJsonPath('data.marketplace.average_quotes_per_load', 0);
    }

    // -- Access --------------------------------------------------------------

    public function test_non_admins_are_kept_out(): void
    {
        $shipper = $this->shipper();

        foreach (['/api/v1/admin/users', '/api/v1/admin/jobs', '/api/v1/admin/overview'] as $path) {
            $this->actingAs($shipper)->getJson($path)->assertForbidden();
        }

        $this->actingAs($shipper)
            ->postJson("/api/v1/admin/users/{$shipper->id}/status", ['status' => 'active'])
            ->assertForbidden();
    }

    public function test_guests_are_refused(): void
    {
        $this->getJson('/api/v1/admin/users')->assertUnauthorized();
    }
}
