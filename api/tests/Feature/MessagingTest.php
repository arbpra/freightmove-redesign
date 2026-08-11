<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\NotificationType;
use App\Enums\QuoteStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Carrier;
use App\Models\Conversation;
use App\Models\FreightJob;
use App\Models\JobQuote;
use App\Models\Message;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Messaging between a shipper and a carrier about one load.
 *
 * The access rule is the interesting part: a carrier must have quoted before a
 * thread can exist. That is the disintermediation guard — see
 * ConversationPolicy for why it is drawn there.
 */
class MessagingTest extends TestCase
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

    /** A load with one carrier who has quoted on it. */
    private function quotedLoad(User $shipper, User $carrier, JobStatus $status = JobStatus::Quoted): FreightJob
    {
        $job = FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => $status,
        ]);

        JobQuote::factory()->create([
            'job_id' => $job->id,
            'carrier_id' => $carrier->id,
            'status' => QuoteStatus::Pending,
        ]);

        return $job;
    }

    // -- Opening a thread ----------------------------------------------------

    public function test_a_carrier_who_quoted_can_open_a_thread(): void
    {
        $shipper = $this->shipper();
        $carrier = $this->carrier();
        $job = $this->quotedLoad($shipper, $carrier);

        $this->actingAs($carrier)
            ->postJson('/api/v1/conversations', ['job_id' => $job->id])
            ->assertCreated();

        $conversation = Conversation::sole();
        $this->assertTrue($conversation->includes($carrier->id));
        $this->assertTrue($conversation->includes($shipper->id));
    }

    /**
     * The guard. An open channel from any carrier to any shipper would route
     * straight around the withheld contact details.
     */
    public function test_a_carrier_who_has_not_quoted_cannot_open_a_thread(): void
    {
        $shipper = $this->shipper();
        $lurker = $this->carrier();

        $job = FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Published,
        ]);

        $this->actingAs($lurker)
            ->postJson('/api/v1/conversations', ['job_id' => $job->id])
            ->assertForbidden();

        $this->assertSame(0, Conversation::count());
    }

    public function test_a_shipper_must_name_the_carrier(): void
    {
        $shipper = $this->shipper();
        $job = $this->quotedLoad($shipper, $this->carrier());

        $this->actingAs($shipper)
            ->postJson('/api/v1/conversations', ['job_id' => $job->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.with_user_id.0', 'Required when you posted the load.');
    }

    public function test_a_shipper_can_open_a_thread_with_a_carrier_who_quoted(): void
    {
        $shipper = $this->shipper();
        $carrier = $this->carrier();
        $job = $this->quotedLoad($shipper, $carrier);

        $this->actingAs($shipper)
            ->postJson('/api/v1/conversations', [
                'job_id' => $job->id,
                'with_user_id' => $carrier->id,
            ])
            ->assertCreated();

        $this->assertSame(1, Conversation::count());
    }

    public function test_a_shipper_cannot_message_a_carrier_who_never_quoted(): void
    {
        $shipper = $this->shipper();
        $stranger = $this->carrier();

        $job = FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Published,
        ]);

        $this->actingAs($shipper)
            ->postJson('/api/v1/conversations', [
                'job_id' => $job->id,
                'with_user_id' => $stranger->id,
            ])
            ->assertForbidden();
    }

    public function test_nobody_can_open_a_thread_on_someone_elses_load(): void
    {
        $carrier = $this->carrier();
        $job = $this->quotedLoad($this->shipper(), $carrier);

        // A different shipper, with no connection to this load at all.
        $this->actingAs($this->shipper())
            ->postJson('/api/v1/conversations', [
                'job_id' => $job->id,
                'with_user_id' => $carrier->id,
            ])
            ->assertForbidden();
    }

    /**
     * The unique index is on (job, one, two) in that order, so the pair has to
     * be stored consistently or the same conversation opens twice.
     */
    public function test_opening_twice_from_either_side_returns_one_thread(): void
    {
        $shipper = $this->shipper();
        $carrier = $this->carrier();
        $job = $this->quotedLoad($shipper, $carrier);

        $first = $this->actingAs($carrier)
            ->postJson('/api/v1/conversations', ['job_id' => $job->id])
            ->assertCreated()
            ->json('data.id');

        $second = $this->actingAs($shipper)
            ->postJson('/api/v1/conversations', [
                'job_id' => $job->id,
                'with_user_id' => $carrier->id,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Conversation::count());
    }

    public function test_participants_are_stored_lowest_id_first(): void
    {
        $shipper = $this->shipper();
        $carrier = $this->carrier();
        $job = $this->quotedLoad($shipper, $carrier);

        $conversation = Conversation::between($job->id, $carrier->id, $shipper->id);

        $this->assertSame(min($shipper->id, $carrier->id), $conversation->participant_one_id);
        $this->assertSame(max($shipper->id, $carrier->id), $conversation->participant_two_id);
    }

    // -- Sending and reading -------------------------------------------------

    public function test_messages_go_back_and_forth(): void
    {
        $shipper = $this->shipper();
        $carrier = $this->carrier();
        $job = $this->quotedLoad($shipper, $carrier);
        $conversation = Conversation::between($job->id, $shipper->id, $carrier->id);

        $this->actingAs($carrier)
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
                'body' => 'Is there a forklift at the delivery end?',
            ])
            ->assertCreated();

        $this->actingAs($shipper)
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
                'body' => 'Yes, and a dock.',
            ])
            ->assertCreated();

        $items = $this->actingAs($carrier)
            ->getJson("/api/v1/conversations/{$conversation->id}")
            ->assertOk()
            ->json('data.items');

        // Oldest first, so a thread reads top to bottom.
        $this->assertSame('Is there a forklift at the delivery end?', $items[0]['body']);
        $this->assertTrue($items[0]['sent_by_me']);
        $this->assertFalse($items[1]['sent_by_me']);
    }

    public function test_an_empty_message_is_refused(): void
    {
        $shipper = $this->shipper();
        $carrier = $this->carrier();
        $job = $this->quotedLoad($shipper, $carrier);
        $conversation = Conversation::between($job->id, $shipper->id, $carrier->id);

        $this->actingAs($carrier)
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", ['body' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');
    }

    public function test_a_stranger_cannot_read_or_post_to_a_thread(): void
    {
        $shipper = $this->shipper();
        $carrier = $this->carrier();
        $job = $this->quotedLoad($shipper, $carrier);
        $conversation = Conversation::between($job->id, $shipper->id, $carrier->id);

        $stranger = $this->carrier();

        $this->actingAs($stranger)
            ->getJson("/api/v1/conversations/{$conversation->id}")
            ->assertForbidden();

        $this->actingAs($stranger)
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", ['body' => 'hello'])
            ->assertForbidden();
    }

    /**
     * A conversation is private to its two participants. Support reading
     * customer messages should be a deliberate, audited feature, not a side
     * effect of the admin bypass every other policy uses.
     */
    public function test_an_admin_cannot_read_a_thread_either(): void
    {
        $shipper = $this->shipper();
        $carrier = $this->carrier();
        $job = $this->quotedLoad($shipper, $carrier);
        $conversation = Conversation::between($job->id, $shipper->id, $carrier->id);

        $admin = User::factory()->create(['role' => UserRole::Admin, 'status' => UserStatus::Active]);

        $this->actingAs($admin)
            ->getJson("/api/v1/conversations/{$conversation->id}")
            ->assertForbidden();
    }

    public function test_opening_a_thread_marks_the_other_sides_messages_read(): void
    {
        $shipper = $this->shipper();
        $carrier = $this->carrier();
        $job = $this->quotedLoad($shipper, $carrier);
        $conversation = Conversation::between($job->id, $shipper->id, $carrier->id);

        $incoming = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $carrier->id,
            'read_at' => null,
        ]);
        $mine = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $shipper->id,
            'read_at' => null,
        ]);

        $this->actingAs($shipper)
            ->getJson("/api/v1/conversations/{$conversation->id}")
            ->assertOk();

        $this->assertNotNull($incoming->fresh()->read_at);
        // My own message stays untouched — I did not read it, I wrote it.
        $this->assertNull($mine->fresh()->read_at);
    }

    public function test_the_list_counts_only_messages_sent_to_me(): void
    {
        $shipper = $this->shipper();
        $carrier = $this->carrier();
        $job = $this->quotedLoad($shipper, $carrier);
        $conversation = Conversation::between($job->id, $shipper->id, $carrier->id);

        Message::factory()->count(3)->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $carrier->id,
            'read_at' => null,
        ]);
        Message::factory()->count(2)->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $shipper->id,
            'read_at' => null,
        ]);

        $this->actingAs($shipper)
            ->getJson('/api/v1/conversations')
            ->assertOk()
            ->assertJsonPath('data.items.0.unread_count', 3)
            ->assertJsonPath('data.unread_total', 3);
    }

    public function test_the_list_shows_only_my_threads(): void
    {
        $mine = $this->shipper();
        $carrier = $this->carrier();
        Conversation::between($this->quotedLoad($mine, $carrier)->id, $mine->id, $carrier->id);

        $other = $this->shipper();
        $otherCarrier = $this->carrier();
        Conversation::between(
            $this->quotedLoad($other, $otherCarrier)->id,
            $other->id,
            $otherCarrier->id,
        );

        $items = $this->actingAs($mine)->getJson('/api/v1/conversations')->assertOk()->json('data.items');

        $this->assertCount(1, $items);
    }

    public function test_a_closed_load_leaves_the_thread_readable_but_silent(): void
    {
        $shipper = $this->shipper();
        $carrier = $this->carrier();
        $job = $this->quotedLoad($shipper, $carrier, JobStatus::Cancelled);
        $conversation = Conversation::between($job->id, $shipper->id, $carrier->id);

        $this->actingAs($carrier)
            ->getJson("/api/v1/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('data.can_send', false);

        $this->actingAs($carrier)
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", ['body' => 'still there?'])
            ->assertForbidden();
    }

    // -- Notifications -------------------------------------------------------

    public function test_the_recipient_is_notified(): void
    {
        $shipper = $this->shipper();
        $carrier = $this->carrier(['company_name' => 'Whitfield Haulage']);
        $job = $this->quotedLoad($shipper, $carrier);
        $conversation = Conversation::between($job->id, $shipper->id, $carrier->id);

        $this->actingAs($carrier)
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
                'body' => 'Can we load at 6am?',
            ])
            ->assertCreated();

        $notification = Notification::where('user_id', $shipper->id)->sole();

        $this->assertSame(NotificationType::MessageReceived->value, $notification->type);
        $this->assertStringContainsString('Whitfield Haulage', $notification->title);
        $this->assertStringContainsString('6am', $notification->body);

        // The sender is not told about their own message.
        $this->assertSame(0, Notification::where('user_id', $carrier->id)->count());
    }

    /**
     * A chat is a burst of short messages. One notification per message turns
     * the feed into a second, worse inbox.
     */
    public function test_a_burst_of_messages_raises_one_notification(): void
    {
        $shipper = $this->shipper();
        $carrier = $this->carrier();
        $job = $this->quotedLoad($shipper, $carrier);
        $conversation = Conversation::between($job->id, $shipper->id, $carrier->id);

        foreach (['One', 'Two', 'Three', 'Four'] as $line) {
            $this->actingAs($carrier)
                ->postJson("/api/v1/conversations/{$conversation->id}/messages", ['body' => $line])
                ->assertCreated();
        }

        $this->assertSame(1, Notification::where('user_id', $shipper->id)->count());
    }

    public function test_a_fresh_notification_is_raised_once_the_last_was_read(): void
    {
        $shipper = $this->shipper();
        $carrier = $this->carrier();
        $job = $this->quotedLoad($shipper, $carrier);
        $conversation = Conversation::between($job->id, $shipper->id, $carrier->id);

        $this->actingAs($carrier)
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", ['body' => 'First'])
            ->assertCreated();

        $this->actingAs($shipper)->postJson('/api/v1/notifications/read-all')->assertOk();

        $this->actingAs($carrier)
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", ['body' => 'Second'])
            ->assertCreated();

        $this->assertSame(2, Notification::where('user_id', $shipper->id)->count());
        $this->assertSame(1, Notification::where('user_id', $shipper->id)->unread()->count());
    }

    public function test_guests_are_refused(): void
    {
        $this->getJson('/api/v1/conversations')->assertUnauthorized();
    }
}
