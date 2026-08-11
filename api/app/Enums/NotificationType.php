<?php

namespace App\Enums;

/**
 * The marketplace moments worth interrupting someone for.
 *
 * Deliberately short. A feed that reports everything is a feed nobody reads, so
 * each entry here is a point where the recipient either has a decision to make
 * or has been waiting on an answer.
 */
enum NotificationType: string
{
    /** A carrier priced your load. */
    case QuoteReceived = 'quote.received';

    /** A carrier withdrew their quote before you decided. */
    case QuoteWithdrawn = 'quote.withdrawn';

    /** Your quote won the job. */
    case QuoteAccepted = 'quote.accepted';

    /** Your quote was not taken. */
    case QuoteDeclined = 'quote.declined';

    /** A load you carried was signed off as delivered. */
    case JobCompleted = 'job.completed';

    /** The other side left you a review. */
    case ReviewReceived = 'review.received';

    /** Someone replied on a load you are both working on. */
    case MessageReceived = 'message.received';

    /** A verification document was approved. */
    case DocumentApproved = 'document.approved';

    /** A verification document was rejected and needs redoing. */
    case DocumentRejected = 'document.rejected';

    /** The whole account reached verified. */
    case CarrierVerified = 'carrier.verified';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
