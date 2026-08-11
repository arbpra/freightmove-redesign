<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Carrier;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PayPal Checkout (Orders v2) — the gateway the previous site used.
 *
 * Every PayPal call is faked, so the whole flow is covered without credentials
 * and without touching their sandbox. The cases that matter most are the ones
 * where PayPal says something *other* than "paid in full": those are where a
 * careless integration gives away the paid product.
 */
class PayPalPaymentTest extends TestCase
{
    use RefreshDatabase;

    private const ORDER_ID = '5O190127TN364715T';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\SubscriptionPlanSeeder::class);

        config([
            'freightmove.subscriptions.gateway' => 'paypal',
            'services.paypal.client_id' => 'test-client-id',
            'services.paypal.client_secret' => 'test-secret',
            'services.paypal.mode' => 'sandbox',
            'services.paypal.webhook_id' => 'WH-TEST-1',
        ]);

        // The access token is cached; a stale one from another test would mask
        // a missing credentials check.
        Cache::flush();
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

    /** @param array<string, mixed> $overrides */
    private function fakePayPal(array $overrides = []): void
    {
        Http::fake(array_merge([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'A21AA-fake', 'expires_in' => 32400]),
            '*/v2/checkout/orders' => Http::response([
                'id' => self::ORDER_ID,
                'status' => 'PAYER_ACTION_REQUIRED',
                'links' => [
                    ['rel' => 'self', 'href' => 'https://api-m.sandbox.paypal.com/v2/checkout/orders/'.self::ORDER_ID],
                    ['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token='.self::ORDER_ID],
                ],
            ]),
        ], $overrides));
    }

    /** A successful capture of the given amount. */
    private function captureResponse(string $value, string $currency = 'AUD', string $status = 'COMPLETED'): \Closure
    {
        return fn () => Http::response([
            'id' => self::ORDER_ID,
            'status' => $status,
            'purchase_units' => [[
                'payments' => ['captures' => [[
                    'id' => '3C679366HH908993F',
                    'status' => 'COMPLETED',
                    'amount' => ['currency_code' => $currency, 'value' => $value],
                ]]],
            ]],
        ]);
    }

    // -- Checkout ------------------------------------------------------------

    public function test_choosing_a_plan_returns_a_paypal_approval_url(): void
    {
        $this->fakePayPal();
        $carrier = $this->carrier();

        $response = $this->actingAs($carrier)
            ->postJson('/api/v1/carrier/subscription/checkout', ['plan' => 'monthly'])
            ->assertCreated()
            ->assertJsonPath('data.gateway', 'paypal');

        $this->assertStringContainsString('paypal.com', $response->json('data.approval_url'));
        $this->assertSame(self::ORDER_ID, $response->json('data.reference'));

        // The order id is stored so the return leg and any webhook can both
        // find this subscription.
        $this->assertSame(self::ORDER_ID, Subscription::sole()->gateway_reference);
    }

    /** The amount charged comes from the plan, never from the request. */
    public function test_the_order_is_created_for_the_plans_price(): void
    {
        $this->fakePayPal();

        $this->actingAs($this->carrier())
            ->postJson('/api/v1/carrier/subscription/checkout', [
                'plan' => 'annual',
                // A hopeful addition that must be ignored entirely.
                'amount' => 1,
            ])
            ->assertCreated();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/v2/checkout/orders')) {
                return true;
            }

            return $request['purchase_units'][0]['amount']['value'] === '699.90'
                && $request['purchase_units'][0]['amount']['currency_code'] === 'AUD';
        });
    }

    public function test_the_plan_is_still_reserved_when_paypal_is_unreachable(): void
    {
        Http::fake(['*' => Http::response(['error' => 'server_error'], 500)]);

        $this->actingAs($this->carrier())
            ->postJson('/api/v1/carrier/subscription/checkout', ['plan' => 'monthly'])
            ->assertStatus(502);

        // The reservation survives, so an outage means "pay later" rather than
        // "start again".
        $this->assertSame('pending', Subscription::sole()->status);
    }

    // -- Capture -------------------------------------------------------------

    private function reserve(User $carrier, string $plan = 'monthly'): Subscription
    {
        $this->fakePayPal();

        $this->actingAs($carrier)
            ->postJson('/api/v1/carrier/subscription/checkout', ['plan' => $plan])
            ->assertCreated();

        return Subscription::sole();
    }

    public function test_a_matching_capture_switches_the_subscription_on(): void
    {
        $carrier = $this->carrier();
        $this->reserve($carrier);

        $this->fakePayPal([
            '*/capture' => $this->captureResponse('64.99'),
        ]);

        $this->actingAs($carrier)
            ->postJson('/api/v1/carrier/subscription/capture', ['reference' => self::ORDER_ID])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertTrue($carrier->fresh()->hasActiveSubscription());
        $this->assertSame('completed', SubscriptionPayment::sole()->status);
    }

    /**
     * The one that matters. If the captured amount is taken on trust, a
     * tampered checkout buys a $699 plan for a dollar.
     */
    public function test_a_capture_for_the_wrong_amount_is_refused(): void
    {
        $carrier = $this->carrier();
        $this->reserve($carrier, 'annual');

        $this->fakePayPal([
            '*/capture' => $this->captureResponse('1.00'),
        ]);

        $this->actingAs($carrier)
            ->postJson('/api/v1/carrier/subscription/capture', ['reference' => self::ORDER_ID])
            ->assertStatus(422);

        $this->assertSame('pending', Subscription::sole()->status);
        $this->assertFalse($carrier->fresh()->hasActiveSubscription());
    }

    public function test_a_capture_in_the_wrong_currency_is_refused(): void
    {
        $carrier = $this->carrier();
        $this->reserve($carrier);

        // The right number, the wrong money.
        $this->fakePayPal([
            '*/capture' => $this->captureResponse('64.99', 'USD'),
        ]);

        $this->actingAs($carrier)
            ->postJson('/api/v1/carrier/subscription/capture', ['reference' => self::ORDER_ID])
            ->assertStatus(422);

        $this->assertSame('pending', Subscription::sole()->status);
    }

    public function test_an_order_that_did_not_complete_is_refused(): void
    {
        $carrier = $this->carrier();
        $this->reserve($carrier);

        $this->fakePayPal([
            '*/capture' => $this->captureResponse('64.99', 'AUD', 'PAYER_ACTION_REQUIRED'),
        ]);

        $this->actingAs($carrier)
            ->postJson('/api/v1/carrier/subscription/capture', ['reference' => self::ORDER_ID])
            ->assertStatus(422);

        $this->assertSame('pending', Subscription::sole()->status);
    }

    /**
     * A double-click, or the webhook racing the browser back. PayPal answers
     * 422 ORDER_ALREADY_CAPTURED, which is a success that already happened.
     */
    public function test_capturing_twice_does_not_charge_or_extend_twice(): void
    {
        $carrier = $this->carrier();
        $this->reserve($carrier);

        $this->fakePayPal(['*/capture' => $this->captureResponse('64.99')]);

        $this->actingAs($carrier)
            ->postJson('/api/v1/carrier/subscription/capture', ['reference' => self::ORDER_ID])
            ->assertOk();

        $endsOn = Subscription::sole()->ends_on->toDateString();

        $this->actingAs($carrier)
            ->postJson('/api/v1/carrier/subscription/capture', ['reference' => self::ORDER_ID])
            ->assertOk();

        $this->assertSame(1, Subscription::count());
        $this->assertSame($endsOn, Subscription::sole()->ends_on->toDateString());
        $this->assertSame(1, SubscriptionPayment::where('status', 'completed')->count());
    }

    public function test_one_carrier_cannot_capture_anothers_order(): void
    {
        $owner = $this->carrier();
        $this->reserve($owner);

        $this->fakePayPal(['*/capture' => $this->captureResponse('64.99')]);

        $this->actingAs($this->carrier())
            ->postJson('/api/v1/carrier/subscription/capture', ['reference' => self::ORDER_ID])
            ->assertNotFound();

        $this->assertSame('pending', Subscription::sole()->status);
    }

    // -- Webhooks ------------------------------------------------------------

    /** @return array<string, mixed> */
    private function webhookPayload(int $subscriptionId, string $event = 'PAYMENT.CAPTURE.COMPLETED'): array
    {
        return [
            'event_type' => $event,
            'resource' => [
                'id' => '3C679366HH908993F',
                'custom_id' => (string) $subscriptionId,
                'supplementary_data' => ['related_ids' => ['order_id' => self::ORDER_ID]],
            ],
        ];
    }

    /**
     * Without verification this endpoint hands out subscriptions to anyone who
     * can POST JSON at it.
     */
    public function test_an_unverified_webhook_is_refused_and_changes_nothing(): void
    {
        $carrier = $this->carrier();
        $subscription = $this->reserve($carrier);

        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'A21AA-fake']),
            '*/verify-webhook-signature' => Http::response(['verification_status' => 'FAILURE']),
        ]);

        $this->postJson('/api/v1/webhooks/paypal', $this->webhookPayload($subscription->id))
            ->assertStatus(400);

        $this->assertSame('pending', $subscription->fresh()->status);
    }

    public function test_a_verified_webhook_activates_the_subscription(): void
    {
        $carrier = $this->carrier();
        $subscription = $this->reserve($carrier);

        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'A21AA-fake']),
            '*/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
            '*/capture' => $this->captureResponse('64.99'),
        ]);

        $this->postJson('/api/v1/webhooks/paypal', $this->webhookPayload($subscription->id))
            ->assertOk();

        $this->assertSame('active', $subscription->fresh()->status);
    }

    /**
     * The webhook and the browser return both arrive for the same payment. Only
     * one of them may take effect.
     */
    public function test_a_webhook_after_the_browser_return_is_a_no_op(): void
    {
        $carrier = $this->carrier();
        $subscription = $this->reserve($carrier);

        $this->fakePayPal(['*/capture' => $this->captureResponse('64.99')]);

        $this->actingAs($carrier)
            ->postJson('/api/v1/carrier/subscription/capture', ['reference' => self::ORDER_ID])
            ->assertOk();

        $endsOn = $subscription->fresh()->ends_on->toDateString();

        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'A21AA-fake']),
            '*/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
            '*/capture' => $this->captureResponse('64.99'),
        ]);

        $this->postJson('/api/v1/webhooks/paypal', $this->webhookPayload($subscription->id))
            ->assertOk();

        $this->assertSame($endsOn, $subscription->fresh()->ends_on->toDateString());
        $this->assertSame(1, Subscription::count());
    }

    public function test_a_refund_marks_the_payment_without_touching_the_period(): void
    {
        $carrier = $this->carrier();
        $subscription = $this->reserve($carrier);

        $this->fakePayPal(['*/capture' => $this->captureResponse('64.99')]);
        $this->actingAs($carrier)
            ->postJson('/api/v1/carrier/subscription/capture', ['reference' => self::ORDER_ID])
            ->assertOk();

        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'A21AA-fake']),
            '*/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
        ]);

        $this->postJson(
            '/api/v1/webhooks/paypal',
            $this->webhookPayload($subscription->id, 'PAYMENT.CAPTURE.REFUNDED')
        )->assertOk();

        $this->assertSame('refunded', SubscriptionPayment::sole()->status);
        // Deliberately still running: a refund mid-period is a conversation,
        // not something to settle automatically.
        $this->assertSame('active', $subscription->fresh()->status);
    }

    public function test_a_webhook_with_no_configured_id_cannot_be_verified(): void
    {
        config(['services.paypal.webhook_id' => null]);

        $carrier = $this->carrier();
        $subscription = $this->reserve($carrier);

        $this->postJson('/api/v1/webhooks/paypal', $this->webhookPayload($subscription->id))
            ->assertStatus(400);

        $this->assertSame('pending', $subscription->fresh()->status);
    }

    // -- Configuration -------------------------------------------------------

    public function test_missing_credentials_fail_loudly_rather_than_silently(): void
    {
        config(['services.paypal.client_id' => null, 'services.paypal.client_secret' => null]);

        $this->actingAs($this->carrier())
            ->postJson('/api/v1/carrier/subscription/checkout', ['plan' => 'monthly'])
            ->assertStatus(502);
    }

    /** An unrecognised gateway falls back to manual rather than breaking. */
    public function test_an_unknown_gateway_falls_back_to_manual(): void
    {
        config(['freightmove.subscriptions.gateway' => 'bitcoin']);

        $this->actingAs($this->carrier())
            ->postJson('/api/v1/carrier/subscription/checkout', ['plan' => 'monthly'])
            ->assertCreated()
            ->assertJsonPath('data.gateway', 'manual')
            ->assertJsonPath('data.approval_url', null);
    }
}
