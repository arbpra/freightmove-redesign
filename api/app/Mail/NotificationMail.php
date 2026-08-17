<?php

namespace App\Mail;

use App\Models\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * An in-app notification, delivered by email.
 *
 * One mailable for every notification type rather than ten near-identical
 * classes. The wording already exists on the notification record, so the bell
 * and the inbox cannot drift apart, and a new notification type gets email for
 * free once it is added to the `mail.notify` allow-list in config.
 *
 * Which types actually send is a product decision, not a technical one — see
 * that config. A marketplace that emails on every event trains people to filter
 * it, and then the one email that mattered goes unread too.
 */
class NotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Notification $notification,
        /** Deep link into the app, or null when the type has nowhere to go. */
        public readonly ?string $url = null,
        public readonly string $action = 'Open FreightMove',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->notification->title);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.notification', with: [
            'notification' => $this->notification,
            'url' => $this->url,
            'action' => $this->action,
        ]);
    }
}
