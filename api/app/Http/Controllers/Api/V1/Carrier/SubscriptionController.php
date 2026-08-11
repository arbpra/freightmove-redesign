<?php

namespace App\Http\Controllers\Api\V1\Carrier;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\SubscriptionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * A carrier's own subscription.
 *
 * Buying is two steps on purpose: `checkout` records the intent as a **pending**
 * period that entitles the carrier to nothing, and the period only switches on
 * once the money is confirmed. Under the manual gateway an admin does that;
 * a real gateway would do it from a webhook, through the same service method.
 */
class SubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /**
     * GET /api/v1/carrier/subscription
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $current = $this->subscriptions->current($user);
        $eligibility = $this->subscriptions->trialEligibility($user);

        $pending = Subscription::with('plan')
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->latest()
            ->first();

        return ApiResponse::success([
            'current' => $current ? [
                'id' => $current->id,
                'plan' => $current->plan?->name,
                'plan_code' => $current->plan?->code,
                'is_trial' => (bool) $current->plan?->is_trial,
                'starts_on' => $current->starts_on?->toDateString(),
                'ends_on' => $current->ends_on?->toDateString(),
                'days_remaining' => $current->ends_on
                    ? max(0, today()->diffInDays($current->ends_on, false))
                    : null,
            ] : null,
            'pending' => $pending ? [
                'id' => $pending->id,
                'plan' => $pending->plan?->name,
                'amount' => (float) ($pending->plan?->price ?? 0),
            ] : null,
            'trial' => $eligibility,
            'gateway' => config('freightmove.subscriptions.gateway'),
            'payment_instructions' => config('freightmove.subscriptions.payment_instructions'),
            // Whether a subscription is currently required at all. False today —
            // see config/freightmove.php.
            'required_to_quote' => (bool) config('freightmove.quoting.require_subscription'),
            'history' => Subscription::with('plan')
                ->where('user_id', $user->id)
                ->orderByDesc('starts_on')
                ->limit(20)
                ->get()
                ->map(fn (Subscription $s) => [
                    'plan' => $s->plan?->name,
                    'status' => $s->status,
                    'starts_on' => $s->starts_on?->toDateString(),
                    'ends_on' => $s->ends_on?->toDateString(),
                ])->all(),
        ]);
    }

    /**
     * POST /api/v1/carrier/subscription/trial
     */
    public function startTrial(Request $request): JsonResponse
    {
        try {
            $subscription = $this->subscriptions->startTrial($request->user());
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), status: 422);
        }

        return ApiResponse::success([
            'ends_on' => $subscription->ends_on?->toDateString(),
        ], 'Your free trial is running. It ends on '
            .$subscription->ends_on?->format('j F Y').'.', 201);
    }

    /**
     * POST /api/v1/carrier/subscription/checkout
     */
    public function checkout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan' => [
                'required',
                Rule::exists('subscription_plans', 'code')->where('is_active', true),
            ],
        ]);

        $plan = SubscriptionPlan::where('code', $validated['plan'])->firstOrFail();

        try {
            $subscription = $this->subscriptions->beginCheckout($request->user(), $plan);
            $intent = $this->subscriptions->startPayment($subscription);
        } catch (RuntimeException $e) {
            // The reservation survives even if the gateway call failed, so an
            // outage at PayPal means "pay later", not "start again".
            return ApiResponse::error(
                'Your plan is reserved, but we could not reach the payment provider. '
                    .'Please try again shortly, or we will be in touch.',
                status: 502,
            );
        }

        return ApiResponse::success([
            'subscription_id' => $subscription->id,
            'plan' => $plan->name,
            'amount' => (float) $plan->price,
            'currency' => $plan->currency ?? 'AUD',
            ...$intent->toArray(),
        ], $intent->approvalUrl
            ? 'Redirecting you to PayPal to complete payment.'
            : ($intent->instructions
                ? 'Your subscription is reserved. Follow the payment instructions and we will switch it on.'
                : 'Your subscription is reserved. Our team will be in touch to arrange payment.'), 201);
    }

    /**
     * POST /api/v1/carrier/subscription/capture
     *
     * The return leg from a redirect gateway. The browser tells us *which*
     * order came back; whether it was actually paid, and for how much, is
     * settled with the gateway directly — never taken from this request.
     */
    public function capture(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reference' => ['required', 'string', 'max:100'],
        ]);

        $subscription = Subscription::with('plan')
            ->where('user_id', $request->user()->id)
            ->where('gateway_reference', $validated['reference'])
            ->latest()
            ->first();

        // Scoped to the caller, so one carrier cannot capture another's order
        // by quoting its id.
        if (! $subscription) {
            return ApiResponse::error('We could not find that payment against your account.', status: 404);
        }

        try {
            $paid = $this->subscriptions->completeGatewayPayment($subscription, $validated['reference']);
        } catch (RuntimeException $e) {
            return ApiResponse::error(
                'We could not confirm that payment with PayPal. If money has left your '
                    .'account, contact us and we will sort it out.',
                status: 502,
            );
        }

        if (! $paid) {
            return ApiResponse::error(
                'That payment has not completed. Your plan is still reserved.',
                status: 422,
            );
        }

        $subscription->refresh();

        return ApiResponse::success([
            'status' => $subscription->status,
            'starts_on' => $subscription->starts_on?->toDateString(),
            'ends_on' => $subscription->ends_on?->toDateString(),
        ], 'Payment received — your subscription is running.');
    }

    /**
     * POST /api/v1/carrier/subscription/{subscription}/cancel
     */
    public function cancel(Request $request, Subscription $subscription): JsonResponse
    {
        abort_unless($subscription->user_id === $request->user()->id, 404);

        $this->subscriptions->cancel($subscription);

        return ApiResponse::success(
            null,
            // Said explicitly, because the opposite assumption is the common one.
            'Cancelled. You keep access until '
                .($subscription->ends_on?->format('j F Y') ?? 'the end of the current period').'.',
        );
    }
}
