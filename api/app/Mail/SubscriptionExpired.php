<?php

namespace App\Mail;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Your subscription has ended."
 *
 * Sent on a cadence after the end date — 3, 7 and 15 days, then monthly. It
 * exists because lapsing is silent: nothing on the site announces it, and a
 * carrier who has stopped seeing quote buttons is more likely to assume the
 * board is quiet than to assume they have lapsed.
 *
 * The tone has to move with the calendar. The same words at three days and at
 * eighteen months would be wrong twice: at three days the carrier probably
 * just missed the renewal, and at eighteen months they have made a decision
 * and the message should acknowledge it rather than pretend nothing happened.
 * `$milestone` and `$daysSince` are what let it tell the difference.
 *
 * Whether they have actually lost anything depends on
 * `FM_REQUIRE_SUBSCRIPTION_TO_QUOTE`, which is currently off — 289 of the 291
 * migrated carriers hold no current subscription, so switching it on today
 * would empty the marketplace. `$gated` carries that distinction into the copy
 * rather than letting the email claim access was withdrawn when it was not.
 * Telling 289 people they have lost something they still have is a support
 * queue, and it teaches them the email is wrong.
 */
class SubscriptionExpired extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly string $milestone = 'd3',
        public readonly int $daysSince = 0,
    ) {}

    /** True once this is a monthly follow-up rather than the first week's news. */
    public function isLongLapsed(): bool
    {
        return str_starts_with($this->milestone, 'm');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->isLongLapsed()
            ? 'Come back to FreightMove when you need a load'
            : 'Your FreightMove subscription has ended');
    }

    public function content(): Content
    {
        $base = rtrim((string) config('freightmove.frontend_url'), '/');

        return new Content(view: 'mail.subscription-expired', with: [
            'subscription' => $this->subscription,
            'plan' => $this->subscription->plan,
            'gated' => (bool) config('freightmove.quoting.require_subscription'),
            'longLapsed' => $this->isLongLapsed(),
            'elapsed' => $this->elapsed(),
            'url' => "{$base}/carrier/subscription",
        ]);
    }

    /** "3 days ago", "2 months ago" — how the body refers to the end date. */
    private function elapsed(): string
    {
        if ($this->isLongLapsed()) {
            $months = (int) substr($this->milestone, 1);

            return $months === 1 ? 'a month ago' : "{$months} months ago";
        }

        return match (true) {
            $this->daysSince <= 1 => 'yesterday',
            default => "{$this->daysSince} days ago",
        };
    }
}
