<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Carrier;
use App\Models\FreightJob;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Carrier subscriptions, as advertised on
 * https://www.freightmove.au/carriers-subscription.
 *
 * The subscription is the paid product, so the rules here decide who may quote
 * once that gate is switched on.
 */
class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\SubscriptionPlanSeeder::class);
    }

    private function carrier(): User
    {
        $user = User::factory()->create([
            'role' => UserRole::Carrier,
            'status' => UserStatus::Active,
        ]);
        UserProfile::factory()->create(['user_id' => $user->id]);
        Carrier::factory()->create(['user_id' => $user->id]);

        return $user;
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
        ]);
    }

    // -- The pricing table ---------------------------------------------------

    public function test_the_public_pricing_table_matches_the_advertised_plans(): void
    {
        $items = $this->getJson('/api/v1/public/subscription-plans')
            ->assertOk()
            ->json('data.items');

        $byCode = collect($items)->keyBy('code');

        // Cast before comparing: JSON renders 0.00 as the integer 0.
        $this->assertSame(0.0, (float) $byCode['trial']['price']);
        $this->assertSame(64.99, $byCode['trial']['compare_at_price']);
        $this->assertSame(2, $byCode['trial']['interval_months']);
        $this->assertSame(64.99, $byCode['monthly']['price']);
        $this->assertSame(184.99, $byCode['quarterly']['price']);
        $this->assertSame(699.90, $byCode['annual']['price']);
    }

    /**
     * The page advertises "Save $3.33 Per Month" on quarterly and "$6.66" on
     * annual. Computed from the prices, so the claim cannot drift from them.
     */
    public function test_the_advertised_monthly_saving_is_computed_not_written(): void
    {
        $byCode = collect(
            $this->getJson('/api/v1/public/subscription-plans')->json('data.items')
        )->keyBy('code');

        $this->assertSame(3.33, $byCode['quarterly']['saving_per_month']);
        $this->assertSame(6.66, $byCode['annual']['saving_per_month']);
        // Monthly is the baseline, so it has nothing to save against.
        $this->assertNull($byCode['monthly']['saving_per_month']);
    }

    public function test_the_pricing_table_needs_no_account(): void
    {
        $this->getJson('/api/v1/public/subscription-plans')->assertOk();
    }

    // -- The free trial ------------------------------------------------------

    public function test_a_carrier_can_start_the_free_trial(): void
    {
        $carrier = $this->carrier();

        $this->actingAs($carrier)
            ->postJson('/api/v1/carrier/subscription/trial')
            ->assertCreated();

        $subscription = Subscription::sole();
        $this->assertSame('active', $subscription->status);
        $this->assertTrue($subscription->plan->is_trial);
    }

    /**
     * The page promises a two-month trial. The previous platform set every
     * trial's end date to the promotion's closing date instead, so a carrier who
     * signed up in July 2026 received a trial that had expired in March.
     */
    public function test_the_trial_runs_two_months_from_today_not_to_a_fixed_date(): void
    {
        $carrier = $this->carrier();

        $this->actingAs($carrier)
            ->postJson('/api/v1/carrier/subscription/trial')
            ->assertCreated();

        $subscription = Subscription::sole();

        $this->assertTrue($subscription->ends_on->isFuture());
        $this->assertSame(
            today()->addMonths(2)->toDateString(),
            $subscription->ends_on->toDateString(),
        );
    }

    public function test_the_trial_can_only_be_used_once(): void
    {
        $carrier = $this->carrier();

        $this->actingAs($carrier)->postJson('/api/v1/carrier/subscription/trial')->assertCreated();

        // Even after it has run out.
        Subscription::sole()->forceFill([
            'ends_on' => today()->subDay(),
            'status' => 'expired',
        ])->save();

        $this->actingAs($carrier)
            ->postJson('/api/v1/carrier/subscription/trial')
            ->assertStatus(422)
            ->assertJsonPath('message', 'You have already used your free trial.');

        $this->assertSame(1, Subscription::count());
    }

    public function test_the_trial_can_be_closed_by_configuration(): void
    {
        config(['freightmove.subscriptions.trial_offer_ends' => today()->subDay()->toDateString()]);

        $this->actingAs($this->carrier())
            ->postJson('/api/v1/carrier/subscription/trial')
            ->assertStatus(422)
            ->assertJsonPath('message', 'The free trial offer has closed.');
    }

    /**
     * Defaults to open. The advertised date has passed while the legacy data
     * shows trials still being granted, so closing it is a decision to make
     * deliberately rather than one to inherit by accident.
     */
    public function test_the_trial_offer_is_open_by_default(): void
    {
        $this->getJson('/api/v1/public/subscription-plans')
            ->assertOk()
            ->assertJsonPath('data.trial_offer_open', true);
    }

    // -- Paid plans ----------------------------------------------------------

    public function test_choosing_a_paid_plan_reserves_it_pending_payment(): void
    {
        $carrier = $this->carrier();

        $this->actingAs($carrier)
            ->postJson('/api/v1/carrier/subscription/checkout', ['plan' => 'quarterly'])
            ->assertCreated()
            ->assertJsonPath('data.amount', 184.99);

        $subscription = Subscription::sole();
        $this->assertSame('pending', $subscription->status);

        $payment = SubscriptionPayment::sole();
        $this->assertSame('pending', $payment->status);
        $this->assertNull($payment->paid_at);
    }

    /** A reserved-but-unpaid subscription is an intention, not an entitlement. */
    public function test_a_pending_subscription_does_not_let_a_carrier_quote(): void
    {
        config(['freightmove.quoting.require_subscription' => true]);

        $carrier = $this->carrier();
        $job = FreightJob::factory()->create(['status' => JobStatus::Published]);

        $this->actingAs($carrier)
            ->postJson('/api/v1/carrier/subscription/checkout', ['plan' => 'monthly'])
            ->assertCreated();

        $this->actingAs($carrier->refresh())
            ->postJson("/api/v1/carrier/board/{$job->id}/quotes", ['amount' => 900])
            ->assertForbidden();
    }

    public function test_confirming_payment_switches_the_subscription_on(): void
    {
        config(['freightmove.quoting.require_subscription' => true]);

        $carrier = $this->carrier();
        $job = FreightJob::factory()->create(['status' => JobStatus::Published]);

        $this->actingAs($carrier)
            ->postJson('/api/v1/carrier/subscription/checkout', ['plan' => 'monthly'])
            ->assertCreated();

        $subscription = Subscription::sole();

        $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/subscriptions/{$subscription->id}/confirm", [
                'reference' => 'BANK-99123',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertSame('completed', SubscriptionPayment::sole()->status);

        $this->actingAs($carrier->refresh())
            ->postJson("/api/v1/carrier/board/{$job->id}/quotes", ['amount' => 900])
            ->assertCreated();
    }

    /**
     * A period that starts while it is still unpaid quietly sells the carrier
     * less than the plan says.
     */
    public function test_the_paid_period_runs_from_confirmation(): void
    {
        $carrier = $this->carrier();

        $this->actingAs($carrier)
            ->postJson('/api/v1/carrier/subscription/checkout', ['plan' => 'quarterly'])
            ->assertCreated();

        $subscription = Subscription::sole();
        // Reserved a fortnight ago and only paid for today.
        $subscription->forceFill(['starts_on' => today()->subDays(14)])->save();

        app(SubscriptionService::class)->confirmPayment($subscription->fresh());

        $subscription->refresh();
        $this->assertSame(today()->toDateString(), $subscription->starts_on->toDateString());
        $this->assertSame(today()->addMonths(3)->toDateString(), $subscription->ends_on->toDateString());
    }

    /**
     * The two dating rules have to agree. Confirmation must not start the
     * period today when the carrier still holds time — that difference comes
     * straight out of what they paid for.
     */
    public function test_paying_while_a_trial_is_running_does_not_swallow_it(): void
    {
        $carrier = $this->carrier();

        $this->actingAs($carrier)->postJson('/api/v1/carrier/subscription/trial')->assertCreated();
        $trialEnds = Subscription::sole()->ends_on->copy();

        $this->actingAs($carrier)
            ->postJson('/api/v1/carrier/subscription/checkout', ['plan' => 'quarterly'])
            ->assertCreated();

        $pending = Subscription::where('status', 'pending')->sole();

        $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/subscriptions/{$pending->id}/confirm")
            ->assertOk();

        $pending->refresh();

        // Starts the day the trial runs out, and still gets its full three
        // months from there.
        $this->assertSame($trialEnds->copy()->addDay()->toDateString(), $pending->starts_on->toDateString());
        $this->assertSame(
            $trialEnds->copy()->addDay()->addMonths(3)->toDateString(),
            $pending->ends_on->toDateString(),
        );
    }

    public function test_confirming_something_already_active_is_refused(): void
    {
        $carrier = $this->carrier();
        $subscription = Subscription::create([
            'user_id' => $carrier->id,
            'subscription_plan_id' => SubscriptionPlan::where('code', 'monthly')->value('id'),
            'status' => 'active',
            'starts_on' => today(),
            'ends_on' => today()->addMonth(),
        ]);

        $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/subscriptions/{$subscription->id}/confirm")
            ->assertStatus(422);
    }

    public function test_an_unknown_plan_is_refused(): void
    {
        $this->actingAs($this->carrier())
            ->postJson('/api/v1/carrier/subscription/checkout', ['plan' => 'lifetime-free'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('plan');
    }

    /** Renewing early must not throw away days already paid for. */
    public function test_renewing_early_stacks_on_the_end_of_the_current_period(): void
    {
        $carrier = $this->carrier();
        $endsOn = today()->addDays(10);

        Subscription::create([
            'user_id' => $carrier->id,
            'subscription_plan_id' => SubscriptionPlan::where('code', 'monthly')->value('id'),
            'status' => 'active',
            'starts_on' => today()->subDays(20),
            'ends_on' => $endsOn,
        ]);

        $this->actingAs($carrier)
            ->postJson('/api/v1/carrier/subscription/checkout', ['plan' => 'monthly'])
            ->assertCreated();

        $new = Subscription::where('status', 'pending')->sole();
        $this->assertSame($endsOn->copy()->addDay()->toDateString(), $new->starts_on->toDateString());
    }

    // -- Status and cancellation ---------------------------------------------

    public function test_a_carrier_sees_their_own_subscription_state(): void
    {
        $carrier = $this->carrier();

        $this->actingAs($carrier)->postJson('/api/v1/carrier/subscription/trial')->assertCreated();

        $this->actingAs($carrier)
            ->getJson('/api/v1/carrier/subscription')
            ->assertOk()
            ->assertJsonPath('data.current.plan_code', 'trial')
            ->assertJsonPath('data.current.is_trial', true)
            ->assertJsonPath('data.trial.eligible', false);
    }

    /** Cancelling means "do not renew", not "lock me out of what I paid for". */
    public function test_cancelling_keeps_access_to_the_end_of_the_period(): void
    {
        $carrier = $this->carrier();
        $this->actingAs($carrier)->postJson('/api/v1/carrier/subscription/trial')->assertCreated();

        $subscription = Subscription::sole();
        $endsOn = $subscription->ends_on->toDateString();

        $this->actingAs($carrier)
            ->postJson("/api/v1/carrier/subscription/{$subscription->id}/cancel")
            ->assertOk();

        $subscription->refresh();
        $this->assertSame('cancelled', $subscription->status);
        $this->assertSame($endsOn, $subscription->ends_on->toDateString());
    }

    public function test_one_carrier_cannot_cancel_anothers_subscription(): void
    {
        $owner = $this->carrier();
        $this->actingAs($owner)->postJson('/api/v1/carrier/subscription/trial')->assertCreated();

        $this->actingAs($this->carrier())
            ->postJson('/api/v1/carrier/subscription/'.Subscription::sole()->id.'/cancel')
            ->assertNotFound();

        $this->assertSame('active', Subscription::sole()->status);
    }

    public function test_shippers_are_kept_out(): void
    {
        $shipper = User::factory()->create([
            'role' => UserRole::Shipper,
            'status' => UserStatus::Active,
        ]);

        $this->actingAs($shipper)->getJson('/api/v1/carrier/subscription')->assertForbidden();
    }

    /**
     * Its own test: `actingAs` persists for the rest of a test method, so a
     * "guest" request made after one would still be authenticated.
     */
    public function test_guests_are_refused(): void
    {
        $this->getJson('/api/v1/carrier/subscription')->assertUnauthorized();
        $this->getJson('/api/v1/admin/subscriptions')->assertUnauthorized();
    }

    /**
     * The counterpart to the pending rule: cancelling must not revoke the
     * period already paid for, which is exactly what the cancellation message
     * tells the carrier.
     */
    public function test_a_cancelled_but_unexpired_subscription_still_allows_quoting(): void
    {
        config(['freightmove.quoting.require_subscription' => true]);

        $carrier = $this->carrier();
        $job = FreightJob::factory()->create(['status' => JobStatus::Published]);

        Subscription::create([
            'user_id' => $carrier->id,
            'subscription_plan_id' => SubscriptionPlan::where('code', 'monthly')->value('id'),
            'status' => 'cancelled',
            'starts_on' => today()->subDays(5),
            'ends_on' => today()->addDays(20),
        ]);

        $this->actingAs($carrier->refresh())
            ->postJson("/api/v1/carrier/board/{$job->id}/quotes", ['amount' => 900])
            ->assertCreated();
    }

    public function test_an_expired_subscription_does_not_allow_quoting(): void
    {
        config(['freightmove.quoting.require_subscription' => true]);

        $carrier = $this->carrier();
        $job = FreightJob::factory()->create(['status' => JobStatus::Published]);

        Subscription::create([
            'user_id' => $carrier->id,
            'subscription_plan_id' => SubscriptionPlan::where('code', 'monthly')->value('id'),
            'status' => 'active',
            'starts_on' => today()->subMonths(2),
            'ends_on' => today()->subDay(),
        ]);

        $this->actingAs($carrier->refresh())
            ->postJson("/api/v1/carrier/board/{$job->id}/quotes", ['amount' => 900])
            ->assertForbidden();
    }
}
