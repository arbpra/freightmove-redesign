<?php

namespace Tests\Feature;

use App\Mail\ContactEnquiry;
use App\Models\ContactMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The public contact form.
 *
 * Unauthenticated and it sends email, so most of what is pinned here is about
 * abuse and about not losing an enquiry when mail fails.
 */
class ContactTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> */
    private function enquiry(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Dana Whitfield',
            'email' => 'dana@example.com',
            'phone' => '0400 000 000',
            'role' => 'shipper',
            'subject' => 'Quote or pricing',
            'message' => 'I need two pallets moved from Dubbo to Newcastle next week.',
        ], $overrides);
    }

    public function test_an_enquiry_is_stored_and_emailed(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/contact', $this->enquiry())
            ->assertCreated()
            ->assertJsonPath('success', true);

        $stored = ContactMessage::sole();
        $this->assertSame('dana@example.com', $stored->email);
        $this->assertNotNull($stored->notified_at);

        Mail::assertSent(ContactEnquiry::class);
    }

    public function test_the_senders_address_is_the_reply_to_not_the_from(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/contact', $this->enquiry())->assertCreated();

        // Sending as the customer would fail SPF and DMARC; staff need only to
        // be able to hit reply.
        Mail::assertSent(
            ContactEnquiry::class,
            fn (ContactEnquiry $mail) => $mail->hasReplyTo('dana@example.com'),
        );
    }

    public function test_an_enquiry_from_a_signed_in_user_records_the_account(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/contact', $this->enquiry())
            ->assertCreated();

        $this->assertSame($user->id, ContactMessage::sole()->user_id);
    }

    /**
     * The enquiry is saved before the email is attempted, so a mail outage
     * must not tell a customer their message did not arrive.
     */
    public function test_a_failing_mailer_does_not_lose_the_enquiry(): void
    {
        Mail::shouldReceive('to->send')->andThrow(new \RuntimeException('SMTP is down'));

        $this->postJson('/api/v1/contact', $this->enquiry())
            ->assertCreated()
            ->assertJsonPath('success', true);

        $stored = ContactMessage::sole();
        $this->assertNotNull($stored, 'The enquiry must survive a mail failure.');
        $this->assertNull($stored->notified_at, 'An unsent enquiry must stay findable.');
    }

    public function test_a_missing_recipient_still_stores_the_enquiry(): void
    {
        config(['freightmove.contact.recipient' => null]);
        Mail::fake();

        $this->postJson('/api/v1/contact', $this->enquiry())->assertCreated();

        $this->assertNull(ContactMessage::sole()->notified_at);
        Mail::assertNothingSent();
    }

    // -- Abuse ---------------------------------------------------------------

    public function test_a_filled_honeypot_is_discarded_without_saying_so(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/contact', $this->enquiry([
            'company_website' => 'http://spam.example',
        ]))->assertCreated();

        $this->assertSame(0, ContactMessage::count());
        Mail::assertNothingSent();
    }

    public function test_submissions_are_rate_limited(): void
    {
        Mail::fake();

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/contact', $this->enquiry([
                'email' => "sender{$i}@example.com",
            ]))->assertCreated();
        }

        // Same IP, different addresses — the IP limit still applies.
        for ($i = 3; $i < 5; $i++) {
            $this->postJson('/api/v1/contact', $this->enquiry([
                'email' => "sender{$i}@example.com",
            ]))->assertCreated();
        }

        $this->postJson('/api/v1/contact', $this->enquiry(['email' => 'sender9@example.com']))
            ->assertStatus(429);
    }

    // -- Validation ----------------------------------------------------------

    public function test_the_essential_fields_are_required(): void
    {
        $this->postJson('/api/v1/contact', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'message']);
    }

    public function test_a_message_too_short_to_answer_is_refused(): void
    {
        $this->postJson('/api/v1/contact', $this->enquiry(['message' => 'hi']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['message']);
    }

    public function test_an_invalid_email_is_refused(): void
    {
        $this->postJson('/api/v1/contact', $this->enquiry(['email' => 'not-an-address']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_an_oversized_message_is_refused(): void
    {
        $this->postJson('/api/v1/contact', $this->enquiry(['message' => str_repeat('a', 2001)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['message']);
    }
}
