<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionReminder;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin record of who was sent which reminder, and when.
 *
 * This is an audit surface, so the cases that matter are the ones that decide
 * whether it can be trusted: that it is admin-only, that it shows suppressed
 * milestones rather than quietly dropping them, and that it cannot be written
 * to from the outside.
 */
class AdminReminderRecordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\SubscriptionPlanSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
        ]);
    }

    private function carrier(string $name, string $email): User
    {
        $user = User::factory()->create([
            'role' => UserRole::Carrier,
            'status' => UserStatus::Active,
            'name' => $name,
            'email' => $email,
        ]);
        UserProfile::factory()->create(['user_id' => $user->id, 'company_name' => "{$name} Freight"]);

        return $user;
    }

    private function reminder(User $carrier, string $kind, string $milestone, bool $sent = true): SubscriptionReminder
    {
        $subscription = Subscription::create([
            'user_id' => $carrier->id,
            'subscription_plan_id' => SubscriptionPlan::where('code', 'monthly')->value('id'),
            'status' => 'active',
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-09-01',
        ]);

        return SubscriptionReminder::create([
            'subscription_id' => $subscription->id,
            'user_id' => $carrier->id,
            'kind' => $kind,
            'milestone' => $milestone,
            'sent_at' => $sent ? now() : null,
            'skip_reason' => $sent ? null : SubscriptionReminder::SKIP_BACKLOG,
        ]);
    }

    public function test_an_admin_sees_who_was_reminded_and_when(): void
    {
        $carrier = $this->carrier('Bruno Katsav', 'bruno@example.test');
        $this->reminder($carrier, 'expiring', 'd5');

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/reminders')
            ->assertOk();

        $response->assertJsonPath('data.items.0.carrier.email', 'bruno@example.test');
        $response->assertJsonPath('data.items.0.carrier.name', 'Bruno Katsav Freight');
        $response->assertJsonPath('data.items.0.label', '5 days before expiry');
        $this->assertNotNull($response->json('data.items.0.sent_at'));
    }

    /** The milestone has to read as English, not as `m3`. */
    public function test_milestones_are_labelled_in_words(): void
    {
        $carrier = $this->carrier('Ana Reyes', 'ana@example.test');
        $this->reminder($carrier, 'expired', 'm3');
        $this->reminder($carrier, 'expired', 'd7');

        $labels = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/reminders')
            ->assertOk()
            ->json('data.items.*.label');

        $this->assertContains('3 months after expiry', $labels);
        $this->assertContains('7 days after expiry', $labels);
    }

    /**
     * Suppressed rows are the record that a milestone was reached and
     * deliberately not emailed. Hiding them would make the ledger look like
     * reminders had gone missing.
     */
    public function test_suppressed_milestones_are_visible_and_distinguishable(): void
    {
        $carrier = $this->carrier('Ivo Lang', 'ivo@example.test');
        $this->reminder($carrier, 'expired', 'm1', sent: false);

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/reminders?status=suppressed')
            ->assertOk();

        $response->assertJsonPath('data.items.0.sent_at', null);
        $response->assertJsonPath('data.items.0.skip_reason', 'backlog');
        $response->assertJsonPath('data.summary.suppressed', 1);
        $response->assertJsonPath('data.summary.sent_total', 0);
    }

    public function test_it_filters_by_kind_and_by_delivery(): void
    {
        $carrier = $this->carrier('Nia Odum', 'nia@example.test');
        $this->reminder($carrier, 'expiring', 'd3');
        $this->reminder($carrier, 'expired', 'd7');
        $this->reminder($carrier, 'expired', 'm1', sent: false);

        $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/reminders?kind=expiring')
            ->assertOk()
            ->assertJsonCount(1, 'data.items');

        $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/reminders?status=sent')
            ->assertOk()
            ->assertJsonCount(2, 'data.items');
    }

    public function test_it_searches_by_carrier(): void
    {
        $this->reminder($this->carrier('Bruno Katsav', 'bruno@example.test'), 'expiring', 'd3');
        $this->reminder($this->carrier('Ana Reyes', 'ana@example.test'), 'expiring', 'd3');

        $items = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/reminders?q=bruno')
            ->assertOk()
            ->json('data.items');

        $this->assertCount(1, $items);
        $this->assertSame('bruno@example.test', $items[0]['carrier']['email']);
    }

    public function test_the_summary_counts_the_whole_ledger(): void
    {
        $carrier = $this->carrier('Nia Odum', 'nia@example.test');
        $this->reminder($carrier, 'expiring', 'd5');
        $this->reminder($carrier, 'expired', 'd3');
        $this->reminder($carrier, 'expired', 'm1', sent: false);

        // Filtered to one row, but the strip still describes everything.
        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/reminders?kind=expiring')
            ->assertOk();

        $response->assertJsonPath('data.summary.sent_total', 2);
        $response->assertJsonPath('data.summary.sent_expiring', 1);
        $response->assertJsonPath('data.summary.sent_expired', 1);
        $response->assertJsonPath('data.summary.suppressed', 1);
        $response->assertJsonPath('data.summary.carriers_reached', 1);
    }

    public function test_a_carrier_cannot_read_the_record(): void
    {
        $carrier = $this->carrier('Bruno Katsav', 'bruno@example.test');
        $this->reminder($carrier, 'expiring', 'd3');

        $this->actingAs($carrier)
            ->getJson('/api/v1/admin/reminders')
            ->assertForbidden();
    }

    public function test_a_signed_out_visitor_cannot_read_the_record(): void
    {
        $this->getJson('/api/v1/admin/reminders')->assertUnauthorized();
    }

    /** Read-only: an audit answer stops meaning anything once it can be edited. */
    public function test_the_record_cannot_be_written_to(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/v1/admin/reminders')->assertStatus(405);
        $this->actingAs($admin)->deleteJson('/api/v1/admin/reminders/1')->assertStatus(404);
    }

    public function test_it_rejects_a_filter_it_does_not_understand(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/reminders?kind=whenever')
            ->assertStatus(422);
    }
}
