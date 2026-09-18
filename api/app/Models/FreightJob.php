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
use Illuminate\Support\Facades\Storage;

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
     * The attached photos, as paths plus public URLs.
     *
     * Legacy rows hold a bare filename from the old `public/images/load`
     * folder rather than a path on our disk. Those come back with a null url
     * instead of a broken link — the files were never migrated, and a 404
     * image is worse than an honest absence.
     *
     * Lives here rather than in a controller because both the job resource and
     * the upload endpoint answer with it, and two copies of this rule would
     * drift the first time the disk changed.
     *
     * @return list<array{path: string, url: string|null}>
     */
    public function imageList(): array
    {
        return array_values(array_map(fn (string $path) => [
            'path' => $path,
            'url' => str_contains($path, '/') ? Storage::disk('public')->url($path) : null,
        ], $this->images_json ?? []));
    }

    /**
     * The public handle for this load — "FM-000212".
     *
     * Opaque on purpose: the board is browsable without an account, and a
     * sequential primary key in a URL tells a stranger how many loads exist and
     * lets them walk the lot. This is what the public routes accept, so it is
     * also what any link into the site has to be built from.
     *
     * Defined here because three places were formatting it by hand, and a
     * fourth was about to.
     */
    public function reference(): string
    {
        return 'FM-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    /** The public page for this load, for links in email. */
    public function publicUrl(): string
    {
        $base = rtrim((string) config('freightmove.frontend_url'), '/');

        return "{$base}/load-board/{$this->reference()}";
    }

    /**
     * The first photo's URL, or null when the load has none.
     *
     * The board shows one thumbnail per card. Reusing `imageList()` and taking
     * the head would build every URL to discard all but one, on every row of
     * every page.
     */
    public function firstImageUrl(): ?string
    {
        foreach ($this->images_json ?? [] as $path) {
            // Legacy rows hold a bare filename with no directory, which was
            // never a path on this disk and resolves to nothing.
            if (is_string($path) && str_contains($path, '/')) {
                return Storage::disk('public')->url($path);
            }
        }

        return null;
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
     * Length, width and height as one readable string, or null when no
     * dimension was given. Millimetres, as the form asks for.
     *
     * Each axis is named, in full. "11,997 × 3,200 × 3,800 mm" asks a carrier
     * to infer an order that is only a convention, and the number that decides
     * whether a load needs a permit is the one they most need to be sure
     * about. `L`/`W`/`H` is shorter but it is still jargon — a shipper posting
     * their first load should not have to decode it.
     *
     * Naming them also fixes a real defect rather than only a confusing one.
     * This used to `array_filter` the three values, which drops anything null
     * **and reindexes** — so a load with no width rendered as
     * "11,997 × 3,800 mm", two numbers with nothing to say that the middle one
     * is missing. Now that reads "Length 11,997 × Height 3,800 mm".
     *
     * This string is for places that can only take one — the SEO description,
     * a list row. Where there is room, the axes are laid out individually; see
     * the load detail page.
     */
    public function dimensionsLabel(): ?string
    {
        $parts = [];

        foreach ([['Length', $this->length_mm], ['Width', $this->width_mm], ['Height', $this->height_mm]] as [$axis, $mm]) {
            if ($mm !== null && $mm > 0) {
                $parts[] = $axis.' '.number_format($mm);
            }
        }

        return $parts === [] ? null : implode(' × ', $parts).' mm';
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
