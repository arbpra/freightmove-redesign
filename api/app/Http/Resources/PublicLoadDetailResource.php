<?php

namespace App\Http\Resources;

use App\Enums\UserRole;
use App\Models\User;
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
 * The shipper is released to a carrier, and to nobody else. Whether that
 * carrier must also hold a **current subscription** is a setting —
 * `shipper_contacts.require_subscription`, currently OFF, so any signed-in
 * carrier sees the details. Turned on, it is what the subscription buys.
 *
 * Signing in is always required regardless. A guest never sees a shipper under
 * either setting, because the free-access decision is about what carriers get
 * for nothing, not about publishing contact details to the open web.
 *
 * Worth being clear that with the setting ON this is stricter than the site it
 * replaces, and with it OFF it matches it. The
 * previous `load-details.blade.php` gated its whole Shipper Information block
 * on `if ($session_id != '')` — merely being signed in — so any registered
 * account could read a shipper's name, phone, email and street address without
 * paying anything. Tying it to a live subscription keeps the capability and
 * gives it a price.
 *
 * The trade is real either way: a carrier holding the shipper's number can
 * take the next job off-platform. The subscription is the answer to that
 * rather than a defence against it, which is why the release is checked per
 * request against `Subscription::scopeCurrent` and never cached on the row.
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

            // The subscription's payload. Null for everyone else.
            'shipper' => $this->shipperFor($viewer),

            // So the client can say "sign in to see the full brief" rather
            // than silently rendering a page with holes in it.
            'is_restricted' => ! $inside,

            /*
             * Why the shipper block is null, so the page can say something
             * useful rather than showing an empty panel:
             *
             *   guest      not signed in at all
             *   subscribe  signed in, but no current subscription
             *   null       released — the block above is populated
             */
            'shipper_locked' => $this->lockReason($viewer),

            /*
             * Whether a subscription is what stands between this viewer and
             * the shipper, or merely an account. The guest lock is shown in
             * both modes, and it should not promise a paywall that is not
             * currently switched on.
             */
            'shipper_requires_subscription' => (bool) config('freightmove.shipper_contacts.require_subscription'),
        ];
    }

    /**
     * The shipper, if this viewer has paid to see them.
     *
     * `hasActiveSubscription()` delegates to `Subscription::scopeCurrent`, so a
     * pending period — a plan reserved and never paid for — does not qualify.
     * That distinction matters more here than anywhere: without it a carrier
     * holds the paid product indefinitely by choosing a plan and stopping.
     *
     * The shipper themselves and an admin are included, because withholding a
     * shipper's own details from them would be absurd.
     *
     * @return array<string, mixed>|null
     */
    private function shipperFor(?User $viewer): ?array
    {
        $shipper = $this->canSeeShipper($viewer) ? $this->shipper : null;

        if (! $shipper) {
            return null;
        }

        $profile = $shipper->profile;

        return [
            'name' => $profile?->company_name ?: $shipper->name,
            'contact_name' => $shipper->name,
            'email' => $shipper->email,
            'phone' => $shipper->phone,
            'location' => trim(implode(' ', array_filter([$profile?->city, $profile?->state]))) ?: null,
            'member_since' => $shipper->created_at?->toDateString(),
        ];
    }

    private function canSeeShipper(?User $viewer): bool
    {
        if ($viewer === null) {
            return false;
        }

        if ($viewer->role === UserRole::Admin || $viewer->id === $this->shipper_id) {
            return true;
        }

        if ($viewer->role !== UserRole::Carrier) {
            return false;
        }

        // Free for every signed-in carrier while this is off, which is the
        // current state. See config/freightmove.php for what has to be true
        // before turning it on.
        if (! config('freightmove.shipper_contacts.require_subscription')) {
            return true;
        }

        return $viewer->hasActiveSubscription();
    }

    /** Null once released; otherwise why, so the page can explain itself. */
    private function lockReason(?User $viewer): ?string
    {
        if ($this->canSeeShipper($viewer)) {
            return null;
        }

        return $viewer === null ? 'guest' : 'subscribe';
    }
}
