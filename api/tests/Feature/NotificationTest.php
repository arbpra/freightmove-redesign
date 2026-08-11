<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\JobStatus;
use App\Enums\NotificationType;
use App\Enums\QuoteStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\VerificationStatus;
use App\Models\Carrier;
use App\Models\FreightJob;
use App\Models\JobQuote;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\VerificationDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The in-app notification feed.
 *
 * Two things are pinned here beyond the obvious: that the right person is told
 * (never the one who acted), and that a feed failure can never undo the thing
 * it was reporting.
 */
class NotificationTest extends TestCase
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

    private function carrier(array $profile = []): User
    {
        $user = User::factory()->create([
            'role' => UserRole::Carrier,
            'status' => UserStatus::Active,
        ]);
        UserProfile::factory()->create(['user_id' => $user->id, ...$profile]);
        Carrier::factory()->create(['user_id' => $user->id]);

        return $user;
    }

    // -- Events that produce a notification ----------------------------------

    public function test_a_shipper_is_told_when_a_carrier_quotes(): void
    {
        $shipper = $this->shipper();
        $carrier = $this->carrier(['company_name' => 'Whitfield Haulage']);

        $job = FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Published,
            'title' => 'Two pallets to Dubbo',
        ]);

        $this->actingAs($carrier)
            ->postJson("/api/v1/carrier/board/{$job->id}/quotes", ['amount' => 1450])
            ->assertCreated();

        $notification = Notification::where('user_id', $shipper->id)->sole();

        $this->assertSame(NotificationType::QuoteReceived->value, $notification->type);
        $this->assertStringContainsString('Two pallets to Dubbo', $notification->title);
        $this->assertStringContainsString('Whitfield Haulage', $notification->body);
        $this->assertStringContainsString('1,450', $notification->body);
        $this->assertFalse($notification->is_read);
    }

    /** Nobody needs telling about something they just did themselves. */
    public function test_the_carrier_who_quoted_is_not_notified(): void
    {
        $carrier = $this->carrier();
        $job = FreightJob::factory()->create([
            'shipper_id' => $this->shipper()->id,
            'status' => JobStatus::Published,
        ]);

        $this->actingAs($carrier)
            ->postJson("/api/v1/carrier/board/{$job->id}/quotes", ['amount' => 900])
            ->assertCreated();

        $this->assertSame(0, Notification::where('user_id', $carrier->id)->count());
    }

    public function test_accepting_tells_the_winner_and_every_loser(): void
    {
        $shipper = $this->shipper();
        $job = FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Quoted,
        ]);

        $winner = $this->carrier();
        $loserOne = $this->carrier();
        $loserTwo = $this->carrier();

        $winningQuote = JobQuote::factory()->create([
            'job_id' => $job->id,
            'carrier_id' => $winner->id,
            'status' => QuoteStatus::Pending,
        ]);

        foreach ([$loserOne, $loserTwo] as $loser) {
            JobQuote::factory()->create([
                'job_id' => $job->id,
                'carrier_id' => $loser->id,
                'status' => QuoteStatus::Pending,
            ]);
        }

        $this->actingAs($shipper)
            ->postJson("/api/v1/shipper/quotes/{$winningQuote->id}/accept")
            ->assertOk();

        $this->assertSame(
            NotificationType::QuoteAccepted->value,
            Notification::where('user_id', $winner->id)->sole()->type,
        );

        foreach ([$loserOne, $loserTwo] as $loser) {
            $this->assertSame(
                NotificationType::QuoteDeclined->value,
                Notification::where('user_id', $loser->id)->sole()->type,
            );
        }

        // The shipper made the decision; they do not need telling about it.
        $this->assertSame(0, Notification::where('user_id', $shipper->id)->count());
    }

    /**
     * Only the quotes that were pending at the moment of acceptance. Someone
     * declined last week must not be told again.
     */
    public function test_already_declined_carriers_are_not_told_twice(): void
    {
        $shipper = $this->shipper();
        $job = FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Quoted,
        ]);

        $winner = $this->carrier();
        $alreadyOut = $this->carrier();

        $winningQuote = JobQuote::factory()->create([
            'job_id' => $job->id,
            'carrier_id' => $winner->id,
            'status' => QuoteStatus::Pending,
        ]);
        JobQuote::factory()->create([
            'job_id' => $job->id,
            'carrier_id' => $alreadyOut->id,
            'status' => QuoteStatus::Rejected,
        ]);

        $this->actingAs($shipper)
            ->postJson("/api/v1/shipper/quotes/{$winningQuote->id}/accept")
            ->assertOk();

        $this->assertSame(0, Notification::where('user_id', $alreadyOut->id)->count());
    }

    public function test_declining_one_quote_tells_that_carrier(): void
    {
        $shipper = $this->shipper();
        $carrier = $this->carrier();

        $job = FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Quoted,
        ]);
        $quote = JobQuote::factory()->create([
            'job_id' => $job->id,
            'carrier_id' => $carrier->id,
            'status' => QuoteStatus::Pending,
        ]);

        $this->actingAs($shipper)
            ->postJson("/api/v1/shipper/quotes/{$quote->id}/decline")
            ->assertOk();

        $this->assertSame(
            NotificationType::QuoteDeclined->value,
            Notification::where('user_id', $carrier->id)->sole()->type,
        );
    }

    public function test_withdrawing_a_quote_tells_the_shipper(): void
    {
        $shipper = $this->shipper();
        $carrier = $this->carrier();

        $job = FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Published,
        ]);
        $quote = JobQuote::factory()->create([
            'job_id' => $job->id,
            'carrier_id' => $carrier->id,
            'status' => QuoteStatus::Pending,
        ]);

        $this->actingAs($carrier)
            ->deleteJson("/api/v1/carrier/quotes/{$quote->id}")
            ->assertOk();

        $this->assertSame(
            NotificationType::QuoteWithdrawn->value,
            Notification::where('user_id', $shipper->id)->sole()->type,
        );
    }

    public function test_a_document_decision_reaches_the_carrier(): void
    {
        $carrier = $this->carrier();
        $admin = User::factory()->create(['role' => UserRole::Admin, 'status' => UserStatus::Active]);

        $document = VerificationDocument::factory()->create([
            'user_id' => $carrier->id,
            'document_type' => 'abn',
            'status' => DocumentStatus::Pending,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/documents/{$document->id}/reject", [
                'note' => 'The ABN does not match the company name on your profile.',
            ])
            ->assertOk();

        $notification = Notification::where('user_id', $carrier->id)->sole();

        $this->assertSame(NotificationType::DocumentRejected->value, $notification->type);
        // The reviewer's note is the useful part, so it becomes the body.
        $this->assertStringContainsString('does not match', $notification->body);
    }

    public function test_reaching_verified_is_announced_once(): void
    {
        $carrier = $this->carrier(['verification_status' => VerificationStatus::Pending]);
        $admin = User::factory()->create(['role' => UserRole::Admin, 'status' => UserStatus::Active]);

        $abn = VerificationDocument::factory()->create([
            'user_id' => $carrier->id, 'document_type' => 'abn', 'status' => DocumentStatus::Pending,
        ]);
        $insurance = VerificationDocument::factory()->create([
            'user_id' => $carrier->id, 'document_type' => 'insurance', 'status' => DocumentStatus::Pending,
        ]);

        $this->actingAs($admin)->postJson("/api/v1/admin/documents/{$abn->id}/approve")->assertOk();

        // Not verified yet, so no announcement.
        $this->assertSame(
            0,
            Notification::where('user_id', $carrier->id)
                ->where('type', NotificationType::CarrierVerified->value)
                ->count(),
        );

        $this->actingAs($admin)->postJson("/api/v1/admin/documents/{$insurance->id}/approve")->assertOk();

        $this->assertSame(
            1,
            Notification::where('user_id', $carrier->id)
                ->where('type', NotificationType::CarrierVerified->value)
                ->count(),
        );
    }

    // -- The feed ------------------------------------------------------------

    public function test_the_feed_returns_only_my_notifications(): void
    {
        $mine = $this->shipper();
        $theirs = $this->shipper();

        Notification::factory()->count(3)->create(['user_id' => $mine->id]);
        Notification::factory()->count(2)->create(['user_id' => $theirs->id]);

        $response = $this->actingAs($mine)->getJson('/api/v1/notifications')->assertOk();

        $this->assertCount(3, $response->json('data.items'));
    }

    public function test_the_feed_can_be_narrowed_to_unread(): void
    {
        $user = $this->shipper();

        Notification::factory()->count(2)->create(['user_id' => $user->id, 'is_read' => false]);
        Notification::factory()->count(3)->create(['user_id' => $user->id, 'is_read' => true]);

        $response = $this->actingAs($user)
            ->getJson('/api/v1/notifications?unread=1')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 2);

        $this->assertCount(2, $response->json('data.items'));
    }

    /**
     * The badge must not change as someone pages through, so the count is the
     * total unread rather than the number on this page.
     */
    public function test_the_unread_count_ignores_pagination(): void
    {
        $user = $this->shipper();
        Notification::factory()->count(9)->create(['user_id' => $user->id, 'is_read' => false]);

        $this->actingAs($user)
            ->getJson('/api/v1/notifications?per_page=2')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 9);
    }

    public function test_the_count_endpoint_answers_without_the_rows(): void
    {
        $user = $this->shipper();
        Notification::factory()->count(4)->create(['user_id' => $user->id, 'is_read' => false]);

        $this->actingAs($user)
            ->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 4)
            ->assertJsonMissingPath('data.items');
    }

    public function test_one_notification_can_be_marked_read(): void
    {
        $user = $this->shipper();
        $notification = Notification::factory()->create(['user_id' => $user->id, 'is_read' => false]);

        $this->actingAs($user)
            ->patchJson("/api/v1/notifications/{$notification->id}/read")
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        $this->assertTrue($notification->fresh()->is_read);
    }

    public function test_everything_can_be_marked_read_at_once(): void
    {
        $user = $this->shipper();
        Notification::factory()->count(5)->create(['user_id' => $user->id, 'is_read' => false]);

        $this->actingAs($user)
            ->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        $this->assertSame(0, $user->appNotifications()->unread()->count());
    }

    public function test_marking_someone_elses_notification_is_a_404(): void
    {
        $notification = Notification::factory()->create([
            'user_id' => $this->shipper()->id,
            'is_read' => false,
        ]);

        $this->actingAs($this->shipper())
            ->patchJson("/api/v1/notifications/{$notification->id}/read")
            ->assertNotFound();

        $this->assertFalse($notification->fresh()->is_read);
    }

    public function test_read_all_does_not_touch_other_users(): void
    {
        $mine = $this->shipper();
        $theirs = $this->shipper();

        Notification::factory()->create(['user_id' => $mine->id, 'is_read' => false]);
        Notification::factory()->create(['user_id' => $theirs->id, 'is_read' => false]);

        $this->actingAs($mine)->postJson('/api/v1/notifications/read-all')->assertOk();

        $this->assertSame(1, $theirs->appNotifications()->unread()->count());
    }

    public function test_guests_are_refused(): void
    {
        $this->getJson('/api/v1/notifications')->assertUnauthorized();
    }
}
