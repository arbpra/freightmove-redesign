<?php

namespace App\Models;

use App\Enums\QuoteStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class JobQuote extends Model
{
    /** @use HasFactory<\Database\Factories\JobQuoteFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'job_id',
        'carrier_id',
        'amount',
        'currency',
        'estimated_delivery_date',
        'notes',
        'status',
        'match_score',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => QuoteStatus::class,
            'amount' => 'decimal:2',
            'match_score' => 'decimal:2',
            'estimated_delivery_date' => 'date',
            'expires_at' => 'datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(FreightJob::class, 'job_id');
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'carrier_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', QuoteStatus::Pending);
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
