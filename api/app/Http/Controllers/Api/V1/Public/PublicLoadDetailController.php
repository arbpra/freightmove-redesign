<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicLoadDetailResource;
use App\Models\FreightJob;
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

        $load = $id === null ? null : FreightJob::query()
            ->published()
            ->recent()
            ->withCount('quotes')
            ->with(['categories:id,name,slug', 'truckTypes:id,name,slug'])
            ->find($id);

        if (! $load) {
            // A load that has been filled, withdrawn or aged off the board is
            // a 404 rather than a 410: the distinction tells a stranger that
            // the reference was real, which is the one thing worth not
            // confirming when the id is meant to be opaque.
            return ApiResponse::error('That load is no longer on the board.', status: 404);
        }

        /*
         * Optional authentication. The route is public, so there is no
         * middleware to resolve a user — but a signed-in carrier's client
         * sends its token anyway, and it costs nothing to honour it. A guest
         * simply resolves to null and gets the narrower payload.
         */
        $viewer = $request->user('sanctum');

        return ApiResponse::success(
            new PublicLoadDetailResource($load->setAttribute('viewer', $viewer))
        );
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
