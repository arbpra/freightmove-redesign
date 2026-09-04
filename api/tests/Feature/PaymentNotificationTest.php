<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Mail\SubscriptionConfirmed;
use App\Mail\SubscriptionPaymentReceived;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Both sides of a payment get told.
 *
 * The carrier's receipt is `SubscriptionConfirmed`; the operator's copy is
 * `SubscriptionPaymentReceived`. The second one matters most under the PayPal
 * gateway, where no human touches the transaction at all — the carrier pays,
 * the capture confirms, the subscription switches itself on, and without this
 * the first anyone here knows of a sale is the bank statement.
 */
class PaymentNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\SubscriptionPlanSeeder::class);
        Mail::fake();
        config(['freightmove.contact.payment_recipient' => 'accounts@freightmove.test']);
    }

    private function carrier(): User
    {
        $user = User::factory()->create([
            'role' => UserRole::Carrier,
            'status' => UserStatus::Active,
            'name' => 'Bruno Katsav',
            'email' => 'bruno@example.test',
        ]);
        UserProfile::factory()->create(['user_id' => $user->id, 'company_name' => 'Katsav Haulage']);

        return $user;
    }

    private function reserved(User $carrier, string $code = 'monthly'): Subscription
    {
        return Subscription::create([
            'user_id' => $carrier->id,
            'subscription_plan_id' => SubscriptionPlan::where('code', $code)->value('id'),
            'status' => 'pending',
            'starts_on' => today(),
            'ends_on' => today()->addMonth(),
        ]);
    }

    public function test_confirming_a_payment_emails_the_carrier_and_the_operator(): void
    {
        $carrier = $this->carrier();

        app(SubscriptionService::class)->confirmPayment($this->reserved($carrier), 'CAPTURE-123');

        Mail::assertSent(SubscriptionConfirmed::class, fn ($mail) => $mail->hasTo('bruno@example.test'));
        Mail::assertSent(SubscriptionPaymentReceived::class, fn ($mail) => $mail->hasTo('accounts@freightmove.test'));
    }

    /** Read on a phone, so the subject carries who and how much. */
    public function test_the_operator_subject_names_the_carrier_and_the_amount(): void
    {
        app(SubscriptionService::class)->confirmPayment($this->reserved($this->carrier()), 'CAPTURE-123');

        Mail::assertSent(SubscriptionPaymentReceived::class, function (SubscriptionPaymentReceived $mail) {
            $subject = $mail->envelope()->subject;

            return str_contains($subject, 'Katsav Haulage') && str_contains($subject, '64.99');
        });
    }

    /** The handle to quote at PayPal if the payment is ever queried or refunded. */
    public function test_the_operator_copy_carries_the_gateway_reference(): void
    {
        app(SubscriptionService::class)->confirmPayment($this->reserved($this->carrier()), 'CAPTURE-123');

        Mail::assertSent(
            SubscriptionPaymentReceived::class,
            fn (SubscriptionPaymentReceived $mail) => $mail->reference === 'CAPTURE-123',
        );
    }

    /** More than one person can be told, without a deploy. */
    public function test_several_operators_can_be_notified(): void
    {
        config(['freightmove.contact.payment_recipient' => 'accounts@freightmove.test, owner@freightmove.test']);

        app(SubscriptionService::class)->confirmPayment($this->reserved($this->carrier()), 'CAPTURE-123');

        Mail::assertSent(SubscriptionPaymentReceived::class, fn ($mail) => $mail->hasTo('accounts@freightmove.test')
            && $mail->hasTo('owner@freightmove.test'));
    }

    public function test_a_blank_recipient_sends_nothing_and_breaks_nothing(): void
    {
        config(['freightmove.contact.payment_recipient' => null]);

        $confirmed = app(SubscriptionService::class)
            ->confirmPayment($this->reserved($this->carrier()), 'CAPTURE-123');

        $this->assertSame('active', $confirmed->status, 'The payment must still complete.');
        Mail::assertNotSent(SubscriptionPaymentReceived::class);
        Mail::assertSent(SubscriptionConfirmed::class);
    }

    /** Junk in the config must not cost the carrier the receipt they are owed. */
    public function test_an_unusable_operator_address_is_skipped(): void
    {
        config(['freightmove.contact.payment_recipient' => 'not-an-address']);

        $confirmed = app(SubscriptionService::class)
            ->confirmPayment($this->reserved($this->carrier()), 'CAPTURE-123');

        $this->assertSame('active', $confirmed->status);
        Mail::assertNotSent(SubscriptionPaymentReceived::class);
        Mail::assertSent(SubscriptionConfirmed::class);
    }

    /**
     * Both gateways converge on `confirmPayment`, so an admin confirming a
     * manual payment produces the same pair as a PayPal capture.
     */
    public function test_a_manual_confirmation_notifies_both_sides_too(): void
    {
        $carrier = $this->carrier();
        $subscription = $this->reserved($carrier);

        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/subscriptions/{$subscription->id}/confirm", ['reference' => 'BANK-9912'])
            ->assertOk();

        Mail::assertSent(SubscriptionConfirmed::class);
        Mail::assertSent(SubscriptionPaymentReceived::class);
    }

}
