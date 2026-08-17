<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Mail\SubscriptionConfirmed;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\CheckoutIntent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

/**
 * Starting, extending and ending carrier subscriptions.
 *
 * The subscription is the paid product (docs/10-domain-rules.md R3), so the
 * rules here decide who can quote once the gate is switched on.
 */
class SubscriptionService
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /** One trial per account, ever. */
    public function hasUsedTrial(User $user): bool
    {
        return Subscription::where('user_id', $user->id)
            ->whereHas('plan', fn ($q) => $q->where('is_trial', true))
            ->exists();
    }

    /** Whether the promotional window is still open. */
    public function trialOfferIsOpen(): bool
    {
        $closes = config('freightmove.subscriptions.trial_offer_ends');

        return $closes === null || today()->lte(Carbon::parse($closes));
    }

    /**
     * @return array{eligible: bool, reason: string|null}
     */
    public function trialEligibility(User $user): array
    {
        if (! $user->isCarrier()) {
            return ['eligible' => false, 'reason' => 'The free trial is for carriers.'];
        }

        if (! $this->trialOfferIsOpen()) {
            return ['eligible' => false, 'reason' => 'The free trial offer has closed.'];
        }

        if ($this->hasUsedTrial($user)) {
            return ['eligible' => false, 'reason' => 'You have already used your free trial.'];
        }

        if ($this->current($user)) {
            return ['eligible' => false, 'reason' => 'You already have an active subscription.'];
        }

        return ['eligible' => true, 'reason' => null];
    }

    /** The subscription currently entitling this user to quote, if any. */
    public function current(User $user): ?Subscription
    {
        return Subscription::with('plan')
            ->where('user_id', $user->id)
            ->current()
            ->orderByDesc('ends_on')
            ->first();
    }

    /**
     * Starts the free trial.
     *
     * The end date is **two months from today**, which is what the pricing page
     * promises. The previous platform set it to the promotion's closing date for
     * everyone instead, so a carrier who signed up in July 2026 got a trial that
     * had expired in March — eleven of ninety legacy periods end before they
     * begin. That is not reproduced.
     */
    public function startTrial(User $user): Subscription
    {
        $eligibility = $this->trialEligibility($user);

        if (! $eligibility['eligible']) {
            throw new RuntimeException($eligibility['reason'] ?? 'Not eligible for a trial.');
        }

        $plan = SubscriptionPlan::where('code', 'trial')->firstOrFail();

        return $this->open($user, $plan, 'active', 'Free');
    }

    /**
     * Records the intent to buy a paid plan.
     *
     * Created **pending**, and it entitles the carrier to nothing until the
     * money is confirmed. Under the manual gateway that confirmation is an admin
     * action; a real gateway would call `confirmPayment` from its webhook.
     */
    public function beginCheckout(User $user, SubscriptionPlan $plan): Subscription
    {
        if ($plan->is_trial) {
            throw new RuntimeException('Use the trial flow for the free plan.');
        }

        return DB::transaction(function () use ($user, $plan) {
            $subscription = $this->open($user, $plan, 'pending');

            SubscriptionPayment::create([
                'user_id' => $user->id,
                'subscription_plan_id' => $plan->id,
                'gateway' => $this->gateway->name(),
                'gateway_reference' => null,
                'payer_name' => $user->name,
                'payer_email' => $user->email,
                // Always the plan's price. Nothing a client sends is used to
                // decide what someone owes.
                'amount' => $plan->price,
                'currency' => $plan->currency ?? 'AUD',
                'status' => 'pending',
                'paid_at' => null,
            ]);

            return $subscription;
        });
    }

    /**
     * Starts payment for a reserved subscription and says where to go next.
     *
     * Separate from `beginCheckout` so the record exists before anyone talks to
     * a payment provider: if PayPal is unreachable, the carrier still has a
     * reserved plan an admin can settle, rather than nothing at all.
     */
    public function startPayment(Subscription $subscription): CheckoutIntent
    {
        $intent = $this->gateway->createCheckout($subscription);

        if ($intent->reference) {
            // Recorded now so the return leg and any webhook can both find the
            // subscription from the gateway's own id.
            $subscription->forceFill(['gateway_reference' => $intent->reference])->save();

            SubscriptionPayment::where('user_id', $subscription->user_id)
                ->where('subscription_plan_id', $subscription->subscription_plan_id)
                ->where('status', 'pending')
                ->latest()
                ->limit(1)
                ->update(['gateway_reference' => $intent->reference]);
        }

        return $intent;
    }

    /**
     * Completes a gateway payment and switches the subscription on.
     *
     * Returns false when the gateway did not confirm the money — including when
     * it captured an amount that does not match the plan. The subscription is
     * left pending in that case, which is the safe direction: an unpaid
     * subscription someone has to chase is recoverable, an unpaid subscription
     * that granted access is not.
     */
    public function completeGatewayPayment(Subscription $subscription, string $reference): bool
    {
        // Already done — a double-click, or a webhook racing the browser back.
        if ($subscription->status !== 'pending') {
            return $subscription->isCurrent();
        }

        $captured = $this->gateway->capture($subscription, $reference);

        if (! $captured) {
            return false;
        }

        $this->confirmPayment($subscription, $captured);

        return true;
    }

    /**
     * Confirms the money arrived and switches the subscription on.
     *
     * Dates run from **confirmation**, not from when the carrier clicked
     * subscribe: a period that starts while it is still unpaid quietly sells
     * them less than the plan says.
     */
    public function confirmPayment(Subscription $subscription, ?string $reference = null): Subscription
    {
        $confirmed = DB::transaction(function () use ($subscription, $reference) {
            $plan = $subscription->plan;

            // Not simply `today()`. Two rules meet here and both must hold: a
            // period must not start while it is unpaid, *and* renewing early
            // must not swallow time the carrier already holds. Taking today
            // alone discarded the stacking worked out at checkout — a carrier
            // who bought a quarter while two months of trial were left ended up
            // with three months total instead of five.
            $start = $this->nextStartFor($subscription->user, $subscription->id);

            $subscription->forceFill([
                'status' => 'active',
                'starts_on' => $start,
                'ends_on' => $start->copy()->addMonths($plan?->interval_months ?? 1),
                // The subscription keeps the **checkout** reference — PayPal's
                // order id — because that is what the return leg and any late
                // webhook look it up by. The capture id is a different handle
                // and belongs on the payment row below. Overwriting one with
                // the other broke retries: a second capture attempt could no
                // longer find the subscription it belonged to.
                'gateway_reference' => $subscription->gateway_reference ?? $reference,
            ])->save();

            SubscriptionPayment::where('user_id', $subscription->user_id)
                ->where('subscription_plan_id', $subscription->subscription_plan_id)
                ->where('status', 'pending')
                ->latest()
                ->limit(1)
                ->update([
                    'status' => 'completed',
                    'paid_at' => now(),
                    'gateway_reference' => $reference,
                ]);

            return $subscription->fresh('plan');
        });

        $this->emailReceipt($confirmed);

        return $confirmed;
    }

    /**
     * Emails the carrier a receipt.
     *
     * Someone who has just paid $699.90 should get something in writing without
     * having to sign in and look — and if they later query the charge, this is
     * what they will search their inbox for. Guarded, because a mail failure
     * must not undo a payment that has already been taken.
     */
    private function emailReceipt(Subscription $subscription): void
    {
        $email = $subscription->user?->email;

        if (! $email) {
            return;
        }

        try {
            $mail = new SubscriptionConfirmed($subscription);

            if (config('freightmove.mail.queue')) {
                Mail::to($email)->queue($mail);
            } else {
                Mail::to($email)->send($mail);
            }
        } catch (Throwable $e) {
            Log::error('Could not send the subscription receipt.', [
                'subscription' => $subscription->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function cancel(Subscription $subscription): Subscription
    {
        // The period already paid for is honoured — `ends_on` is left alone, so
        // cancelling means "do not renew", not "refund me by locking me out".
        $subscription->forceFill(['status' => 'cancelled'])->save();

        return $subscription;
    }

    /**
     * The day a new period should begin: the day after any period already
     * running, or today if there is none.
     *
     * One implementation, used both when a plan is chosen and when its payment
     * is confirmed, because they have to agree — the two dated a subscription
     * differently and the difference came out of the carrier's pocket.
     *
     * `$excluding` is the subscription being confirmed, which must not be
     * treated as its own predecessor.
     */
    private function nextStartFor(User $user, ?int $excluding = null): Carbon
    {
        $latestEnd = Subscription::where('user_id', $user->id)
            ->when($excluding, fn ($q) => $q->whereKeyNot($excluding))
            ->current()
            ->max('ends_on');

        $latestEnd = $latestEnd ? Carbon::parse($latestEnd) : null;

        return $latestEnd?->isFuture() ? $latestEnd->copy()->addDay() : today();
    }

    /**
     * Opens a period, stacking on the end of one already running so a carrier
     * who renews early does not lose the days they have paid for.
     */
    private function open(
        User $user,
        SubscriptionPlan $plan,
        string $status,
        ?string $reference = null,
    ): Subscription {
        $start = $this->nextStartFor($user);

        return Subscription::create([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'status' => $status,
            'starts_on' => $start,
            'ends_on' => $start->copy()->addMonths($plan->interval_months),
            'gateway_reference' => $reference,
        ]);
    }
}
