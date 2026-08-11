<?php

namespace Tests\Feature;

use App\Models\RouteDistance;
use App\Models\Suburb;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suburb autocomplete and the cached route distances (G3).
 *
 * Both are public: the marketing quote form needs them before anyone signs in.
 */
class PublicRouteLookupTest extends TestCase
{
    use RefreshDatabase;

    private function suburb(string $name, string $state = 'NSW'): Suburb
    {
        return Suburb::create(['name' => $name, 'state' => $state]);
    }

    // -- Autocomplete --------------------------------------------------------

    public function test_suburb_search_matches_on_name(): void
    {
        $this->suburb('Port Macquarie');
        $this->suburb('Newport');
        $this->suburb('Katoomba');

        $response = $this->getJson('/api/v1/public/suburbs?q=port')->assertOk();

        $names = array_column($response->json('data.items'), 'name');

        $this->assertContains('Port Macquarie', $names);
        $this->assertContains('Newport', $names);
        $this->assertNotContains('Katoomba', $names);
    }

    public function test_names_starting_with_the_term_come_first(): void
    {
        $this->suburb('Newport');
        $this->suburb('Port Macquarie');

        $names = array_column(
            $this->getJson('/api/v1/public/suburbs?q=port')->json('data.items'),
            'name',
        );

        $this->assertSame('Port Macquarie', $names[0]);
    }

    public function test_suburb_search_can_be_narrowed_to_a_state(): void
    {
        $this->suburb('Richmond', 'NSW');
        $this->suburb('Richmond', 'VIC');

        $items = $this->getJson('/api/v1/public/suburbs?q=richmond&state=vic')
            ->assertOk()
            ->json('data.items');

        $this->assertCount(1, $items);
        $this->assertSame('VIC', $items[0]['state']);
    }

    /**
     * `%` is a LIKE wildcard. Unescaped it would dump the whole table, which is
     * both a data-shape leak and a cheap way to make the database work hard.
     */
    public function test_like_wildcards_in_the_query_are_escaped(): void
    {
        $this->suburb('Bondi');
        $this->suburb('Manly');

        // %25%25 is "%%" once decoded — two characters, so it clears the
        // minimum length and actually reaches the query.
        $this->assertSame(
            [],
            $this->getJson('/api/v1/public/suburbs?q=%25%25')->assertOk()->json('data.items'),
        );
    }

    public function test_suburb_search_requires_a_usable_term(): void
    {
        $this->getJson('/api/v1/public/suburbs')->assertStatus(422);
        $this->getJson('/api/v1/public/suburbs?q=a')->assertStatus(422);
    }

    // -- Cached distances ----------------------------------------------------

    public function test_a_cached_lane_returns_its_distance(): void
    {
        $pickup = $this->suburb('Sydney');
        $dropoff = $this->suburb('Melbourne', 'VIC');

        RouteDistance::create([
            'pickup_suburb_id' => $pickup->id,
            'dropoff_suburb_id' => $dropoff->id,
            'distance_metres' => 878000,
            'duration_seconds' => 32400,
            'distance_text' => '878 km',
            'duration_text' => '9 hours',
        ]);

        $response = $this->getJson("/api/v1/public/routes/{$pickup->id}/{$dropoff->id}")
            ->assertOk()
            ->assertJsonPath('data.cached', true)
            ->assertJsonPath('data.distance_text', '878 km')
            ->assertJsonPath('data.pickup', 'Sydney, NSW');

        // Cast before comparing: JSON renders a whole float as an integer.
        $this->assertSame(878.0, (float) $response->json('data.distance_km'));
    }

    public function test_a_lane_that_is_not_cached_is_not_an_error(): void
    {
        $pickup = $this->suburb('Sydney');
        $dropoff = $this->suburb('Broome', 'WA');

        $this->getJson("/api/v1/public/routes/{$pickup->id}/{$dropoff->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.cached', false);
    }

    public function test_serving_from_cache_records_the_hit(): void
    {
        $pickup = $this->suburb('Sydney');
        $dropoff = $this->suburb('Newcastle');

        $route = RouteDistance::create([
            'pickup_suburb_id' => $pickup->id,
            'dropoff_suburb_id' => $dropoff->id,
            'distance_metres' => 162000,
            'duration_seconds' => 7200,
        ]);

        $this->getJson("/api/v1/public/routes/{$pickup->id}/{$dropoff->id}")->assertOk();
        $this->getJson("/api/v1/public/routes/{$pickup->id}/{$dropoff->id}")->assertOk();

        $route->refresh();
        $this->assertSame(2, $route->lookups);
        $this->assertNotNull($route->last_used_at);
    }

    /**
     * The legacy cache holds both directions separately for 20 pairs and the
     * figures differ, so the reverse must not be served as an answer.
     */
    public function test_the_cache_is_directional(): void
    {
        $pickup = $this->suburb('Sydney');
        $dropoff = $this->suburb('Canberra', 'ACT');

        RouteDistance::create([
            'pickup_suburb_id' => $pickup->id,
            'dropoff_suburb_id' => $dropoff->id,
            'distance_metres' => 286000,
            'duration_seconds' => 10800,
        ]);

        $this->getJson("/api/v1/public/routes/{$dropoff->id}/{$pickup->id}")
            ->assertOk()
            ->assertJsonPath('data.cached', false);
    }

    public function test_an_unknown_suburb_is_a_404(): void
    {
        $suburb = $this->suburb('Sydney');

        $this->getJson("/api/v1/public/routes/{$suburb->id}/999999")->assertNotFound();
    }

    // -- Parsing the legacy formats -----------------------------------------

    /**
     * The legacy table stored whatever the Distance Matrix API returned: mostly
     * display text, occasionally the raw integers from an earlier code path.
     * These are the exact shapes present in the live data.
     */
    public function test_legacy_distance_formats_parse_to_metres(): void
    {
        $this->assertSame(3616000, RouteDistance::metresFrom('3,616 km'));
        $this->assertSame(21100, RouteDistance::metresFrom('21.1 km'));
        $this->assertSame(1, RouteDistance::metresFrom('1 m'));
        $this->assertSame(713686, RouteDistance::metresFrom('713686'));
        $this->assertNull(RouteDistance::metresFrom(''));
        $this->assertNull(RouteDistance::metresFrom('a while'));
    }

    public function test_legacy_duration_formats_parse_to_seconds(): void
    {
        $this->assertSame(140400, RouteDistance::secondsFrom('1 day 15 hours'));
        $this->assertSame(61500, RouteDistance::secondsFrom('17 hours 5 mins'));
        $this->assertSame(3780, RouteDistance::secondsFrom('1 hour 3 min'));
        $this->assertSame(180000, RouteDistance::secondsFrom('2 days 2 hours'));
        $this->assertSame(60, RouteDistance::secondsFrom('1 min'));
        $this->assertSame(27076, RouteDistance::secondsFrom('27076'));
        $this->assertNull(RouteDistance::secondsFrom(''));
        $this->assertNull(RouteDistance::secondsFrom('ages'));
    }
}
