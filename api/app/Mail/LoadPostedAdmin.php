<?php

namespace App\Mail;

use App\Models\FreightJob;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The operator's copy: a shipper has posted a load.
 *
 * One message per load, not per carrier — this is the "something happened on
 * the marketplace" notice, and it carries the fan-out count so the volume
 * going out under it is visible rather than implied.
 */
class LoadPostedAdmin extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly FreightJob $job,
        public readonly int $carriersNotified = 0,
    ) {}

    public function envelope(): Envelope
    {
        $shipper = $this->job->shipper?->profile?->company_name
            ?: $this->job->shipper?->name
            ?: 'A shipper';

        return new Envelope(subject: "New load posted by {$shipper}");
    }

    public function content(): Content
    {
        $base = rtrim((string) config('freightmove.frontend_url'), '/');

        return new Content(view: 'mail.load-posted-admin', with: [
            'job' => $this->job,
            'shipper' => $this->job->user,
            'carriersNotified' => $this->carriersNotified,
            'url' => "{$base}/admin/jobs",
        ]);
    }
}
