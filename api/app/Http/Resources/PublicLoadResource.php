<?php

namespace App\Http\Resources;

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
            'pickup' => $this->pickup_location,
            'delivery' => $this->delivery_location,
            'category' => $this->load_category,
            'truck_type' => $this->trailer_type_required,
            'availability' => $this->availability?->label(),
            'pickup_date' => $this->pickup_date?->toDateString(),
            'weight_tons' => $this->weight_tons !== null ? (float) $this->weight_tons : null,
            'quotes_count' => $this->quotes_count ?? 0,
            'posted_at' => ($this->relisted_at ?? $this->created_at)?->toIso8601String(),
        ];
    }
}
