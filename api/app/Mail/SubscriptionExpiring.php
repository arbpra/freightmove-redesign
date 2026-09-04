<?php

namespace App\Mail;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Your subscription ends soon."
 *
 * The one email on the list whose entire value is its timing. A carrier whose
 * access lapses does not get an error at a moment they can act on it — they
 * find out when they open a load they wanted to quote on and the button is
 * gone, usually while the shipper is still collecting prices. By then the
 * quote is late even if they renew immediately.
 *
 * `$daysLeft` is the *real* number of days remaining, not the bucket that
 * triggered the send, so a run that slipped a day still says something true.
 */
class SubscriptionExpiring extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly int $daysLeft,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: match (true) {
            $this->daysLeft <= 0 => 'Your FreightMove subscription ends today',
            $this->daysLeft === 1 => 'Your FreightMove subscription ends tomorrow',
            default => "Your FreightMove subscription ends in {$this->daysLeft} days",
        });
    }

    public function content(): Content
    {
        $base = rtrim((string) config('freightmove.frontend_url'), '/');

        return new Content(view: 'mail.subscription-expiring', with: [
            'subscription' => $this->subscription,
            'plan' => $this->subscription->plan,
            'daysLeft' => $this->daysLeft,
            'countdown' => match (true) {
                $this->daysLeft <= 0 => 'today',
                $this->daysLeft === 1 => 'tomorrow',
                default => "in {$this->daysLeft} days",
            },
            'url' => "{$base}/carrier/subscription",
        ]);
    }
}
