<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per carrier per load: was this person told, and when.
 *
 * Written before the send and stamped after it, so a row with a null `sent_at`
 * and no `failure` means "in flight" rather than "delivered". The legacy site
 * had a table shaped for this and never filled it.
 */
class LoadAlert extends Model
{
    protected $fillable = ['freight_job_id', 'user_id', 'sent_at', 'failure'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(FreightJob::class, 'freight_job_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeDelivered(Builder $query): Builder
    {
        return $query->whereNotNull('sent_at');
    }
}
