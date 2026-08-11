<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Models\Conversation;
use App\Models\FreightJob;
use App\Models\JobQuote;
use App\Models\Notification;
use App\Models\User;
use App\Models\VerificationDocument;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes the in-app notification feed.
 *
 * Two rules run through everything here.
 *
 * **Notifying must never break the thing being notified about.** Accepting a
 * quote is the transaction; telling people about it is a consequence. Every
 * public method swallows its own failures and logs them, so a full disk or a
 * bad column can never turn a successful booking into a 500 for the shipper.
 *
 * **The recipient is never the actor.** Nobody needs telling about something
 * they just did, so each method takes the audience explicitly rather than
 * notifying everyone attached to a record.
 */
class Notifier
{
    /** A carrier priced a shipper's load. */
    public function quoteReceived(JobQuote $quote, FreightJob $job): void
    {
        $carrier = $quote->carrier?->profile?->company_name
            ?? $quote->carrier?->name
            ?? 'A carrier';

        $this->write(
            $job->shipper_id,
            NotificationType::QuoteReceived,
            "New quote on {$job->title}",
            sprintf('%s quoted $%s.', $carrier, number_format((float) $quote->amount, 2)),
            $job,
        );
    }

    /** A carrier pulled their quote before the shipper decided. */
    public function quoteWithdrawn(JobQuote $quote, FreightJob $job): void
    {
        $carrier = $quote->carrier?->profile?->company_name
            ?? $quote->carrier?->name
            ?? 'A carrier';

        $this->write(
            $job->shipper_id,
            NotificationType::QuoteWithdrawn,
            "A quote on {$job->title} was withdrawn",
            "{$carrier} is no longer available for this load.",
            $job,
        );
    }

    /** The winning carrier. */
    public function quoteAccepted(JobQuote $quote, FreightJob $job): void
    {
        $this->write(
            $quote->carrier_id,
            NotificationType::QuoteAccepted,
            "You won the job: {$job->title}",
            sprintf(
                'Your quote of $%s was accepted. %s → %s.',
                number_format((float) $quote->amount, 2),
                $job->pickup_location,
                $job->delivery_location,
            ),
            $job,
        );
    }

    /**
     * Everyone who did not win.
     *
     * Written as one insert rather than a loop: a popular lane can attract a
     * dozen quotes, and a dozen round trips inside a request that has already
     * done its real work is waste.
     *
     * @param  iterable<JobQuote>  $quotes
     */
    public function quotesDeclined(iterable $quotes, FreightJob $job, string $reason): void
    {
        $now = now();
        $rows = [];

        foreach ($quotes as $quote) {
            $rows[] = [
                'user_id' => $quote->carrier_id,
                'type' => NotificationType::QuoteDeclined->value,
                'title' => "Not this time: {$job->title}",
                'body' => $reason,
                'is_read' => false,
                'related_type' => 'freight_job',
                'related_id' => $job->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === []) {
            return;
        }

        $this->guard(fn () => Notification::insert($rows));
    }

    /** The shipper signed the load off as delivered. */
    public function jobCompleted(FreightJob $job, int $carrierId): void
    {
        $this->write(
            $carrierId,
            NotificationType::JobCompleted,
            "Completed: {$job->title}",
            'The shipper has signed this load off. You can leave them a review.',
            $job,
        );
    }

    /** Someone left a review. */
    public function reviewReceived(FreightJob $job, int $recipientId, int $rating): void
    {
        $this->write(
            $recipientId,
            NotificationType::ReviewReceived,
            'You have a new review',
            sprintf('%d out of 5 on "%s".', $rating, $job->title),
            $job,
        );
    }

    /**
     * A new message.
     *
     * Deliberately at most **one unread notification per conversation**. A
     * chat is a burst of short messages, and a feed that gains an entry for
     * each of them stops being a feed and becomes a second, worse inbox. The
     * badge already says "there is something here"; saying it eleven times adds
     * nothing. Once the recipient reads the notification, the next message
     * raises a fresh one.
     */
    public function messageReceived(
        Conversation $conversation,
        int $recipientId,
        User $sender,
        string $body,
    ): void {
        $this->guard(function () use ($conversation, $recipientId, $sender, $body) {
            $alreadyWaiting = Notification::query()
                ->where('user_id', $recipientId)
                ->where('type', NotificationType::MessageReceived->value)
                ->where('related_type', 'conversation')
                ->where('related_id', $conversation->id)
                ->unread()
                ->exists();

            if ($alreadyWaiting) {
                return;
            }

            $from = $sender->profile?->company_name ?: $sender->name;

            Notification::create([
                'user_id' => $recipientId,
                'type' => NotificationType::MessageReceived->value,
                'title' => "Message from {$from}",
                'body' => Str::limit($body, 120),
                'is_read' => false,
                'related_type' => 'conversation',
                'related_id' => $conversation->id,
            ]);
        });
    }

    public function documentReviewed(VerificationDocument $document, bool $approved): void
    {
        $this->write(
            $document->user_id,
            $approved ? NotificationType::DocumentApproved : NotificationType::DocumentRejected,
            $approved
                ? 'Document approved'
                : 'A document needs redoing',
            $approved
                ? "Your {$document->document_type} has been approved."
                : ($document->review_note ?: "Your {$document->document_type} was not accepted."),
            null,
            'verification_document',
            $document->id,
        );
    }

    public function carrierVerified(User $carrier): void
    {
        $this->write(
            $carrier->id,
            NotificationType::CarrierVerified,
            'Your account is verified',
            'Shippers now see the verified badge on every quote you send.',
            null,
            'user',
            $carrier->id,
        );
    }

    /**
     * The single-row path.
     *
     * `$job` is a convenience: pass it and the notification points at that load.
     */
    private function write(
        ?int $userId,
        NotificationType $type,
        string $title,
        ?string $body,
        ?FreightJob $job = null,
        ?string $relatedType = null,
        ?int $relatedId = null,
    ): void {
        if (! $userId) {
            return;
        }

        $this->guard(fn () => Notification::create([
            'user_id' => $userId,
            'type' => $type->value,
            'title' => $title,
            'body' => $body,
            'is_read' => false,
            'related_type' => $job ? 'freight_job' : $relatedType,
            'related_id' => $job?->id ?? $relatedId,
        ]));
    }

    /**
     * Runs a write and absorbs any failure.
     *
     * See the class docblock: the notification is downstream of the real work,
     * and must not be able to undo it.
     */
    private function guard(callable $write): void
    {
        try {
            $write();
        } catch (Throwable $e) {
            Log::error('Could not write a notification.', ['error' => $e->getMessage()]);
        }
    }
}
