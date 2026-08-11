<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Models\Subscription;
use App\Support\CheckoutIntent;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * PayPal Checkout, Orders v2 — the same integration the previous site used.
 *
 * The imported payment history is PayPal: 69 completed captures in AUD, with
 * `payer_id` values in PayPal's format, so the merchant account already exists
 * and this continues that arrangement rather than replacing it.
 *
 * The flow is: create an order for the plan's price, send the carrier to
 * PayPal's approval URL, then capture when they come back. **Capture is where
 * the money moves**, and the amount captured is checked against the plan before
 * anything is switched on — see `capture()`.
 */
class PayPalGateway implements PaymentGateway
{
    /** Access tokens last 9 hours; cached well short of that. */
    private const TOKEN_CACHE_KEY = 'paypal.access_token';

    private const TOKEN_TTL_SECONDS = 28000;

    public function name(): string
    {
        return 'paypal';
    }

    public function createCheckout(Subscription $subscription): CheckoutIntent
    {
        $plan = $subscription->plan;

        if (! $plan) {
            throw new RuntimeException('That subscription has no plan to charge for.');
        }

        $response = $this->client()->post('/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                // Ties the PayPal order back to our subscription without
                // trusting anything the browser sends back later.
                'custom_id' => (string) $subscription->id,
                'description' => "FreightMove {$plan->name}",
                'amount' => [
                    'currency_code' => $plan->currency ?: 'AUD',
                    'value' => number_format((float) $plan->price, 2, '.', ''),
                ],
            ]],
            'payment_source' => [
                'paypal' => [
                    'experience_context' => [
                        'brand_name' => 'FreightMove',
                        'user_action' => 'PAY_NOW',
                        'return_url' => $this->frontendUrl('/carrier/subscription/return'),
                        'cancel_url' => $this->frontendUrl('/carrier/subscription?cancelled=1'),
                    ],
                ],
            ],
        ]);

        if (! $response->successful()) {
            $this->fail('Could not start the PayPal checkout.', $response->json());
        }

        $order = $response->json();
        $approvalUrl = collect($order['links'] ?? [])
            ->firstWhere('rel', 'payer-action')['href']
            ?? collect($order['links'] ?? [])->firstWhere('rel', 'approve')['href']
            ?? null;

        if (! $approvalUrl) {
            $this->fail('PayPal did not return an approval link.', $order);
        }

        return CheckoutIntent::redirect($this->name(), $approvalUrl, $order['id']);
    }

    /**
     * Captures an approved order.
     *
     * Three things are verified before this reports success, because a capture
     * that is merely "not an error" is not the same as being paid:
     *
     * 1. the order actually completed;
     * 2. the amount captured equals the plan price **to the cent**;
     * 3. the currency matches.
     *
     * The amount is read from PayPal's response, never from the client. Trusting
     * a browser-supplied total is the classic way to sell a $699 plan for a
     * dollar. A mismatch is refused and logged rather than quietly accepted —
     * the money may well have moved, and that needs a human, not an automatic
     * activation for the wrong sum.
     */
    public function capture(Subscription $subscription, string $reference): ?string
    {
        $plan = $subscription->plan;
        $response = $this->client()->post("/v2/checkout/orders/{$reference}/capture");

        // PayPal answers 422 ORDER_ALREADY_CAPTURED when a capture is retried —
        // a double-click, or a webhook racing the browser return. That is a
        // success that already happened, not a failure.
        if ($response->status() === 422 && $this->isAlreadyCaptured($response->json())) {
            return $this->existingCaptureId($reference) ?? $reference;
        }

        if (! $response->successful()) {
            $this->fail('PayPal refused the capture.', $response->json());
        }

        $order = $response->json();

        if (($order['status'] ?? null) !== 'COMPLETED') {
            Log::warning('PayPal order was not completed.', [
                'subscription' => $subscription->id,
                'order' => $reference,
                'status' => $order['status'] ?? null,
            ]);

            return null;
        }

        $capture = $order['purchase_units'][0]['payments']['captures'][0] ?? null;
        $paid = $capture['amount'] ?? null;

        $expected = number_format((float) $plan->price, 2, '.', '');
        $expectedCurrency = $plan->currency ?: 'AUD';

        if (($paid['value'] ?? null) !== $expected || ($paid['currency_code'] ?? null) !== $expectedCurrency) {
            Log::error('PayPal captured an amount that does not match the plan.', [
                'subscription' => $subscription->id,
                'order' => $reference,
                'expected' => "{$expected} {$expectedCurrency}",
                'captured' => ($paid['value'] ?? '?').' '.($paid['currency_code'] ?? '?'),
            ]);

            return null;
        }

        return $capture['id'] ?? $reference;
    }

    /**
     * Confirms a webhook really came from PayPal.
     *
     * Verified server-side against PayPal's own endpoint rather than by
     * checking a shared secret: an unverified webhook is an open endpoint that
     * hands out subscriptions to anyone who can guess its URL.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function verifyWebhook(array $payload, array $headers): bool
    {
        $webhookId = config('services.paypal.webhook_id');

        if (! $webhookId) {
            Log::error('A PayPal webhook arrived but no webhook id is configured, so it cannot be verified.');

            return false;
        }

        $response = $this->client()->post('/v1/notifications/verify-webhook-signature', [
            'auth_algo' => $headers['paypal-auth-algo'] ?? '',
            'cert_url' => $headers['paypal-cert-url'] ?? '',
            'transmission_id' => $headers['paypal-transmission-id'] ?? '',
            'transmission_sig' => $headers['paypal-transmission-sig'] ?? '',
            'transmission_time' => $headers['paypal-transmission-time'] ?? '',
            'webhook_id' => $webhookId,
            'webhook_event' => $payload,
        ]);

        return $response->successful()
            && ($response->json('verification_status') === 'SUCCESS');
    }

    // -- Plumbing ------------------------------------------------------------

    private function client(): PendingRequest
    {
        return Http::withToken($this->accessToken())
            ->baseUrl($this->baseUrl())
            ->acceptJson()
            ->timeout(20)
            // One retry on a transient failure; a payment call is worth
            // retrying once, and not worth hammering.
            ->retry(2, 250, throw: false);
    }

    /**
     * OAuth token, cached.
     *
     * Fetching one per API call would triple the round trips on every checkout
     * for no benefit — the token is valid for hours.
     */
    private function accessToken(): string
    {
        return Cache::remember(self::TOKEN_CACHE_KEY, self::TOKEN_TTL_SECONDS, function (): string {
            $id = config('services.paypal.client_id');
            $secret = config('services.paypal.client_secret');

            if (! $id || ! $secret) {
                throw new RuntimeException(
                    'PayPal is selected as the payment gateway but PAYPAL_CLIENT_ID / '
                    .'PAYPAL_CLIENT_SECRET are not set.'
                );
            }

            $response = Http::asForm()
                ->withBasicAuth($id, $secret)
                ->acceptJson()
                ->timeout(20)
                ->post($this->baseUrl().'/v1/oauth2/token', ['grant_type' => 'client_credentials']);

            if (! $response->successful()) {
                $this->fail('PayPal rejected the API credentials.', $response->json());
            }

            return $response->json('access_token');
        });
    }

    private function baseUrl(): string
    {
        return config('services.paypal.mode') === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    private function frontendUrl(string $path): string
    {
        return rtrim((string) config('freightmove.frontend_url'), '/').$path;
    }

    /** @param array<string, mixed>|null $body */
    private function isAlreadyCaptured(?array $body): bool
    {
        foreach ($body['details'] ?? [] as $detail) {
            if (($detail['issue'] ?? null) === 'ORDER_ALREADY_CAPTURED') {
                return true;
            }
        }

        return false;
    }

    /** Re-reads an order to recover the capture id after a duplicate capture. */
    private function existingCaptureId(string $orderId): ?string
    {
        $response = $this->client()->get("/v2/checkout/orders/{$orderId}");

        return $response->successful()
            ? ($response->json('purchase_units.0.payments.captures.0.id') ?? null)
            : null;
    }

    /** @param array<string, mixed>|null $body */
    private function fail(string $message, ?array $body): never
    {
        Log::error($message, ['paypal' => $body]);

        throw new RuntimeException($message);
    }
}
