<?php

namespace App\Mail;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The operator's copy: a carrier has paid.
 *
 * The carrier's own receipt is `SubscriptionConfirmed`. This is the other side
 * of the same event, and it exists because the PayPal path has no human in it:
 * the carrier pays, the capture confirms, the subscription switches itself on,
 * and nothing else would say so. Under the manual gateway an admin at least
 * clicked a button; under PayPal the first anyone here would know of a sale is
 * the bank statement.
 *
 * Written to be read on a phone in a notification preview, so the subject
 * carries the two facts that matter — who, and how much.
 */
class SubscriptionPaymentReceived extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly ?string $reference = null,
    ) {}

    public function envelope(): Envelope
    {
        $carrier = $this->subscription->user?->profile?->company_name
            ?: $this->subscription->user?->name
            ?: 'A carrier';

        $amount = number_format((float) ($this->subscription->plan?->price ?? 0), 2);

        return new Envelope(subject: "Payment received: {$carrier} — \${$amount}");
    }

    public function content(): Content
    {
        $base = rtrim((string) config('freightmove.frontend_url'), '/');

        return new Content(view: 'mail.subscription-payment-received', with: [
            'subscription' => $this->subscription,
            'plan' => $this->subscription->plan,
            'carrier' => $this->subscription->user,
            'company' => $this->subscription->user?->profile?->company_name,
            'reference' => $this->reference ?? $this->subscription->gateway_reference,
            'gateway' => config('freightmove.subscriptions.gateway'),
            'url' => "{$base}/admin/payments",
        ]);
    }
}
