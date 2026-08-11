<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    /** @use HasFactory<\Database\Factories\ConversationFactory> */
    use HasFactory;

    protected $fillable = [
        'job_id',
        'participant_one_id',
        'participant_two_id',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(FreightJob::class, 'job_id');
    }

    public function participantOne(): BelongsTo
    {
        return $this->belongsTo(User::class, 'participant_one_id');
    }

    public function participantTwo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'participant_two_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Finds or opens the thread between two people about one load.
     *
     * Participants are stored **lowest user id first**, always. The unique
     * index is on `(job_id, participant_one_id, participant_two_id)`, which only
     * prevents a duplicate if the pair is written in a consistent order —
     * otherwise (job, A, B) and (job, B, A) are different rows to the database
     * and the same conversation to everyone else. Normalising here is what makes
     * that index mean what it looks like it means.
     */
    public static function between(int $jobId, int $userA, int $userB): self
    {
        return static::firstOrCreate([
            'job_id' => $jobId,
            'participant_one_id' => min($userA, $userB),
            'participant_two_id' => max($userA, $userB),
        ]);
    }

    /** The other person, from the point of view of the given user. */
    public function counterpartId(int $userId): int
    {
        return $this->participant_one_id === $userId
            ? $this->participant_two_id
            : $this->participant_one_id;
    }

    public function includes(int $userId): bool
    {
        return $this->participant_one_id === $userId || $this->participant_two_id === $userId;
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where(function (Builder $q) use ($userId) {
            $q->where('participant_one_id', $userId)
                ->orWhere('participant_two_id', $userId);
        });
    }
}
