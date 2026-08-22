<?php

namespace App\Models;

use App\Enums\JobStatus;
use App\Enums\LoadAvailability;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
        'availability',
        'relisted_at',
        'delivery_date',
        'load_category',
        'quantity',
        'length_mm',
        'width_mm',
        'height_mm',
        'weight_kg',
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
            'availability' => LoadAvailability::class,
            'pickup_date' => 'date',
            'relisted_at' => 'datetime',
            'delivery_date' => 'date',
            'length_mm' => 'integer',
            'width_mm' => 'integer',
            'height_mm' => 'integer',
            'weight_kg' => 'integer',
            'budget_min' => 'decimal:2',
            'budget_max' => 'decimal:2',
            'images_json' => 'array',
            'documents_json' => 'array',
        ];
    }

    /**
     * The weight in tonnes, for display.
     *
     * Kilograms are what the shipper types and what is stored; tonnes are what
     * a carrier scanning the board wants to read. Derived rather than stored so
     * the two can never disagree — the previous schema kept only tonnes, and
     * the kilogram value the shipper actually entered was lost to rounding.
     */
    public function weightTons(): ?float
    {
        return $this->weight_kg === null ? null : round($this->weight_kg / 1000, 2);
    }

    /**
     * Length x width x height as a single readable string, or null when no
     * dimension was given. Millimetres, as the form asks for.
     */
    public function dimensionsLabel(): ?string
    {
        $parts = array_filter([$this->length_mm, $this->width_mm, $this->height_mm]);

        if ($parts === []) {
            return null;
        }

        return implode(' × ', array_map(fn (int $mm) => number_format($mm), $parts)).' mm';
    }

    public function shipper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shipper_id');
    }

    /**
     * Legacy loads carry several of each — 67 of 103 live loads list more than
     * one truck type — so these pivots are the source of truth. The singular
     * `load_category` / `trailer_type_required` columns are denormalised copies
     * of the primary value, kept for cheap list rendering.
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    public function truckTypes(): BelongsToMany
    {
        return $this->belongsToMany(TruckType::class);
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

    /**
     * Loads recent enough to still be worth quoting.
     *
     * The legacy board hardcoded 7 days (docs/10-domain-rules.md R4); the window
     * is configurable here, and 0 disables it. Measured from `relisted_at` when
     * present so a bumped load returns to the top — see scopeBoardOrder.
     */
    public function scopeRecent(Builder $query, ?int $days = null): Builder
    {
        $days ??= (int) config('freightmove.board.recency_days');

        if ($days <= 0) {
            return $query;
        }

        return $query->where(
            fn ($inner) => $inner
                ->where('relisted_at', '>=', now()->subDays($days))
                ->orWhere(fn ($q) => $q->whereNull('relisted_at')
                    ->where('created_at', '>=', now()->subDays($days)))
        );
    }

    /** Freshest first, counting a relist as fresh. */
    public function scopeBoardOrder(Builder $query): Builder
    {
        return $query->orderByRaw('COALESCE(relisted_at, created_at) DESC');
    }
}
