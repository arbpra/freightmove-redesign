<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\QuoteStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Mail\LoadPosted;
use App\Mail\NotificationMail;
use App\Mail\SubscriptionConfirmed;
use App\Models\Carrier;
use App\Models\Conversation;
use App\Models\FreightJob;
use App\Models\JobQuote;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Transactional email.
 *
 * The previous site sent four emails — load posted, quote received, password
 * reset, contact form. Everything else here happened only in the notification
 * bell, which is invisible to someone who is not signed in, so a carrier could
 * win a job and never find out.
 *
 * Most of what is pinned below is restraint: which events email, which
 * deliberately do not, and that a mail failure never breaks the thing it was
 * reporting on.
 */
class TransactionalEmailTest extends TestCase
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

    // -- The events that email ----------------------------------------------

    public function test_a_shipper_is_emailed_when_a_carrier_quotes(): void
    {
        Mail::fake();

        $shipper = $this->shipper();
        $carrier = $this->carrier(['company_name' => 'Whitfield Haulage']);
        $job = FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Published,
        ]);

        $this->actingAs($carrier)
            ->postJson("/api/v1/carrier/board/{$job->id}/quotes", ['amount' => 1450])
            ->assertCreated();

        Mail::assertSent(
            NotificationMail::class,
            fn (NotificationMail $mail) => $mail->hasTo($shipper->email)
                && str_contains($mail->notification->title, $job->title),
        );
    }

    public function test_the_winning_carrier_is_emailed(): void
    {
        Mail::fake();

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
            ->postJson("/api/v1/shipper/quotes/{$quote->id}/accept")
            ->assertOk();

        Mail::assertSent(
            NotificationMail::class,
            fn (NotificationMail $mail) => $mail->hasTo($carrier->email),
        );
    }

    public function test_a_shipper_gets_a_receipt_when_their_load_goes_live(): void
    {
        Mail::fake();
        $shipper = $this->shipper();

        $this->actingAs($shipper)
            ->postJson('/api/v1/shipper/jobs', [
                'title' => 'Two pallets to Dubbo',
                'pickup_location' => 'Sydney, NSW',
                'delivery_location' => 'Dubbo, NSW',
                'status' => 'published',
            ])
            ->assertCreated();

        Mail::assertSent(LoadPosted::class, fn ($mail) => $mail->hasTo($shipper->email));
    }

    /** A draft is not on the board, so there is nothing to confirm. */
    public function test_a_draft_sends_no_receipt(): void
    {
        Mail::fake();

        $this->actingAs($this->shipper())
            ->postJson('/api/v1/shipper/jobs', [
                'title' => 'Not ready yet',
                'pickup_location' => 'Sydney, NSW',
                'delivery_location' => 'Dubbo, NSW',
            ])
            ->assertCreated();

        Mail::assertNothingSent();
    }

    public function test_publishing_a_draft_sends_the_receipt(): void
    {
        Mail::fake();

        $shipper = $this->shipper();
        $job = FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Draft,
        ]);

        $this->actingAs($shipper)
            ->postJson("/api/v1/shipper/jobs/{$job->id}/publish")
            ->assertOk();

        Mail::assertSent(LoadPosted::class);
    }

    public function test_a_paid_subscription_sends_a_receipt(): void
    {
        Mail::fake();
        $this->seed(\Database\Seeders\SubscriptionPlanSeeder::class);

        $carrier = $this->carrier();
        $admin = User::factory()->create(['role' => UserRole::Admin, 'status' => UserStatus::Active]);

        $this->actingAs($carrier)
            ->postJson('/api/v1/carrier/subscription/checkout', ['plan' => 'annual'])
            ->assertCreated();

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/subscriptions/'.Subscription::sole()->id.'/confirm', [
                'reference' => 'BANK-1',
            ])
            ->assertOk();

        Mail::assertSent(
            SubscriptionConfirmed::class,
            fn (SubscriptionConfirmed $mail) => $mail->hasTo($carrier->email),
        );
    }

    // -- The events that deliberately do not --------------------------------

    /**
     * A losing carrier has nothing to act on, and a marketplace that emails
     * every event trains people to filter the sender — at which point the one
     * message that mattered goes unread too.
     */
    public function test_a_declined_quote_does_not_email(): void
    {
        Mail::fake();

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

        Mail::assertNotSent(NotificationMail::class);
    }

    /** The bell already covers it; the inbox does not need to. */
    public function test_completing_a_job_does_not_email(): void
    {
        Mail::fake();

        $shipper = $this->shipper();
        $carrier = $this->carrier();
        $job = FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Accepted,
        ]);
        $quote = JobQuote::factory()->create([
            'job_id' => $job->id,
            'carrier_id' => $carrier->id,
            'status' => QuoteStatus::Accepted,
        ]);
        \App\Models\JobAcceptance::create([
            'job_id' => $job->id,
            'quote_id' => $quote->id,
            'carrier_id' => $carrier->id,
            'shipper_id' => $shipper->id,
            'accepted_at' => now(),
        ]);

        $this->actingAs($shipper)
            ->postJson("/api/v1/shipper/jobs/{$job->id}/complete")
            ->assertOk();

        Mail::assertNotSent(NotificationMail::class);
    }

    /**
     * A chat is a burst of short messages. One email per message would be
     * unusable, so the once-per-conversation rule governs both channels.
     */
    public function test_a_burst_of_messages_sends_one_email(): void
    {
        Mail::fake();

        $shipper = $this->shipper();
        $carrier = $this->carrier();
        $job = FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Quoted,
        ]);
        JobQuote::factory()->create([
            'job_id' => $job->id,
            'carrier_id' => $carrier->id,
            'status' => QuoteStatus::Pending,
        ]);
        $conversation = Conversation::between($job->id, $shipper->id, $carrier->id);

        foreach (['One', 'Two', 'Three'] as $line) {
            $this->actingAs($carrier)
                ->postJson("/api/v1/conversations/{$conversation->id}/messages", ['body' => $line])
                ->assertCreated();
        }

        Mail::assertSent(NotificationMail::class, 1);
    }

    // -- Failure -------------------------------------------------------------

    /**
     * The quote is the transaction; telling people about it is a consequence.
     * A dead mail server must not turn a successful quote into a 500.
     */
    public function test_a_mail_failure_does_not_break_the_action(): void
    {
        Mail::shouldReceive('to->send')->andThrow(new \RuntimeException('SMTP is down'));

        $shipper = $this->shipper();
        $carrier = $this->carrier();
        $job = FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Published,
        ]);

        $this->actingAs($carrier)
            ->postJson("/api/v1/carrier/board/{$job->id}/quotes", ['amount' => 900])
            ->assertCreated();

        // The notification still exists — the durable record is unaffected.
        $this->assertSame(1, \App\Models\Notification::where('user_id', $shipper->id)->count());
    }

    /** The allow-list is config, so it can be changed without touching code. */
    public function test_the_allow_list_governs_what_sends(): void
    {
        Mail::fake();
        config(['freightmove.mail.notify' => []]);

        $shipper = $this->shipper();
        $carrier = $this->carrier();
        $job = FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Published,
        ]);

        $this->actingAs($carrier)
            ->postJson("/api/v1/carrier/board/{$job->id}/quotes", ['amount' => 900])
            ->assertCreated();

        Mail::assertNotSent(NotificationMail::class);
    }

    // -- Content -------------------------------------------------------------

    /** An email is read outside the app, so its links must be absolute. */
    public function test_emails_link_back_with_an_absolute_url(): void
    {
        Mail::fake();
        config(['freightmove.frontend_url' => 'https://new.freightmove.au']);

        $shipper = $this->shipper();
        $carrier = $this->carrier();
        $job = FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Published,
        ]);

        $this->actingAs($carrier)
            ->postJson("/api/v1/carrier/board/{$job->id}/quotes", ['amount' => 900])
            ->assertCreated();

        Mail::assertSent(
            NotificationMail::class,
            fn (NotificationMail $mail) => str_starts_with((string) $mail->url, 'https://new.freightmove.au/')
                && str_contains((string) $mail->url, "/shipper/jobs/{$job->id}/quotes"),
        );
    }

    public function test_the_notification_email_renders(): void
    {
        $shipper = $this->shipper();
        $notification = \App\Models\Notification::factory()->create([
            'user_id' => $shipper->id,
            'title' => 'New quote on Two pallets to Dubbo',
            'body' => 'Whitfield Haulage quoted $1,450.00.',
        ]);

        $rendered = (new NotificationMail($notification, 'https://example.test/x', 'Compare quotes'))
            ->render();

        $this->assertStringContainsString('New quote on Two pallets to Dubbo', $rendered);
        $this->assertStringContainsString('Whitfield Haulage', $rendered);
        $this->assertStringContainsString('Compare quotes', $rendered);
        $this->assertStringContainsString('FREIGHT', $rendered);
    }
}
