<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\FreightJob
 */
class FreightJobResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'pickup_location' => $this->pickup_location,
            'delivery_location' => $this->delivery_location,
            'pickup_date' => $this->pickup_date?->toDateString(),
            'delivery_date' => $this->delivery_date?->toDateString(),
            'availability' => $this->availability?->value,
            'availability_label' => $this->availability?->label(),
            'load_category' => $this->load_category,
            // Full lists from the pivots; the singular fields above are the
            // denormalised primary value used for compact list rows.
            'categories' => $this->whenLoaded('categories', fn () => $this->categories
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'slug' => $c->slug])->all()),
            'truck_types' => $this->whenLoaded('truckTypes', fn () => $this->truckTypes
                ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'slug' => $t->slug])->all()),
            'quantity' => $this->quantity,
            'length_mm' => $this->length_mm,
            'width_mm' => $this->width_mm,
            'height_mm' => $this->height_mm,
            'dimensions_label' => $this->dimensionsLabel(),
            'weight_kg' => $this->weight_kg,
            // Derived, not stored. Kept in the payload because the board and
            // the public cards read tonnes; kilograms are the stored fact.
            'weight_tons' => $this->weightTons(),
            'vehicle_type_required' => $this->vehicle_type_required,
            'trailer_type_required' => $this->trailer_type_required,
            'budget_min' => $this->budget_min !== null ? (float) $this->budget_min : null,
            'budget_max' => $this->budget_max !== null ? (float) $this->budget_max : null,
            'status' => $this->status->value,
            'visibility' => $this->visibility,
            'images' => $this->images_json ?? [],
            // Counted rather than loaded: the list only needs the number, and
            // loading every quote per row would be an N+1 waiting to happen.
            'quotes_count' => $this->whenCounted('quotes'),
            // Set by the carrier board's withExists so the client can mark
            // loads this carrier has already priced without a second call.
            'quoted_by_me' => $this->when(
                isset($this->quoted_by_me),
                fn () => (bool) $this->quoted_by_me,
            ),
            // When the shipper last bumped it. The board sorts on
            // COALESCE(relisted_at, created_at), so this is the load's
            // effective age as carriers see it.
            'relisted_at' => $this->relisted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
