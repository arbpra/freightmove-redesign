<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\NotificationType;
use App\Enums\QuoteStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Carrier;
use App\Models\FreightJob;
use App\Models\JobAcceptance;
use App\Models\JobQuote;
use App\Models\Notification;
use App\Models\Review;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\ReputationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Closing a load out, and the reviews that follow.
 *
 * Both were missing entirely: nothing could move a job to `completed`, and the
 * rating displayed beside every carrier's quote was written only by the
 * factory — a reputation with nothing behind it.
 */
class CompletionAndReviewTest extends TestCase
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
        Carrier::factory()->create(['user_id' => $user->id]);

        return $user;
    }

    /** A load already booked with a carrier. */
    private function bookedLoad(User $shipper, User $carrier): FreightJob
    {
        $job = FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Accepted,
        ]);

        $quote = JobQuote::factory()->create([
            'job_id' => $job->id,
            'carrier_id' => $carrier->id,
            'status' => QuoteStatus::Accepted,
        ]);

        JobAcceptance::create([
            'job_id' => $job->id,
            'quote_id' => $quote->id,
            'carrier_id' => $carrier->id,
            'shipper_id' => $shipper->id,
            'accepted_at' => now(),
        ]);

        return $job;
    }

    // -- Completion ----------------------------------------------------------

    public function test_a_shipper_completes_a_booked_load(): void
    {
        $shipper = $this->shipper();
        $carrier = $this->carrier();
        $job = $this->bookedLoad($shipper, $carrier);

        $this->actingAs($shipper)
            ->postJson("/api/v1/shipper/jobs/{$job->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertSame(JobStatus::Completed, $job->fresh()->status);
    }

    /**
     * The carrier has an obvious interest in a job being closed — it ends the
     * window in which a problem can be raised — so the party who received the
     * freight is the one who says it arrived.
     */
    public function test_the_carrier_cannot_complete_the_job_themselves(): void
    {
        $shipper = $this->shipper();
        $carrier = $this->carrier();
        $job = $this->bookedLoad($shipper, $carrier);

        $this->actingAs($carrier)
            ->postJson("/api/v1/shipper/jobs/{$job->id}/complete")
            ->assertForbidden();

        $this->assertSame(JobStatus::Accepted, $job->fresh()->status);
    }

    public function test_only_a_booked_load_can_be_completed(): void
    {
        $shipper = $this->shipper();

        foreach ([JobStatus::Draft, JobStatus::Published, JobStatus::Cancelled] as $status) {
            $job = FreightJob::factory()->create([
                'shipper_id' => $shipper->id,
                'status' => $status,
            ]);

            $this->actingAs($shipper)
                ->postJson("/api/v1/shipper/jobs/{$job->id}/complete")
                ->assertForbidden();
        }
    }

    public function test_completing_notifies_the_carrier_and_updates_both_records(): void
    {
        $shipper = $this->shipper();
        $carrier = $this->carrier();
        $job = $this->bookedLoad($shipper, $carrier);

        $this->actingAs($shipper)
            ->postJson("/api/v1/shipper/jobs/{$job->id}/complete")
            ->assertOk();

        $this->assertSame(
            NotificationType::JobCompleted->value,
            Notification::where('user_id', $carrier->id)->sole()->type,
        );

        // Counted from the jobs, not from reviews: a track record should not
        // depend on whether the other side got round to writing one.
        $this->assertSame(1, $carrier->fresh()->profile->completed_jobs_count);
        $this->assertSame(1, $shipper->fresh()->profile->completed_jobs_count);
    }

    // -- Reviews -------------------------------------------------------------

    private function completedLoad(User $shipper, User $carrier): FreightJob
    {
        $job = $this->bookedLoad($shipper, $carrier);

        $this->actingAs($shipper)
            ->postJson("/api/v1/shipper/jobs/{$job->id}/complete")
            ->assertOk();

        return $job->fresh();
    }

    public function test_both_sides_can_review_a_completed_load(): void
    {
        $shipper = $this->shipper();
        $carrier = $this->carrier();
        $job = $this->completedLoad($shipper, $carrier);

        $this->actingAs($shipper)
            ->postJson("/api/v1/jobs/{$job->id}/reviews", ['rating' => 5, 'comment' => 'On time.'])
            ->assertCreated();

        $this->actingAs($carrier)
            ->postJson("/api/v1/jobs/{$job->id}/reviews", ['rating' => 4, 'comment' => 'Easy load.'])
            ->assertCreated();

        $this->assertSame(2, Review::count());
    }

    /** The target is derived from the load, never sent by the client. */
    public function test_a_review_is_aimed_at_the_other_party_automatically(): void
    {
        $shipper = $this->shipper();
        $carrier = $this->carrier();
        $job = $this->completedLoad($shipper, $carrier);

        $this->actingAs($shipper)
            ->postJson("/api/v1/jobs/{$job->id}/reviews", [
                'rating' => 5,
                // An attempt to aim it elsewhere is simply ignored.
                'reviewed_user_id' => $shipper->id,
            ])
            ->assertCreated();

        $this->assertSame($carrier->id, Review::sole()->reviewed_user_id);
    }

    public function test_a_load_that_is_not_complete_cannot_be_reviewed(): void
    {
        $shipper = $this->shipper();
        $carrier = $this->carrier();
        $job = $this->bookedLoad($shipper, $carrier);

        $this->actingAs($shipper)
            ->postJson("/api/v1/jobs/{$job->id}/reviews", ['rating' => 5])
            ->assertForbidden();
    }

    public function test_a_bystander_cannot_review(): void
    {
        $job = $this->completedLoad($this->shipper(), $this->carrier());

        $this->actingAs($this->carrier())
            ->postJson("/api/v1/jobs/{$job->id}/reviews", ['rating' => 1])
            ->assertForbidden();

        $this->assertSame(0, Review::count());
    }

    public function test_nobody_reviews_the_same_load_twice(): void
    {
        $shipper = $this->shipper();
        $job = $this->completedLoad($shipper, $this->carrier());

        $this->actingAs($shipper)
            ->postJson("/api/v1/jobs/{$job->id}/reviews", ['rating' => 5])
            ->assertCreated();

        $this->actingAs($shipper)
            ->postJson("/api/v1/jobs/{$job->id}/reviews", ['rating' => 1])
            ->assertForbidden();

        $this->assertSame(1, Review::count());
    }

    public function test_a_rating_outside_one_to_five_is_refused(): void
    {
        $shipper = $this->shipper();
        $job = $this->completedLoad($shipper, $this->carrier());

        foreach ([0, 6, -1] as $rating) {
            $this->actingAs($shipper)
                ->postJson("/api/v1/jobs/{$job->id}/reviews", ['rating' => $rating])
                ->assertStatus(422)
                ->assertJsonValidationErrors('rating');
        }
    }

    public function test_a_review_notifies_the_person_reviewed(): void
    {
        $shipper = $this->shipper();
        $carrier = $this->carrier();
        $job = $this->completedLoad($shipper, $carrier);

        // Clear the completion notification so only the review one remains.
        Notification::query()->delete();

        $this->actingAs($shipper)
            ->postJson("/api/v1/jobs/{$job->id}/reviews", ['rating' => 5])
            ->assertCreated();

        $this->assertSame(
            NotificationType::ReviewReceived->value,
            Notification::where('user_id', $carrier->id)->sole()->type,
        );
    }

    // -- Reputation ----------------------------------------------------------

    public function test_the_rating_is_the_average_of_reviews_received(): void
    {
        $carrier = $this->carrier();

        foreach ([5, 4, 3] as $rating) {
            $shipper = $this->shipper();
            $job = $this->completedLoad($shipper, $carrier);

            $this->actingAs($shipper)
                ->postJson("/api/v1/jobs/{$job->id}/reviews", ['rating' => $rating])
                ->assertCreated();
        }

        $this->assertSame('4.00', $carrier->fresh()->profile->rating);
        $this->assertSame(3, $carrier->fresh()->profile->completed_jobs_count);
    }

    /**
     * "Not rated yet" and "rated zero" are opposite claims, and the client
     * renders them differently.
     */
    public function test_an_unreviewed_user_is_unrated_not_zero(): void
    {
        $carrier = $this->carrier();

        app(ReputationService::class)->refresh($carrier);

        $this->assertNull($carrier->fresh()->profile->rating);
    }

    /**
     * The columns are derived. A profile update must not be able to assert
     * them, and neither must anything else.
     */
    public function test_a_recompute_clears_a_rating_with_no_reviews_behind_it(): void
    {
        $carrier = $this->carrier();
        $carrier->profile->forceFill(['rating' => 4.9, 'completed_jobs_count' => 180])->save();

        $this->artisan('reputations:recompute')->assertSuccessful();

        $profile = $carrier->fresh()->profile;
        $this->assertNull($profile->rating);
        $this->assertSame(0, $profile->completed_jobs_count);
    }

    public function test_the_dry_run_writes_nothing(): void
    {
        $carrier = $this->carrier();
        $carrier->profile->forceFill(['rating' => 4.9])->save();

        $this->artisan('reputations:recompute --dry-run')->assertSuccessful();

        $this->assertNotNull($carrier->fresh()->profile->rating);
    }

    // -- Reading -------------------------------------------------------------

    public function test_the_parties_can_read_the_reviews_on_their_load(): void
    {
        $shipper = $this->shipper();
        $carrier = $this->carrier();
        $job = $this->completedLoad($shipper, $carrier);

        $this->actingAs($shipper)
            ->postJson("/api/v1/jobs/{$job->id}/reviews", ['rating' => 5, 'comment' => 'Great.'])
            ->assertCreated();

        $this->actingAs($carrier)
            ->getJson("/api/v1/jobs/{$job->id}/reviews")
            ->assertOk()
            ->assertJsonPath('data.items.0.rating', 5)
            ->assertJsonPath('data.items.0.by_me', false)
            // The carrier has not written theirs yet.
            ->assertJsonPath('data.can_review', true)
            ->assertJsonPath('data.already_reviewed', false);
    }

    public function test_a_bystander_cannot_read_them(): void
    {
        $job = $this->completedLoad($this->shipper(), $this->carrier());

        $this->actingAs($this->shipper())
            ->getJson("/api/v1/jobs/{$job->id}/reviews")
            ->assertForbidden();
    }
}
