<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\QuoteStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\FreightJob;
use App\Models\JobQuote;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Accepting a quote — the step that closes the marketplace loop.
 *
 * The legacy platform recorded no accept or decline state at all, which is why
 * only 4 of 143 legacy quotes could be migrated with an outcome
 * (docs/10-domain-rules.md). Everything here is new behaviour, so it is pinned
 * down carefully.
 */
class QuoteAcceptanceTest extends TestCase
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

    private function carrier(): User
    {
        $user = User::factory()->create([
            'role' => UserRole::Carrier,
            'status' => UserStatus::Active,
        ]);
        UserProfile::factory()->create(['user_id' => $user->id]);

        return $user;
    }

    private function jobWithQuotes(User $shipper, int $count = 3): FreightJob
    {
        $job = FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Quoted,
        ]);

        for ($i = 0; $i < $count; $i++) {
            JobQuote::factory()->create([
                'job_id' => $job->id,
                'carrier_id' => $this->carrier()->id,
                'amount' => 1000 * ($i + 1),
                'status' => QuoteStatus::Pending,
            ]);
        }

        return $job;
    }

    // -- Comparing -------------------------------------------------------------

    public function test_a_shipper_sees_the_quotes_on_their_load_cheapest_first(): void
    {
        $shipper = $this->shipper();
        $job = $this->jobWithQuotes($shipper);

        $response = $this->actingAs($shipper)->getJson("/api/v1/shipper/jobs/{$job->id}/quotes");

        $response->assertOk()
            ->assertJsonCount(3, 'data.items')
            ->assertJsonPath('data.job.can_decide', true);

        // Compared as a sequence: JSON renders a whole float as an integer, so
        // asserting a single path against 1000.0 fails on type alone.
        $this->assertSame([1000, 2000, 3000], array_column($response->json('data.items'), 'amount'));
    }

    public function test_the_comparison_includes_who_the_carrier_is(): void
    {
        $shipper = $this->shipper();
        $job = $this->jobWithQuotes($shipper, 1);

        $this->actingAs($shipper)
            ->getJson("/api/v1/shipper/jobs/{$job->id}/quotes")
            ->assertOk()
            ->assertJsonStructure(['data' => ['items' => [['carrier' => ['id', 'name']]]]]);
    }

    /**
     * Contact details stay hidden until a quote is accepted — otherwise a
     * shipper could harvest every carrier's number by posting a load and never
     * booking.
     */
    public function test_carrier_contact_details_are_withheld_until_acceptance(): void
    {
        $shipper = $this->shipper();
        $job = $this->jobWithQuotes($shipper, 1);
        $quote = $job->quotes()->first();
        $carrier = $quote->carrier;

        $this->actingAs($shipper)
            ->getJson("/api/v1/shipper/jobs/{$job->id}/quotes")
            ->assertOk()
            ->assertDontSee($carrier->email);

        $this->actingAs($shipper)
            ->postJson("/api/v1/shipper/quotes/{$quote->id}/accept")
            ->assertOk()
            ->assertSee($carrier->email);
    }

    public function test_a_shipper_cannot_read_quotes_on_someone_elses_load(): void
    {
        $job = $this->jobWithQuotes($this->shipper());

        $this->actingAs($this->shipper())
            ->getJson("/api/v1/shipper/jobs/{$job->id}/quotes")
            ->assertForbidden();
    }

    // -- Accepting -------------------------------------------------------------

    public function test_accepting_a_quote_books_the_load(): void
    {
        $shipper = $this->shipper();
        $job = $this->jobWithQuotes($shipper);
        $winner = $job->quotes()->orderBy('amount')->first();

        $this->actingAs($shipper)
            ->postJson("/api/v1/shipper/quotes/{$winner->id}/accept")
            ->assertOk()
            ->assertJsonPath('data.quote.status', 'accepted');

        $this->assertDatabaseHas('job_acceptances', [
            'job_id' => $job->id,
            'quote_id' => $winner->id,
            'carrier_id' => $winner->carrier_id,
            'shipper_id' => $shipper->id,
        ]);

        $this->assertSame(JobStatus::Accepted, $job->fresh()->status);
    }

    public function test_accepting_one_quote_declines_the_others(): void
    {
        $shipper = $this->shipper();
        $job = $this->jobWithQuotes($shipper);
        $winner = $job->quotes()->orderBy('amount')->first();

        $this->actingAs($shipper)
            ->postJson("/api/v1/shipper/quotes/{$winner->id}/accept")
            ->assertOk();

        // Nobody is left waiting indefinitely.
        $this->assertSame(
            2,
            JobQuote::where('job_id', $job->id)->where('status', QuoteStatus::Rejected)->count(),
        );
        $this->assertSame(
            0,
            JobQuote::where('job_id', $job->id)->where('status', QuoteStatus::Pending)->count(),
        );
    }

    public function test_a_second_acceptance_on_the_same_load_is_refused(): void
    {
        $shipper = $this->shipper();
        $job = $this->jobWithQuotes($shipper);
        $quotes = $job->quotes()->orderBy('amount')->get();

        $this->actingAs($shipper)
            ->postJson("/api/v1/shipper/quotes/{$quotes[0]->id}/accept")
            ->assertOk();

        // The load is booked, so the runner-up can no longer be accepted.
        $this->actingAs($shipper)
            ->postJson("/api/v1/shipper/quotes/{$quotes[1]->id}/accept")
            ->assertForbidden();

        $this->assertSame(1, \App\Models\JobAcceptance::where('job_id', $job->id)->count());
    }

    public function test_a_shipper_cannot_accept_a_quote_on_someone_elses_load(): void
    {
        $job = $this->jobWithQuotes($this->shipper());
        $quote = $job->quotes()->first();

        $this->actingAs($this->shipper())
            ->postJson("/api/v1/shipper/quotes/{$quote->id}/accept")
            ->assertForbidden();

        $this->assertDatabaseCount('job_acceptances', 0);
    }

    public function test_a_carrier_cannot_accept_quotes(): void
    {
        $job = $this->jobWithQuotes($this->shipper());
        $quote = $job->quotes()->first();

        $this->actingAs($this->carrier())
            ->postJson("/api/v1/shipper/quotes/{$quote->id}/accept")
            ->assertForbidden();
    }

    public function test_a_cancelled_load_cannot_have_a_quote_accepted(): void
    {
        $shipper = $this->shipper();
        $job = $this->jobWithQuotes($shipper);
        $job->update(['status' => JobStatus::Cancelled]);

        $this->actingAs($shipper)
            ->postJson("/api/v1/shipper/quotes/{$job->quotes()->first()->id}/accept")
            ->assertForbidden();
    }

    // -- Declining -------------------------------------------------------------

    public function test_declining_one_quote_leaves_the_load_open(): void
    {
        $shipper = $this->shipper();
        $job = $this->jobWithQuotes($shipper);
        $quote = $job->quotes()->first();

        $this->actingAs($shipper)
            ->postJson("/api/v1/shipper/quotes/{$quote->id}/decline")
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        // Still open, and the other two are untouched.
        $this->assertSame(JobStatus::Quoted, $job->fresh()->status);
        $this->assertSame(
            2,
            JobQuote::where('job_id', $job->id)->where('status', QuoteStatus::Pending)->count(),
        );
    }

    public function test_an_already_declined_quote_cannot_be_accepted(): void
    {
        $shipper = $this->shipper();
        $job = $this->jobWithQuotes($shipper);
        $quote = $job->quotes()->first();

        $this->actingAs($shipper)->postJson("/api/v1/shipper/quotes/{$quote->id}/decline")->assertOk();

        $this->actingAs($shipper)
            ->postJson("/api/v1/shipper/quotes/{$quote->id}/accept")
            ->assertForbidden();
    }
}
