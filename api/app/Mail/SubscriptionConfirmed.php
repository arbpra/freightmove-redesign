<?php

namespace App\Mail;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A receipt, sent once a subscription is paid for and running.
 *
 * Someone who has just parted with $699.90 should get something in writing
 * without having to log in and look — and if they later query the charge, this
 * is the record they will search their inbox for.
 */
class SubscriptionConfirmed extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Subscription $subscription) {}

    public function envelope(): Envelope
    {
        $plan = $this->subscription->plan?->name ?? 'subscription';

        return new Envelope(subject: "Your {$plan} is active");
    }

    public function content(): Content
    {
        $base = rtrim((string) config('freightmove.frontend_url'), '/');

        return new Content(view: 'mail.subscription-confirmed', with: [
            'subscription' => $this->subscription,
            'plan' => $this->subscription->plan,
            'url' => "{$base}/carrier/board",
        ]);
    }
}
