<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Models\FreightJob;
use App\Models\JobQuote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public teaser of the load board.
 *
 * Most of what matters here is what a guest is *not* shown.
 */
class PublicLoadBoardTest extends TestCase
{
    use RefreshDatabase;

    private function shipper(): User
    {
        return User::factory()->create(['role' => UserRole::Shipper]);
    }

    public function test_it_lists_open_loads_without_an_account(): void
    {
        FreightJob::factory()->count(3)->create([
            'shipper_id' => $this->shipper()->id,
            'status' => JobStatus::Published,
            'visibility' => 'public',
        ]);

        $this->getJson('/api/v1/public/loads/recent')
            ->assertOk()
            ->assertJsonCount(3, 'data.items')
            ->assertJsonPath('data.open_total', 3);
    }

    public function test_it_returns_five_by_default(): void
    {
        FreightJob::factory()->count(9)->create([
            'shipper_id' => $this->shipper()->id,
            'status' => JobStatus::Published,
            'visibility' => 'public',
        ]);

        $this->getJson('/api/v1/public/loads/recent')
            ->assertOk()
            ->assertJsonCount(5, 'data.items')
            // The total still reports every open load, not the page size.
            ->assertJsonPath('data.open_total', 9);
    }

    /**
     * The teaser exists to prove the marketplace is alive, not to let someone
     * work the job without joining.
     */
    public function test_it_withholds_budget_description_and_the_shipper(): void
    {
        $shipper = $this->shipper();

        FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Published,
            'visibility' => 'public',
            'budget_min' => 1200,
            'budget_max' => 2400,
            'description' => 'Gate code 4417, ask for Dave on 0400 000 000.',
        ]);

        $body = $this->getJson('/api/v1/public/loads/recent')->assertOk()->getContent();

        $this->assertStringNotContainsString('1200', $body);
        $this->assertStringNotContainsString('2400', $body);
        $this->assertStringNotContainsString('Gate code', $body);
        $this->assertStringNotContainsString('0400 000 000', $body);
        $this->assertStringNotContainsString($shipper->email, $body);
        $this->assertStringNotContainsString($shipper->name, $body);
    }

    /** No id means there is nothing for a guest to try fetching directly. */
    public function test_it_does_not_expose_load_ids(): void
    {
        FreightJob::factory()->create([
            'shipper_id' => $this->shipper()->id,
            'status' => JobStatus::Published,
            'visibility' => 'public',
        ]);

        $item = $this->getJson('/api/v1/public/loads/recent')->json('data.items.0');

        $this->assertArrayNotHasKey('id', $item);
        $this->assertArrayHasKey('pickup', $item);
    }

    public function test_private_and_unpublished_loads_never_appear(): void
    {
        $shipper = $this->shipper();

        FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Published,
            'visibility' => 'private',
        ]);
        FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Draft,
        ]);
        FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Accepted,
        ]);

        $this->getJson('/api/v1/public/loads/recent')
            ->assertOk()
            ->assertJsonCount(0, 'data.items')
            ->assertJsonPath('data.open_total', 0);
    }

    /** The home page must never advertise freight that has gone stale. */
    public function test_stale_loads_are_excluded_by_the_recency_window(): void
    {
        config(['freightmove.board.recency_days' => 7]);
        $shipper = $this->shipper();

        FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Published,
            'visibility' => 'public',
            'created_at' => now()->subMonths(2),
        ]);
        FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Published,
            'visibility' => 'public',
            'created_at' => now()->subDay(),
        ]);

        $this->getJson('/api/v1/public/loads/recent')
            ->assertOk()
            ->assertJsonCount(1, 'data.items');
    }

    public function test_it_shows_how_many_carriers_have_quoted(): void
    {
        $job = FreightJob::factory()->create([
            'shipper_id' => $this->shipper()->id,
            'status' => JobStatus::Published,
            'visibility' => 'public',
        ]);

        // Two different carriers: one quote per carrier per load is enforced by
        // a unique index, so reusing the same one is not a valid fixture.
        foreach (range(1, 2) as $ignored) {
            JobQuote::factory()->create([
                'job_id' => $job->id,
                'carrier_id' => User::factory()->create(['role' => UserRole::Carrier])->id,
            ]);
        }

        $this->getJson('/api/v1/public/loads/recent')
            ->assertOk()
            ->assertJsonPath('data.items.0.quotes_count', 2);
    }

    public function test_the_limit_is_capped(): void
    {
        $this->getJson('/api/v1/public/loads/recent?limit=500')
            ->assertStatus(422)
            ->assertJsonValidationErrors('limit');
    }

    // -- The full board ------------------------------------------------------

    public function test_the_full_board_is_open_to_anyone(): void
    {
        FreightJob::factory()->count(25)->create([
            'shipper_id' => $this->shipper()->id,
            'status' => JobStatus::Published,
            'visibility' => 'public',
        ]);

        $this->getJson('/api/v1/public/loads')
            ->assertOk()
            ->assertJsonCount(20, 'data.items')
            ->assertJsonPath('data.meta.total', 25)
            ->assertJsonPath('data.meta.last_page', 2);
    }

    /** The same withholding applies here, not just to the home page teaser. */
    public function test_the_full_board_withholds_the_same_details(): void
    {
        $shipper = $this->shipper();

        FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Published,
            'visibility' => 'public',
            'budget_min' => 4321,
            'description' => 'Call Dave on 0400 111 222',
        ]);

        $body = $this->getJson('/api/v1/public/loads')->assertOk()->getContent();

        $this->assertStringNotContainsString('4321', $body);
        $this->assertStringNotContainsString('0400 111 222', $body);
        $this->assertStringNotContainsString($shipper->email, $body);

        $item = $this->getJson('/api/v1/public/loads')->json('data.items.0');
        $this->assertArrayNotHasKey('id', $item);
        // An opaque reference instead, so rows can be tracked without the
        // real id leaving the server.
        $this->assertStringStartsWith('FM-', $item['ref']);
    }

    public function test_the_board_can_be_searched_by_lane(): void
    {
        $shipper = $this->shipper();

        FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Published,
            'visibility' => 'public',
            'pickup_location' => 'Dubbo, NSW',
        ]);
        FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Published,
            'visibility' => 'public',
            'pickup_location' => 'Perth, WA',
        ]);

        $this->getJson('/api/v1/public/loads?search=Dubbo')
            ->assertOk()
            ->assertJsonCount(1, 'data.items');
    }

    public function test_board_search_escapes_like_wildcards(): void
    {
        FreightJob::factory()->create([
            'shipper_id' => $this->shipper()->id,
            'status' => JobStatus::Published,
            'visibility' => 'public',
        ]);

        $this->getJson('/api/v1/public/loads?search=%25')
            ->assertOk()
            ->assertJsonCount(0, 'data.items');
    }

    /** Offering a filter that returns nothing is worse than not offering it. */
    public function test_the_category_filter_only_lists_categories_with_open_freight(): void
    {
        $shipper = $this->shipper();

        FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Published,
            'visibility' => 'public',
            'load_category' => 'Machinery',
        ]);
        // Closed, so its category must not appear as a filter option.
        FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Completed,
            'load_category' => 'Livestock',
        ]);

        $categories = $this->getJson('/api/v1/public/loads')->json('data.categories');

        $this->assertContains('Machinery', $categories);
        $this->assertNotContains('Livestock', $categories);
    }

    /**
     * The client shows "sign in to quote" or "subscribe to quote" from this,
     * rather than hardcoding a rule that lives in config.
     */
    public function test_the_board_reports_what_quoting_requires(): void
    {
        config([
            'freightmove.quoting.require_subscription' => true,
            'freightmove.verification.require_to_quote' => false,
        ]);

        $this->getJson('/api/v1/public/loads')
            ->assertOk()
            ->assertJsonPath('data.quoting.requires_subscription', true)
            ->assertJsonPath('data.quoting.requires_verification', false);
    }
}
