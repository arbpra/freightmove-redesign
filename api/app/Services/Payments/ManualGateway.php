<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Models\Subscription;
use App\Support\CheckoutIntent;

/**
 * No gateway: the carrier pays by some arrangement off the platform — bank
 * transfer, invoice, a card over the phone — and an admin confirms it from the
 * payment queue.
 *
 * The default, and a real fallback rather than a stub: it is how a small
 * operator can take money on day one without a merchant integration, and it
 * stays useful afterwards for the payment that arrives some other way.
 */
class ManualGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'manual';
    }

    public function createCheckout(Subscription $subscription): CheckoutIntent
    {
        return CheckoutIntent::offline(
            $this->name(),
            config('freightmove.subscriptions.payment_instructions'),
        );
    }

    /**
     * There is nothing to capture — an admin confirming the payment *is* the
     * capture, and it happens through SubscriptionService directly.
     */
    public function capture(Subscription $subscription, string $reference): ?string
    {
        return null;
    }
}
