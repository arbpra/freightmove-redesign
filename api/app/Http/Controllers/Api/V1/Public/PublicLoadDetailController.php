<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\PublicLoadDetailResource;
use App\Models\FreightJob;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One load, in full.
 *
 * Addressed by the opaque reference the board hands out (`FM-000443`) rather
 * than the primary key. That is not obfuscation for its own sake: the board
 * deliberately withholds `id`, and a detail route keyed on `/loads/443` would
 * hand it straight back — along with an invitation to walk the whole table by
 * incrementing it.
 *
 * Open to anyone, for the same reason the board is: a carrier deciding whether
 * to subscribe should be able to see the freight first. What a signed-out
 * visitor may see is `PublicLoadDetailResource`'s decision, and it is stricter
 * than what a carrier sees — the description and the budget are withheld until
 * there is an account behind the request.
 */
class PublicLoadDetailController extends Controller
{
    /**
     * GET /api/v1/public/loads/{ref}
     */
    public function __invoke(Request $request, string $ref): JsonResponse
    {
        $id = $this->idFrom($ref);

        /*
         * Resolved before the visibility scopes, not after.
         *
         * `published()` requires an open status and public visibility, and
         * `recent()` cuts off at the board's recency window — so applying them
         * first would 404 a shipper's own draft, their completed job, and
         * anything they posted three weeks ago. The owner and an admin are not
         * browsing the board; they are looking at a record.
         */
        $load = $id === null ? null : FreightJob::query()
            ->withCount('quotes')
            // The shipper is loaded but not necessarily published — the
            // resource decides that per viewer. Eager-loaded so the check
            // costs nothing when it does resolve.
            ->with([
                'categories:id,name,slug',
                'truckTypes:id,name,slug',
                'shipper:id,name,email,phone,created_at',
                'shipper.profile:id,user_id,company_name,city,state',
            ])
            ->find($id);

        /*
         * Optional authentication. The route is public, so no middleware
         * resolves a user — but a signed-in client sends its token anyway, and
         * honouring it is what lets one page serve a guest, a carrier, the
         * shipper who posted the load and an admin. A guest resolves to null
         * and gets the narrowest payload.
         */
        $viewer = $request->user('sanctum');

        // Everyone else still sees only what the board publishes.
        if ($load && ! $this->isOwnerOrAdmin($load, $viewer)) {
            $onBoard = FreightJob::query()->published()->recent()
                ->whereKey($load->id)->exists();

            if (! $onBoard) {
                $load = null;
            }
        }

        if (! $load) {
            // A load that has been filled, withdrawn or aged off the board is
            // a 404 rather than a 410: the distinction tells a stranger that
            // the reference was real, which is the one thing worth not
            // confirming when the id is meant to be opaque.
            return ApiResponse::error('That load is no longer on the board.', status: 404);
        }

        return ApiResponse::success(
            new PublicLoadDetailResource($load->setAttribute('viewer', $viewer))
        );
    }

    /**
     * The load's own shipper, or an admin.
     *
     * These two are looking at a record rather than browsing the board, so
     * neither the open-for-quotes status nor the recency window applies.
     */
    private function isOwnerOrAdmin(FreightJob $load, ?User $viewer): bool
    {
        if ($viewer === null) {
            return false;
        }

        return $viewer->role === UserRole::Admin || $viewer->id === $load->shipper_id;
    }

    /**
     * `FM-000443` -> 443. Also accepts a bare number, so a link that has lost
     * its prefix in a copy-paste still resolves.
     */
    private function idFrom(string $ref): ?int
    {
        $digits = ltrim(preg_replace('/\D/', '', $ref), '0');

        return $digits === '' ? null : (int) $digits;
    }
}
