<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per reminder milestone reached, per subscription.
 *
 * Written *before* the email goes out and deleted again if the send throws, so
 * a row means "this message is claimed", not "this message arrived". The
 * alternative — send first, record after — loses the record if the process
 * dies mid-send and mails the carrier twice on the next run.
 *
 * A row with `sent_at` null was reached but deliberately not emailed;
 * `skip_reason` says why. Those are kept rather than discarded because this
 * table is also the admin record, and "we decided not to" is a different
 * answer from "we never got that far".
 */
class SubscriptionReminder extends Model
{
    public const KIND_EXPIRING = 'expiring';

    public const KIND_EXPIRED = 'expired';

    /** The backlog of a long-lapsed subscription, collapsed to one send. */
    public const SKIP_BACKLOG = 'backlog';

    protected $fillable = [
        'subscription_id',
        'user_id',
        'kind',
        'milestone',
        'sent_at',
        'skip_reason',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Only the ones a carrier actually received. */
    public function scopeDelivered(Builder $query): Builder
    {
        return $query->whereNotNull('sent_at');
    }

    /**
     * How this reads in the admin list.
     *
     * Built here rather than in the client so the API, the CSV and any future
     * consumer all say the same thing.
     */
    public function label(): string
    {
        $unit = substr($this->milestone, 0, 1);
        $n = (int) substr($this->milestone, 1);

        if ($this->kind === self::KIND_EXPIRING) {
            return $n === 1 ? '1 day before expiry' : "{$n} days before expiry";
        }

        if ($unit === 'm') {
            return $n === 1 ? '1 month after expiry' : "{$n} months after expiry";
        }

        return $n === 1 ? '1 day after expiry' : "{$n} days after expiry";
    }
}
