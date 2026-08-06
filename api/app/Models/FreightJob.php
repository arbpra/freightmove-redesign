<?php

namespace App\Models;

use App\Enums\JobStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A freight load posted by a shipper.
 *
 * Named FreightJob rather than Job so it is never confused with Laravel's
 * queue jobs, which own the unrelated `jobs` table.
 */
class FreightJob extends Model
{
    /** @use HasFactory<\Database\Factories\FreightJobFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'shipper_id',
        'title',
        'description',
        'pickup_location',
        'delivery_location',
        'pickup_date',
        'delivery_date',
        'load_category',
        'weight_tons',
        'vehicle_type_required',
        'trailer_type_required',
        'budget_min',
        'budget_max',
        'status',
        'visibility',
        'images_json',
        'documents_json',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => JobStatus::class,
            'pickup_date' => 'date',
            'delivery_date' => 'date',
            'weight_tons' => 'decimal:2',
            'budget_min' => 'decimal:2',
            'budget_max' => 'decimal:2',
            'images_json' => 'array',
            'documents_json' => 'array',
        ];
    }

    public function shipper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shipper_id');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(JobQuote::class, 'job_id');
    }

    public function acceptance(): HasOne
    {
        return $this->hasOne(JobAcceptance::class, 'job_id');
    }

    public function tracking(): HasOne
    {
        return $this->hasOne(JobTracking::class, 'job_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class, 'job_id');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'job_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'job_id');
    }

    /** Jobs visible on the public board. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereIn('status', JobStatus::openForQuotes())
            ->where('visibility', 'public');
    }

    public function scopeForShipper(Builder $query, int $shipperId): Builder
    {
        return $query->where('shipper_id', $shipperId);
    }
}
