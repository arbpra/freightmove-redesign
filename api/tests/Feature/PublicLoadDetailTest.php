<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\FreightJob;
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

    /**
     * The shipper is withheld from everybody, signed in or not. Their identity
     * is released when a quote is accepted, not when a page is opened.
     */
    public function test_the_shipper_is_never_published(): void
    {
        $job = $this->load();
        $shipper = $job->shipper;

        foreach ([null, $this->carrier()] as $viewer) {
            $request = $viewer ? $this->actingAs($viewer) : $this;
            $body = $request->getJson("/api/v1/public/loads/{$this->ref($job)}")->assertOk()->getContent();

            $this->assertStringNotContainsString($shipper->email, $body);
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
