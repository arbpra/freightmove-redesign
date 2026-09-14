<?php

namespace App\Support;

/**
 * Location strings, as they should be read rather than as they were captured.
 *
 * Google Places returns a full formatted address, so every location on this
 * marketplace is stored as "Townsville QLD, Australia". A lane then renders as
 * "Townsville QLD, Australia to Brisbane QLD, Australia" — thirteen characters
 * of each end spent on a fact that is true of every load on an Australian
 * freight board, and which pushes the two place names far enough apart that
 * they stop reading as a pair.
 *
 * The country is dropped for display only. The column keeps what the shipper
 * actually selected, because that is the record of what they meant and it is
 * what a future geocode would be run against.
 *
 * Only Australia is stripped. A rare interstate-to-overseas entry should keep
 * its country, since there the country is the whole point.
 */
class Place
{
    /**
     * The place, without the country everyone already knows.
     *
     * Returns the original when stripping would leave nothing — a location
     * recorded only as "Australia" is not informative, but it is better than
     * an empty line where a suburb should be.
     */
    public static function short(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        $short = preg_replace('/\s*,?\s*Australia\s*$/i', '', $trimmed);
        $short = trim((string) $short, " \t,");

        return $short === '' ? $trimmed : $short;
    }
}
