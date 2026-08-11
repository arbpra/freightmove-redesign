<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicLoadResource;
use App\Models\FreightJob;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A public teaser of the loads currently on the board.
 *
 * The home page used to show a quote form that a guest could not actually
 * submit — it collected a lane and then sent them to registration, dropping
 * what they had typed. Real freight, visible without an account, is a more
 * honest thing to put there and a better argument for a carrier to sign up.
 *
 * **What a guest may see is deliberately narrow.** Enough to show the
 * marketplace is alive — lane, freight type, how recently it was posted — and
 * nothing that would let someone work the job without joining:
 *
 *   - no budget. It is the shipper's negotiating position.
 *   - no description. That is where site contacts and gate codes end up.
 *   - no shipper identity. Publishing who is shipping what, on which lane, is
 *     commercially sensitive to them and an invitation to approach them
 *     directly — the disintermediation the whole platform charges to prevent.
 *
 * Only `public` loads that are open for quotes appear, and the same recency
 * window the carrier board uses applies, so the page never advertises freight
 * that has gone stale.
 */
class RecentLoadController extends Controller
{
    private const DEFAULT_LIMIT = 5;

    private const MAX_LIMIT = 10;

    /**
     * GET /api/v1/public/loads/recent
     */
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ]);

        $loads = FreightJob::query()
            ->published()
            ->recent()
            ->boardOrder()
            ->withCount('quotes')
            ->limit($validated['limit'] ?? self::DEFAULT_LIMIT)
            ->get();

        return ApiResponse::success([
            // Shared with the full board, so what a guest may see is defined
            // once rather than in two places that can drift.
            'items' => PublicLoadResource::collection($loads),
            // The honest headline number, not a marketing figure: how many
            // loads are actually open right now.
            'open_total' => FreightJob::published()->recent()->count(),
        ]);
    }
}