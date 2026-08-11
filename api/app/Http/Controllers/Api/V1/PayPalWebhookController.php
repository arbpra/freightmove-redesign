<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Services\Payments\PayPalGateway;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * PayPal's server-to-server notifications.
 *
 * This exists because the browser return leg is not reliable: the carrier can
 * close the tab, lose signal, or have the payment settle asynchronously. If the
 * only thing that activates a subscription is the user coming back, some people
 * will pay and get nothing.
 *
 * Unauthenticated by necessity — PayPal has no session — so **every** request
 * is verified against PayPal before it is acted on. An unverified webhook
 * endpoint that activates subscriptions is a way to get the paid product for
 * free by POSTing some JSON.
 */
class PayPalWebhookController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly PayPalGateway $paypal,
    ) {}

    /**
     * POST /api/v1/webhooks/paypal
     */
    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->all();

        if (! $this->paypal->verifyWebhook($payload, $this->signatureHeaders($request))) {
            Log::warning('Rejected an unverified PayPal webhook.', [
                'event' => $payload['event_type'] ?? null,
                'ip' => $request->ip(),
            ]);

            // 400 rather than 403: PayPal retries on 5xx, and there is no point
            // it retrying something that will never verify.
            return response()->json(['received' => false], 400);
        }

        $event = $payload['event_type'] ?? '';
        $resource = $payload['resource'] ?? [];

        match ($event) {
            'CHECKOUT.ORDER.APPROVED', 'PAYMENT.CAPTURE.COMPLETED' => $this->activate($resource),
            'PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.CAPTURE.REVERSED'
                => $this->recordFailure($event, $resource),
            default => null,
        };

        // Always 200 once verified. A non-2xx makes PayPal retry, and retrying
        // an event we have deliberately ignored achieves nothing.
        return response()->json(['received' => true]);
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function activate(array $resource): void
    {
        $subscription = $this->resolveSubscription($resource);

        if (! $subscription) {
            return;
        }

        // Idempotent: completeGatewayPayment returns early for anything already
        // out of `pending`, so the webhook and the browser return can both
        // arrive without double-activating or double-charging.
        $orderId = $resource['supplementary_data']['related_ids']['order_id']
            ?? $subscription->gateway_reference;

        if (! $orderId) {
            return;
        }

        $this->subscriptions->completeGatewayPayment($subscription, $orderId);
    }

    /**
     * A denial or refund. The subscription is not switched off here — a refund
     * after a period has started is a commercial conversation, not something to
     * settle automatically — but the payment record is corrected so the admin
     * queue reflects reality.
     *
     * @param  array<string, mixed>  $resource
     */
    private function recordFailure(string $event, array $resource): void
    {
        $subscription = $this->resolveSubscription($resource);

        if (! $subscription) {
            return;
        }

        SubscriptionPayment::where('user_id', $subscription->user_id)
            ->where('subscription_plan_id', $subscription->subscription_plan_id)
            ->latest()
            ->limit(1)
            ->update(['status' => str_contains($event, 'REFUND') ? 'refunded' : 'failed']);

        Log::warning('PayPal reported a payment problem.', [
            'event' => $event,
            'subscription' => $subscription->id,
        ]);
    }

    /**
     * Finds the subscription an event is about.
     *
     * `custom_id` is set when the order is created and is the reliable link;
     * the order id is the fallback for events that do not carry it.
     *
     * @param  array<string, mixed>  $resource
     */
    private function resolveSubscription(array $resource): ?Subscription
    {
        $customId = $resource['custom_id']
            ?? $resource['purchase_units'][0]['custom_id']
            ?? null;

        if ($customId && $subscription = Subscription::with('plan')->find($customId)) {
            return $subscription;
        }

        $orderId = $resource['id']
            ?? $resource['supplementary_data']['related_ids']['order_id']
            ?? null;

        if (! $orderId) {
            return null;
        }

        return Subscription::with('plan')->where('gateway_reference', $orderId)->latest()->first();
    }

    /** @return array<string, string> */
    private function signatureHeaders(Request $request): array
    {
        $wanted = [
            'paypal-auth-algo',
            'paypal-cert-url',
            'paypal-transmission-id',
            'paypal-transmission-sig',
            'paypal-transmission-time',
        ];

        return collect($wanted)
            ->mapWithKeys(fn (string $header) => [$header => (string) $request->header($header)])
            ->all();
    }
}
