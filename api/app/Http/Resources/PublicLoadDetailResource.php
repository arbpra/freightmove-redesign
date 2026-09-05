<?php

namespace App\Http\Resources;

use App\Enums\UserRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One load, as the person asking is allowed to see it.
 *
 * The board's `PublicLoadResource` decides what a guest may see in a *row*;
 * this decides what they may see on a *page*, and the answer is almost the
 * same. A detail view is not a licence to publish more — it is the same facts
 * with room to lay them out.
 *
 * Two fields are held back from signed-out visitors and released to a carrier
 * with an account:
 *
 *   - `description` — free text, and therefore where site contacts, gate
 *     codes, mobile numbers and "ask for Dave" reliably end up. Publishing it
 *     to anyone who finds the URL is how the marketplace gets disintermediated
 *     by a search engine.
 *   - `budget` — the shipper's negotiating position. A carrier who has signed
 *     up is inside the marketplace and already sees it on the board; a
 *     stranger is not.
 *
 * The shipper is withheld from everybody here, signed in or not. Who ships
 * what on which lane is commercially sensitive to them, and the identity is
 * released only once a quote is accepted and the two sides are working
 * together — see docs/10-domain-rules.md.
 *
 * @mixin \App\Models\FreightJob
 */
class PublicLoadDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $this->viewer ?? null;

        // A carrier or an admin. A shipper browsing the board is still a
        // customer, not a competitor, so they see the carrier's view too.
        $inside = $viewer !== null && in_array(
            $viewer->role,
            [UserRole::Carrier, UserRole::Shipper, UserRole::Admin],
            true,
        );

        return [
            'ref' => 'FM-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT),
            'title' => $this->title,

            'pickup' => $this->pickup_location,
            'delivery' => $this->delivery_location,
            'pickup_date' => $this->pickup_date?->toDateString(),
            'delivery_date' => $this->delivery_date?->toDateString(),
            'availability' => $this->availability?->label(),

            'category' => $this->load_category,
            'categories' => $this->whenLoaded('categories', fn () => $this->categories
                ->map(fn ($c) => ['name' => $c->name, 'slug' => $c->slug])->all()),
            'truck_type' => $this->trailer_type_required,
            'truck_types' => $this->whenLoaded('truckTypes', fn () => $this->truckTypes
                ->map(fn ($t) => ['name' => $t->name, 'slug' => $t->slug])->all()),
            'vehicle_type' => $this->vehicle_type_required,

            'quantity' => $this->quantity,
            'length_mm' => $this->length_mm,
            'width_mm' => $this->width_mm,
            'height_mm' => $this->height_mm,
            'dimensions_label' => $this->dimensionsLabel(),
            'weight_kg' => $this->weight_kg,
            'weight_tons' => $this->weightTons(),

            // Photos are already public on the board — a shipper uploading one
            // is publishing it to carriers, and SVG is refused for exactly
            // this reason (config/freightmove.php).
            'images' => array_values(array_map(
                fn (array $image) => $image['url'] ?? null,
                $this->imageList(),
            )),

            'quotes_count' => $this->quotes_count ?? 0,
            'posted_at' => ($this->relisted_at ?? $this->created_at)?->toIso8601String(),

            // Withheld from strangers. See the class docblock.
            'description' => $inside ? $this->description : null,
            'budget_min' => $inside && $this->budget_min !== null ? (float) $this->budget_min : null,
            'budget_max' => $inside && $this->budget_max !== null ? (float) $this->budget_max : null,

            // So the client can say "sign in to see the full brief" rather
            // than silently rendering a page with holes in it.
            'is_restricted' => ! $inside,
        ];
    }
}
