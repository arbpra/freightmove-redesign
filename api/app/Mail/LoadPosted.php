<?php

namespace App\Mail;

use App\Models\FreightJob;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Confirms to a shipper that their load is live.
 *
 * The previous site sent this, so shippers expect it. It is a receipt rather
 * than a notification — the shipper is the one who acted, and the feed
 * deliberately never tells anyone about their own actions — which is why it is
 * its own mailable rather than going through the Notifier.
 */
class LoadPosted extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly FreightJob $job) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your load is live: {$this->job->title}");
    }

    public function content(): Content
    {
        $base = rtrim((string) config('freightmove.frontend_url'), '/');

        return new Content(view: 'mail.load-posted', with: [
            'job' => $this->job,
            'url' => "{$base}/shipper/jobs/{$this->job->id}/quotes",
        ]);
    }
}
