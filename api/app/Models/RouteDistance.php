<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cached driving distance between two suburbs.
 *
 * See the create_route_distances_table migration for why the numbers and the
 * display text are both stored.
 */
class RouteDistance extends Model
{
    protected $fillable = [
        'legacy_id',
        'pickup_suburb_id',
        'dropoff_suburb_id',
        'distance_metres',
        'duration_seconds',
        'distance_text',
        'duration_text',
        'lookups',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'distance_metres' => 'integer',
            'duration_seconds' => 'integer',
            'lookups' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }

    public function pickupSuburb(): BelongsTo
    {
        return $this->belongsTo(Suburb::class, 'pickup_suburb_id');
    }

    public function dropoffSuburb(): BelongsTo
    {
        return $this->belongsTo(Suburb::class, 'dropoff_suburb_id');
    }

    /**
     * Records a cache hit.
     *
     * Incremented in the database rather than read-modify-write so concurrent
     * lookups of a popular lane cannot lose counts.
     */
    public function recordHit(): void
    {
        $this->increment('lookups', 1, ['last_used_at' => now()]);
    }

    /**
     * Metres from a Distance Matrix distance, in either shape the legacy data
     * contains: a bare integer already in metres, or the display text.
     *
     * Live data holds "3,616 km" (642 rows), "12.4 km" (11), "1 m" (8) and two
     * bare integers. Returns null for anything else rather than guessing — the
     * importer reports what it could not read.
     */
    public static function metresFrom(?string $value): ?int
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d+$/', $value)) {
            return (int) $value;
        }

        if (preg_match('/^([\d,]+(?:\.\d+)?)\s*(km|m)$/i', $value, $match)) {
            $number = (float) str_replace(',', '', $match[1]);

            return (int) round(strtolower($match[2]) === 'km' ? $number * 1000 : $number);
        }

        return null;
    }

    /**
     * Seconds from a Distance Matrix duration.
     *
     * Google varies the wording by magnitude and pluralises inconsistently —
     * live data contains "1 day 15 hours", "17 hours 5 mins", "1 hour 3 min"
     * and "1 min" — so every "<number> <unit>" pair is summed rather than
     * matching each phrasing. A bare integer is already seconds.
     */
    public static function secondsFrom(?string $value): ?int
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d+$/', $value)) {
            return (int) $value;
        }

        if (! preg_match_all('/(\d+)\s*(day|hour|hr|min|sec)/i', $value, $matches, PREG_SET_ORDER)) {
            return null;
        }

        $units = ['day' => 86400, 'hour' => 3600, 'hr' => 3600, 'min' => 60, 'sec' => 1];

        return array_reduce(
            $matches,
            fn (int $carry, array $m) => $carry + (int) $m[1] * $units[strtolower($m[2])],
            0,
        );
    }
}
