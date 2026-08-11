<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\LoadAvailability;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\FreightJob;
use App\Models\JobQuote;
use App\Models\Subscription;
use App\Models\TruckType;
use App\Models\User;
use Database\Seeders\FreightTaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The carrier load board and quoting.
 *
 * Covers the legacy rules in docs/10-domain-rules.md: R2 (one quote per carrier
 * per load), R3/G4 (subscription gates quoting), R4/G5 (recency window) and
 * R7 (filters combine freely).
 */
class CarrierBoardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FreightTaxonomySeeder::class);
    }

    private function carrier(): User
    {
        return User::factory()->create([
            'role' => UserRole::Carrier,
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

    private function openLoad(array $attributes = []): FreightJob
    {
        return FreightJob::factory()->create(array_merge([
            'shipper_id' => $this->shipper()->id,
            'status' => JobStatus::Published,
            'visibility' => 'public',
            'created_at' => now(),
        ], $attributes));
    }

    // -- Visibility -----------------------------------------------------------

    public function test_the_board_shows_open_loads(): void
    {
        $this->openLoad(['title' => 'Open load']);

        $this->actingAs($this->carrier())
            ->getJson('/api/v1/carrier/board')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.title', 'Open load');
    }

    public function test_drafts_and_cancelled_loads_are_never_on_the_board(): void
    {
        $this->openLoad(['status' => JobStatus::Draft]);
        $this->openLoad(['status' => JobStatus::Cancelled]);
        $this->openLoad(['status' => JobStatus::Completed]);

        $this->actingAs($this->carrier())
            ->getJson('/api/v1/carrier/board')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 0);
    }

    public function test_a_carrier_cannot_read_a_draft_by_guessing_its_id(): void
    {
        $draft = $this->openLoad(['status' => JobStatus::Draft]);

        $this->actingAs($this->carrier())
            ->getJson("/api/v1/carrier/board/{$draft->id}")
            ->assertNotFound();
    }

    public function test_shippers_are_kept_off_the_carrier_board(): void
    {
        $this->actingAs($this->shipper())->getJson('/api/v1/carrier/board')->assertForbidden();
    }

    // -- R4 / G5: the recency window ------------------------------------------

    public function test_loads_older_than_the_window_drop_off_the_board(): void
    {
        config(['freightmove.board.recency_days' => 7]);

        $this->openLoad(['title' => 'Fresh', 'created_at' => now()->subDays(2)]);
        $this->openLoad(['title' => 'Stale', 'created_at' => now()->subDays(30)]);

        $this->actingAs($this->carrier())
            ->getJson('/api/v1/carrier/board')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.title', 'Fresh');
    }

    public function test_relisting_brings_an_old_load_back(): void
    {
        config(['freightmove.board.recency_days' => 7]);

        $this->openLoad([
            'title' => 'Relisted',
            'created_at' => now()->subDays(30),
            'relisted_at' => now(),
        ]);

        $this->actingAs($this->carrier())
            ->getJson('/api/v1/carrier/board')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1);
    }

    public function test_the_window_can_be_switched_off(): void
    {
        config(['freightmove.board.recency_days' => 0]);
        $this->openLoad(['created_at' => now()->subYears(2)]);

        $this->actingAs($this->carrier())
            ->getJson('/api/v1/carrier/board')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1);
    }

    // -- R7: filters combine freely -------------------------------------------

    public function test_filters_combine(): void
    {
        $trailer = TruckType::query()->first();

        $wanted = $this->openLoad([
            'title' => 'Wanted',
            'pickup_location' => 'Brisbane, QLD',
            'delivery_location' => 'Perth, WA',
            'availability' => LoadAvailability::Asap,
        ]);
        $wanted->truckTypes()->attach($trailer->id);

        // Differs on availability only.
        $other = $this->openLoad([
            'pickup_location' => 'Brisbane, QLD',
            'delivery_location' => 'Perth, WA',
            'availability' => LoadAvailability::Planning,
        ]);
        $other->truckTypes()->attach($trailer->id);

        $this->actingAs($this->carrier())
            ->getJson('/api/v1/carrier/board?pickup_state=QLD&delivery_state=WA&availability=asap&truck_type_id='.$trailer->id)
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.title', 'Wanted');
    }

    public function test_a_carrier_can_hide_loads_they_have_already_quoted(): void
    {
        $carrier = $this->carrier();
        $quoted = $this->openLoad(['title' => 'Already priced']);
        $this->openLoad(['title' => 'Still open']);

        JobQuote::factory()->create(['job_id' => $quoted->id, 'carrier_id' => $carrier->id]);

        $this->actingAs($carrier)
            ->getJson('/api/v1/carrier/board?unquoted=1')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.title', 'Still open');
    }

    public function test_the_board_flags_loads_this_carrier_already_quoted(): void
    {
        $carrier = $this->carrier();
        $job = $this->openLoad();
        JobQuote::factory()->create(['job_id' => $job->id, 'carrier_id' => $carrier->id]);

        $this->actingAs($carrier)
            ->getJson('/api/v1/carrier/board')
            ->assertOk()
            ->assertJsonPath('data.items.0.quoted_by_me', true);
    }

    // -- Quoting --------------------------------------------------------------

    public function test_a_carrier_can_quote_on_an_open_load(): void
    {
        $job = $this->openLoad();

        $this->actingAs($this->carrier())
            ->postJson("/api/v1/carrier/board/{$job->id}/quotes", [
                'amount' => 4200.50,
                'notes' => 'Excludes GST and fuel levy.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.amount', 4200.5)
            ->assertJsonPath('data.status', 'pending');
    }

    /** Legacy rule R2. */
    public function test_a_carrier_cannot_quote_the_same_load_twice(): void
    {
        $carrier = $this->carrier();
        $job = $this->openLoad();

        $this->actingAs($carrier)
            ->postJson("/api/v1/carrier/board/{$job->id}/quotes", ['amount' => 1000])
            ->assertCreated();

        $this->actingAs($carrier)
            ->postJson("/api/v1/carrier/board/{$job->id}/quotes", ['amount' => 900])
            ->assertForbidden();

        $this->assertSame(1, JobQuote::where('job_id', $job->id)->count());
    }

    public function test_quoting_a_draft_is_refused(): void
    {
        $draft = $this->openLoad(['status' => JobStatus::Draft]);

        $this->actingAs($this->carrier())
            ->postJson("/api/v1/carrier/board/{$draft->id}/quotes", ['amount' => 1000])
            ->assertNotFound();
    }

    public function test_a_price_is_required(): void
    {
        $job = $this->openLoad();

        $this->actingAs($this->carrier())
            ->postJson("/api/v1/carrier/board/{$job->id}/quotes", ['notes' => 'call me'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');
    }

    // -- R3 / G4: the subscription gate ---------------------------------------

    public function test_quoting_is_open_while_enforcement_is_off(): void
    {
        // The default, chosen because only 2 of 291 migrated carriers hold a
        // current subscription — see config/freightmove.php.
        config(['freightmove.quoting.require_subscription' => false]);

        $job = $this->openLoad();

        $this->actingAs($this->carrier())
            ->postJson("/api/v1/carrier/board/{$job->id}/quotes", ['amount' => 1000])
            ->assertCreated();
    }

    public function test_enforcement_blocks_a_carrier_with_no_subscription(): void
    {
        config(['freightmove.quoting.require_subscription' => true]);

        $job = $this->openLoad();

        $this->actingAs($this->carrier())
            ->postJson("/api/v1/carrier/board/{$job->id}/quotes", ['amount' => 1000])
            ->assertForbidden();
    }

    public function test_enforcement_allows_a_carrier_with_a_current_subscription(): void
    {
        config(['freightmove.quoting.require_subscription' => true]);

        $carrier = $this->carrier();
        Subscription::create([
            'user_id' => $carrier->id,
            'status' => 'active',
            'starts_on' => today()->subMonth(),
            'ends_on' => today()->addMonth(),
        ]);

        $job = $this->openLoad();

        $this->actingAs($carrier)
            ->postJson("/api/v1/carrier/board/{$job->id}/quotes", ['amount' => 1000])
            ->assertCreated();
    }

    public function test_an_expired_subscription_does_not_count(): void
    {
        config(['freightmove.quoting.require_subscription' => true]);

        $carrier = $this->carrier();
        Subscription::create([
            'user_id' => $carrier->id,
            // Status says active but the period has passed: the dates win,
            // because nothing keeps that column fresh as time moves.
            'status' => 'active',
            'starts_on' => today()->subYear(),
            'ends_on' => today()->subDay(),
        ]);

        $job = $this->openLoad();

        $this->actingAs($carrier)
            ->postJson("/api/v1/carrier/board/{$job->id}/quotes", ['amount' => 1000])
            ->assertForbidden();
    }

    public function test_legacy_carriers_keep_quoting_during_the_grace_period(): void
    {
        config([
            'freightmove.quoting.require_subscription' => true,
            'freightmove.quoting.grandfather_legacy_until' => today()->addMonth()->toDateString(),
        ]);

        $carrier = $this->carrier();
        $carrier->forceFill(['legacy_id' => '1645941147'])->save();

        $job = $this->openLoad();

        $this->actingAs($carrier)
            ->postJson("/api/v1/carrier/board/{$job->id}/quotes", ['amount' => 1000])
            ->assertCreated();
    }

    public function test_the_grace_period_expires(): void
    {
        config([
            'freightmove.quoting.require_subscription' => true,
            'freightmove.quoting.grandfather_legacy_until' => today()->subDay()->toDateString(),
        ]);

        $carrier = $this->carrier();
        $carrier->forceFill(['legacy_id' => '1645941147'])->save();

        $job = $this->openLoad();

        $this->actingAs($carrier)
            ->postJson("/api/v1/carrier/board/{$job->id}/quotes", ['amount' => 1000])
            ->assertForbidden();
    }

    // -- My quotes ------------------------------------------------------------

    public function test_a_carrier_sees_only_their_own_quotes(): void
    {
        $mine = $this->carrier();
        $theirs = $this->carrier();

        // Separate loads: one quote per carrier per load is a unique index.
        JobQuote::factory()->create(['job_id' => $this->openLoad()->id, 'carrier_id' => $mine->id]);
        JobQuote::factory()->create(['job_id' => $this->openLoad()->id, 'carrier_id' => $mine->id]);
        JobQuote::factory()->create([
            'job_id' => $this->openLoad()->id,
            'carrier_id' => $theirs->id,
        ]);

        $this->actingAs($mine)
            ->getJson('/api/v1/carrier/quotes')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 2);
    }

    public function test_a_carrier_can_withdraw_a_pending_quote(): void
    {
        $carrier = $this->carrier();
        $quote = JobQuote::factory()->create([
            'job_id' => $this->openLoad()->id,
            'carrier_id' => $carrier->id,
            'status' => 'pending',
        ]);

        $this->actingAs($carrier)
            ->deleteJson("/api/v1/carrier/quotes/{$quote->id}")
            ->assertOk();
    }

    public function test_a_carrier_cannot_withdraw_someone_elses_quote(): void
    {
        $quote = JobQuote::factory()->create([
            'job_id' => $this->openLoad()->id,
            'carrier_id' => $this->carrier()->id,
        ]);

        $this->actingAs($this->carrier())
            ->deleteJson("/api/v1/carrier/quotes/{$quote->id}")
            ->assertForbidden();
    }
}
