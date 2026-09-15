<?php

namespace App\Http\Resources;

use App\Support\Place;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A load as an unauthenticated visitor may see it.
 *
 * **One definition, used by both public endpoints** — the home page teaser and
 * the full board. Two hand-rolled copies of "what a guest may see" is how a
 * field gets added to one and quietly leaks from the other.
 *
 * Everything here is safe to publish. What is deliberately absent:
 *
 *   - `id` — nothing for a guest to try fetching directly.
 *   - `budget_min` / `budget_max` — the shipper's negotiating position.
 *   - `description` — where site contacts, gate codes and phone numbers end up.
 *   - the shipper — publishing who ships what on which lane is commercially
 *     sensitive to them, and an invitation to approach them off-platform.
 *
 * A carrier who signs in sees the rest through the authenticated board.
 *
 * @mixin \App\Models\FreightJob
 */
class PublicLoadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // A stable, opaque handle so the client can track rows across
            // pages without the real id being exposed.
            'ref' => 'FM-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT),
            'title' => $this->title,
            // Without the country: every load on this board is Australian,
            // and the suffix pushes the two place names far enough apart that
            // they stop reading as a pair. See Place.
            'pickup' => Place::short($this->pickup_location),
            'delivery' => Place::short($this->delivery_location),
            'category' => $this->load_category,
            'truck_type' => $this->trailer_type_required,
            'availability' => $this->availability?->label(),
            'pickup_date' => $this->pickup_date?->toDateString(),
            'weight_kg' => $this->weight_kg,
            'weight_tons' => $this->weightTons(),
            // Size matters to a carrier deciding whether it fits, and gives
            // away nothing about who is shipping it.
            'quantity' => $this->quantity,
            'dimensions_label' => $this->dimensionsLabel(),
            'quotes_count' => $this->quotes_count ?? 0,
            'posted_at' => ($this->relisted_at ?? $this->created_at)?->toIso8601String(),

            /*
             * The first photo, for the board card's thumbnail.
             *
             * One, not the gallery: a list of a hundred loads should not pull
             * down six images each, and the card has room for exactly one. The
             * rest are on the detail page.
             *
             * Null when the shipper attached none, which the card handles —
             * a placeholder tile is better than a row that changes height
             * depending on whether someone took a picture.
             */
            'thumbnail' => $this->firstImageUrl(),
        ];
    }
}
