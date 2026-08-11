<?php

namespace App\Mail;

use App\Models\ContactMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The internal notification for a contact form enquiry.
 *
 * Plain text on purpose. Every field is customer-supplied, and a text part
 * cannot be coaxed into rendering markup or a link the sender did not intend.
 */
class ContactEnquiry extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly ContactMessage $enquiry) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Website enquiry: '.($this->enquiry->subject ?: 'General enquiry'),
            // From our own domain, so SPF and DMARC still pass — the customer's
            // address goes in Reply-To, which is what staff actually need.
            replyTo: [new Address($this->enquiry->email, $this->enquiry->name)],
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.contact-enquiry');
    }
}
