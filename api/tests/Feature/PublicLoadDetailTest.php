<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\FreightJob;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One load, in full, at `GET /public/loads/{ref}`.
 *
 * The interesting cases are all about the boundary. A detail page is where a
 * "just show everything" instinct does the most damage: the brief is free text
 * and reliably contains site contacts and mobile numbers, and the shipper's
 * identity is the marketplace's whole reason to exist. Neither may reach a
 * stranger who guessed a URL.
 */
class PublicLoadDetailTest extends TestCase
{
    use RefreshDatabase;

    private function load(array $attributes = []): FreightJob
    {
        $shipper = User::factory()->create([
            'role' => UserRole::Shipper,
            'status' => UserStatus::Active,
        ]);

        return FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Published,
            'title' => 'Excavator relocation',
            'pickup_location' => 'Sydney NSW',
            'delivery_location' => 'Dubbo NSW',
            'description' => 'Site contact Dave on 0400 000 000, gate code 4417.',
            'budget_min' => 1200,
            'budget_max' => 1800,
            ...$attributes,
        ]);
    }

    private function ref(FreightJob $job): string
    {
        return 'FM-'.str_pad((string) $job->id, 6, '0', STR_PAD_LEFT);
    }

    private function carrier(): User
    {
        return User::factory()->create([
            'role' => UserRole::Carrier,
            'status' => UserStatus::Active,
        ]);
    }

    public function test_a_visitor_can_open_a_load_without_an_account(): void
    {
        $job = $this->load();

        $this->getJson("/api/v1/public/loads/{$this->ref($job)}")
            ->assertOk()
            ->assertJsonPath('data.title', 'Excavator relocation')
            ->assertJsonPath('data.pickup', 'Sydney NSW')
            ->assertJsonPath('data.delivery', 'Dubbo NSW')
            ->assertJsonPath('data.ref', $this->ref($job));
    }

    /**
     * The brief is free text, so it is where "ask for Dave on 0400…" ends up.
     * Publishing it to anyone with the URL is how the marketplace gets
     * disintermediated by a search engine.
     */
    public function test_the_brief_and_the_budget_are_withheld_from_strangers(): void
    {
        $job = $this->load();

        $response = $this->getJson("/api/v1/public/loads/{$this->ref($job)}")->assertOk();

        $response->assertJsonPath('data.description', null);
        $response->assertJsonPath('data.budget_min', null);
        $response->assertJsonPath('data.budget_max', null);
        $response->assertJsonPath('data.is_restricted', true);

        // Not merely absent from the fields above — absent from the payload.
        $this->assertStringNotContainsString('0400 000 000', $response->getContent());
        $this->assertStringNotContainsString('4417', $response->getContent());
    }

    public function test_a_signed_in_carrier_sees_the_brief_and_the_budget(): void
    {
        $job = $this->load();

        $data = $this->actingAs($this->carrier())
            ->getJson("/api/v1/public/loads/{$this->ref($job)}")
            ->assertOk()
            ->assertJsonPath('data.is_restricted', false)
            ->assertJsonPath('data.description', 'Site contact Dave on 0400 000 000, gate code 4417.')
            ->json('data');

        // Compared numerically: JSON drops the fractional part of 1200.0, so
        // an identity check against a float fails on the encoding, not the value.
        $this->assertEqualsWithDelta(1200, $data['budget_min'], 0.001);
        $this->assertEqualsWithDelta(1800, $data['budget_max'], 0.001);
    }

    // --- The shipper: what the subscription buys -----------------------------

    private function subscribedCarrier(): User
    {
        $carrier = $this->carrier();

        $this->seed(\Database\Seeders\SubscriptionPlanSeeder::class);
        Subscription::create([
            'user_id' => $carrier->id,
            'subscription_plan_id' => SubscriptionPlan::where('code', 'monthly')->value('id'),
            'status' => 'active',
            'starts_on' => today()->subDay(),
            'ends_on' => today()->addMonth(),
        ]);

        return $carrier;
    }

    public function test_a_subscribed_carrier_sees_the_shipper(): void
    {
        $job = $this->load();

        $data = $this->actingAs($this->subscribedCarrier())
            ->getJson("/api/v1/public/loads/{$this->ref($job)}")
            ->assertOk()
            ->assertJsonPath('data.shipper_locked', null)
            ->json('data');

        $this->assertSame($job->shipper->email, $data['shipper']['email']);
        $this->assertNotNull($data['shipper']['name']);
    }

    /**
     * The whole point of the gate. 291 of 297 carriers hold no subscription
     * today, so this is the path almost everyone takes.
     */
    public function test_an_unsubscribed_carrier_does_not_see_the_shipper(): void
    {
        $job = $this->load();

        $response = $this->actingAs($this->carrier())
            ->getJson("/api/v1/public/loads/{$this->ref($job)}")
            ->assertOk();

        $response->assertJsonPath('data.shipper', null);
        $response->assertJsonPath('data.shipper_locked', 'subscribe');
        $this->assertStringNotContainsString($job->shipper->email, $response->getContent());
    }

    /**
     * A reserved-but-unpaid plan must not open the door. Without this a
     * carrier holds the paid product forever by choosing a plan and stopping.
     */
    public function test_a_pending_subscription_does_not_release_the_shipper(): void
    {
        $job = $this->load();
        $carrier = $this->carrier();

        $this->seed(\Database\Seeders\SubscriptionPlanSeeder::class);
        Subscription::create([
            'user_id' => $carrier->id,
            'subscription_plan_id' => SubscriptionPlan::where('code', 'monthly')->value('id'),
            'status' => 'pending',
            'starts_on' => today(),
            'ends_on' => today()->addMonth(),
        ]);

        $this->actingAs($carrier)
            ->getJson("/api/v1/public/loads/{$this->ref($job)}")
            ->assertOk()
            ->assertJsonPath('data.shipper', null)
            ->assertJsonPath('data.shipper_locked', 'subscribe');
    }

    /** An expired subscription is not a current one. */
    public function test_a_lapsed_subscription_does_not_release_the_shipper(): void
    {
        $job = $this->load();
        $carrier = $this->carrier();

        $this->seed(\Database\Seeders\SubscriptionPlanSeeder::class);
        Subscription::create([
            'user_id' => $carrier->id,
            'subscription_plan_id' => SubscriptionPlan::where('code', 'monthly')->value('id'),
            'status' => 'active',
            'starts_on' => today()->subMonths(2),
            'ends_on' => today()->subDay(),
        ]);

        $this->actingAs($carrier)
            ->getJson("/api/v1/public/loads/{$this->ref($job)}")
            ->assertOk()
            ->assertJsonPath('data.shipper', null);
    }

    public function test_a_guest_never_sees_the_shipper(): void
    {
        $job = $this->load();

        $response = $this->getJson("/api/v1/public/loads/{$this->ref($job)}")->assertOk();

        $response->assertJsonPath('data.shipper', null);
        $response->assertJsonPath('data.shipper_locked', 'guest');
        $this->assertStringNotContainsString($job->shipper->email, $response->getContent());
    }

    /** Withholding a shipper's own details from them would be absurd. */
    public function test_the_shipper_sees_their_own_load(): void
    {
        $job = $this->load();

        $this->actingAs($job->shipper)
            ->getJson("/api/v1/public/loads/{$this->ref($job)}")
            ->assertOk()
            ->assertJsonPath('data.shipper_locked', null);
    }

    /** The internal key stays internal, whoever is looking. */
    public function test_the_shipper_id_is_never_published(): void
    {
        $job = $this->load();

        foreach ([null, $this->carrier(), $this->subscribedCarrier()] as $viewer) {
            $request = $viewer ? $this->actingAs($viewer) : $this;
            $body = $request->getJson("/api/v1/public/loads/{$this->ref($job)}")->assertOk()->getContent();

            $this->assertStringNotContainsString('shipper_id', $body);
        }
    }

    /** The board withholds ids; the detail payload must not hand them back. */
    public function test_the_primary_key_is_not_published(): void
    {
        $job = $this->load();

        $data = $this->getJson("/api/v1/public/loads/{$this->ref($job)}")->assertOk()->json('data');

        $this->assertArrayNotHasKey('id', $data);
    }

    public function test_a_draft_load_is_not_reachable(): void
    {
        $job = $this->load(['status' => JobStatus::Draft]);

        $this->getJson("/api/v1/public/loads/{$this->ref($job)}")->assertNotFound();
        $this->actingAs($this->carrier())
            ->getJson("/api/v1/public/loads/{$this->ref($job)}")
            ->assertNotFound();
    }

    // --- The owner and the admin see the record, not the board --------------

    /**
     * A shipper opening their own load from My Loads must not be told it does
     * not exist because it is a draft, or finished, or three weeks old. The
     * board's scopes describe browsing; these two are reading a record.
     */
    public function test_a_shipper_can_open_their_own_draft(): void
    {
        $job = $this->load(['status' => JobStatus::Draft]);

        $this->actingAs($job->shipper)
            ->getJson("/api/v1/public/loads/{$this->ref($job)}")
            ->assertOk()
            ->assertJsonPath('data.ref', $this->ref($job));
    }

    public function test_a_shipper_can_open_a_load_older_than_the_board_window(): void
    {
        $job = $this->load();

        // Written straight to the row: Eloquent manages `created_at`, so a
        // forceFill+save is silently ignored and the load stays "new".
        \Illuminate\Support\Facades\DB::table('freight_jobs')
            ->where('id', $job->id)
            ->update(['created_at' => now()->subDays(60), 'relisted_at' => null]);

        // Guest first: `actingAs` persists for the rest of the test, so a
        // signed-out assertion made after it is not signed out at all.
        $this->getJson("/api/v1/public/loads/{$this->ref($job)}")->assertNotFound();

        $this->actingAs($job->shipper)
            ->getJson("/api/v1/public/loads/{$this->ref($job)}")
            ->assertOk();
    }

    public function test_an_admin_can_open_any_load(): void
    {
        $job = $this->load(['status' => JobStatus::Draft]);

        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
        ]);

        $this->actingAs($admin)
            ->getJson("/api/v1/public/loads/{$this->ref($job)}")
            ->assertOk()
            ->assertJsonPath('data.shipper_locked', null);
    }

    /** One shipper must not read another's drafts. */
    public function test_a_shipper_cannot_open_someone_elses_draft(): void
    {
        $job = $this->load(['status' => JobStatus::Draft]);

        $other = User::factory()->create([
            'role' => UserRole::Shipper,
            'status' => UserStatus::Active,
        ]);

        $this->actingAs($other)
            ->getJson("/api/v1/public/loads/{$this->ref($job)}")
            ->assertNotFound();
    }

    public function test_an_unknown_reference_is_a_404(): void
    {
        $this->getJson('/api/v1/public/loads/FM-999999')->assertNotFound();
        $this->getJson('/api/v1/public/loads/nonsense')->assertNotFound();
    }

    /** A link that lost its prefix in a copy-paste should still resolve. */
    public function test_a_bare_number_resolves(): void
    {
        $job = $this->load();

        $this->getJson("/api/v1/public/loads/{$job->id}")
            ->assertOk()
            ->assertJsonPath('data.ref', $this->ref($job));
    }

    /**
     * `loads/recent` is a real endpoint and shares the prefix with the
     * wildcard. Route order is the only thing keeping them apart, and route
     * order is easy to disturb.
     */
    public function test_the_recent_endpoint_still_wins_over_the_wildcard(): void
    {
        $this->load();

        $this->getJson('/api/v1/public/loads/recent')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }
}
