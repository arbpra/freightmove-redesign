<?php

namespace App\Contracts;

use App\Models\Subscription;
use App\Support\CheckoutIntent;

/**
 * How a carrier pays for a subscription.
 *
 * Two implementations exist: `ManualGateway`, where an admin confirms money
 * arrived by some other route, and `PayPalGateway`, which is what the previous
 * site used and what the imported payment history came from.
 *
 * The contract is deliberately narrow. Everything about *what* a subscription
 * is worth, when it starts and what it entitles someone to lives in
 * SubscriptionService; a gateway only takes money and says whether it arrived.
 */
interface PaymentGateway
{
    /** Identifier stored on `subscription_payments.gateway`. */
    public function name(): string;

    /**
     * Begins payment for a reserved subscription.
     *
     * Returns where to send the carrier next — an approval URL for a redirect
     * gateway, or instructions for one that settles offline.
     */
    public function createCheckout(Subscription $subscription): CheckoutIntent;

    /**
     * Completes payment after the carrier returns from the gateway.
     *
     * Implementations **must** verify that the money actually captured matches
     * the plan's price and currency before reporting success. The amount is
     * never taken from the client: a checkout the carrier can edit on the way
     * through is a checkout they can pay a dollar for.
     *
     * @return string|null The gateway's reference for the payment, or null if
     *                     it did not complete.
     */
    public function capture(Subscription $subscription, string $reference): ?string;
}
