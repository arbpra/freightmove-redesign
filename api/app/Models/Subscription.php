<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $fillable = [
        'legacy_id', 'user_id', 'subscription_plan_id',
        'status', 'starts_on', 'ends_on', 'gateway_reference',
    ];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }

    /**
     * The statuses that still entitle the holder to quote.
     *
     * `pending` is **not** one of them: an unpaid subscription is an intention,
     * not an entitlement, and treating it as access would mean anyone could
     * have the paid product for free by reserving a plan and never paying.
     *
     * `cancelled` **is** one of them, until the end date passes. Cancelling
     * means "do not renew", not "refund me by locking me out of the period I
     * have already paid for" — which is also what the cancellation message
     * promises the carrier.
     */
    public const ENTITLING_STATUSES = ['active', 'cancelled'];

    /**
     * Currently entitling the carrier to quote.
     *
     * A null `ends_on` is open-ended, which is how a handful of legacy periods
     * were recorded.
     */
    public function isCurrent(): bool
    {
        return in_array($this->status, self::ENTITLING_STATUSES, true)
            && ($this->ends_on === null || ! $this->ends_on->isPast());
    }

    /** The single definition of "entitled", used by every caller. */
    public function scopeCurrent($query)
    {
        return $query->whereIn('status', self::ENTITLING_STATUSES)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', today()));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }
}
