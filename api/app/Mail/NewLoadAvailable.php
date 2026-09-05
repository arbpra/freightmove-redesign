<?php

namespace App\Mail;

use App\Models\FreightJob;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

/**
 * "A load has been posted." Sent to carriers.
 *
 * The highest-volume message this application sends by a wide margin — roughly
 * 11,800 a month at current posting rates — so it is written to be skimmed and
 * to be easy to stop. The subject is the lane, because that is the only thing a
 * carrier uses to decide whether to open it.
 *
 * The unsubscribe link is signed and expires in a year. It has to work without
 * a login: someone who wants these to stop is exactly the person who will not
 * sign in to make them stop, and a link that asks them to is a spam complaint
 * instead. The Spam Act 2003 requires a functional opt-out on commercial
 * electronic messages to Australian addresses.
 */
class NewLoadAvailable extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly FreightJob $job,
        public readonly User $carrier,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "New load: {$this->lane()}");
    }

    public function content(): Content
    {
        $base = rtrim((string) config('freightmove.frontend_url'), '/');

        return new Content(view: 'mail.new-load-available', with: [
            'job' => $this->job,
            'lane' => $this->lane(),
            'url' => "{$base}/carrier/board",
            'unsubscribeUrl' => $this->unsubscribeUrl(),
        ]);
    }

    /** "Sydney NSW to Melbourne VIC" — the one line that decides an open. */
    private function lane(): string
    {
        $from = trim((string) $this->job->pickup_location);
        $to = trim((string) $this->job->delivery_location);

        return $from !== '' && $to !== '' ? "{$from} to {$to}" : ($this->job->title ?? 'freight available');
    }

    /**
     * A signed URL rather than a token column: nothing to store, nothing to
     * leak, and it cannot be pointed at another account by editing the id.
     */
    private function unsubscribeUrl(): string
    {
        return URL::temporarySignedRoute(
            'load-alerts.unsubscribe',
            now()->addYear(),
            ['user' => $this->carrier->id],
        );
    }
}
