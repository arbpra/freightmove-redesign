<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicLoadResource;
use App\Models\Category;
use App\Models\FreightJob;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The public load board — every load currently open for quotes.
 *
 * Open to anyone, because a carrier deciding whether to subscribe should be
 * able to see whether there is freight on their lanes first. Quoting is what
 * needs an account; looking does not.
 *
 * What each row shows is PublicLoadResource's decision, shared with the home
 * page teaser so the two cannot drift apart.
 */
class PublicLoadBoardController extends Controller
{
    private const MAX_PER_PAGE = 50;

    /**
     * GET /api/v1/public/loads
     */
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ]);

        $loads = FreightJob::query()
            ->published()
            ->recent()
            ->withCount('quotes')
            ->when($validated['category'] ?? null, fn ($q, $c) => $q->where('load_category', $c))
            ->when($validated['search'] ?? null, function ($query, string $term) {
                // `%` and `_` are LIKE wildcards; unescaped, a search for "%"
                // would return the whole board in one query.
                $escaped = addcslashes($term, '%_\\');

                $query->where(function ($inner) use ($escaped) {
                    $inner->where('title', 'like', "%{$escaped}%")
                        ->orWhere('pickup_location', 'like', "%{$escaped}%")
                        ->orWhere('delivery_location', 'like', "%{$escaped}%");
                });
            })
            ->boardOrder()
            ->paginate($validated['per_page'] ?? 20)
            ->withQueryString();

        return ApiResponse::success([
            'items' => PublicLoadResource::collection($loads->items()),
            'meta' => [
                'current_page' => $loads->currentPage(),
                'last_page' => $loads->lastPage(),
                'per_page' => $loads->perPage(),
                'total' => $loads->total(),
            ],
            // Only the categories that actually have open freight against them,
            // so the filter never offers a choice that returns nothing.
            'categories' => FreightJob::query()
                ->published()
                ->recent()
                ->whereNotNull('load_category')
                ->distinct()
                ->orderBy('load_category')
                ->pluck('load_category'),
            // Lets the client say "sign in to quote" or "subscribe to quote"
            // accurately, rather than guessing at the rule.
            'quoting' => [
                'requires_subscription' => (bool) config('freightmove.quoting.require_subscription'),
                'requires_verification' => (bool) config('freightmove.verification.require_to_quote'),
            ],
        ]);
    }
}
