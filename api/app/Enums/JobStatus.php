<?php

namespace App\Enums;

/**
 * Lifecycle of a freight job, per docs/05-database-schema.md.
 *
 * Draft -> Published -> Matched -> Quoted -> Accepted -> Completed,
 * with Cancelled and Disputed reachable from most states.
 */
enum JobStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Matched = 'matched';
    case Quoted = 'quoted';
    case Accepted = 'accepted';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Disputed = 'disputed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Statuses where carriers may still submit a quote.
     *
     * @return list<self>
     */
    public static function openForQuotes(): array
    {
        return [self::Published, self::Matched, self::Quoted];
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }
}
