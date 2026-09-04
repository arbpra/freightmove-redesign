<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payment against a carrier subscription — the equivalent of the legacy
 * `paypal_transaction` table, which holds 69 rows and $5,775.23 of history.
 *
 * `status` is always lower case. PayPal reports `COMPLETED`; MySQL's
 * case-insensitive collation hid the mismatch from every query, but the two
 * spellings still reached the API's JSON, where a client comparing
 * `status === 'completed'` sees only one of them. Normalised on write here and
 * for existing rows by the 2026_09_03_000002 migration.
 */
class SubscriptionPayment extends Model
{
    /** Money actually taken. */
    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'legacy_id', 'user_id', 'subscription_plan_id', 'gateway', 'gateway_reference',
        'payer_name', 'payer_email', 'amount', 'currency', 'status', 'paid_at',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_at' => 'datetime'];
    }

    /** Lower-cased on the way in, so one spelling reaches the database. */
    public function setStatusAttribute(?string $value): void
    {
        $this->attributes['status'] = $value === null ? null : strtolower($value);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    /** Money that actually arrived, for totals that must not overstate. */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }
}
