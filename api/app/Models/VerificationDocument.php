<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VerificationDocument extends Model
{
    /** @use HasFactory<\Database\Factories\VerificationDocumentFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'document_type',
        'file_path',
        'original_name',
        'mime_type',
        'size_bytes',
        'status',
        'reviewed_by',
        'review_note',
        'reviewed_at',
        'expires_at',
    ];

    /**
     * The stored path is deliberately never serialised.
     *
     * It is a location on a private disk. Nothing outside the download endpoint
     * has any use for it, and leaking it invites someone to go looking for a
     * way to fetch it directly.
     */
    protected $hidden = ['file_path'];

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'size_bytes' => 'integer',
            'reviewed_at' => 'datetime',
            'expires_at' => 'date',
        ];
    }

    /** A document past its expiry proves nothing, whatever its status says. */
    public function hasLapsed(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return $this->status === DocumentStatus::Approved && ! $this->hasLapsed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopeAwaitingReview(Builder $query): Builder
    {
        return $query->where('status', DocumentStatus::Pending);
    }
}
