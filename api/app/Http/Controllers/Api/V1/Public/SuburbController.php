<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\Suburb;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Suburb autocomplete over the 15,329 rows carried across from the legacy
 * `suburb_master` table.
 *
 * Public because the marketing quote form asks for pickup and delivery before
 * anyone signs in. It exposes nothing but place names, which are already public
 * knowledge, and it is rate limited like the other unauthenticated endpoints.
 */
class SuburbController extends Controller
{
    /** Enough to fill a dropdown; a longer list is a sign the query is too vague. */
    private const LIMIT = 15;

    /**
     * GET /api/v1/public/suburbs?q=&state=
     */
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'state' => ['nullable', 'string', 'max:10'],
        ]);

        // `%` and `_` are wildcards in LIKE, so a search for "%" would
        // otherwise match every row in the table.
        $term = addcslashes($validated['q'], '%_\\');

        $suburbs = Suburb::query()
            ->where('name', 'like', "%{$term}%")
            ->when(
                $validated['state'] ?? null,
                fn ($query, string $state) => $query->where('state', strtoupper($state))
            )
            // Names that start with what was typed come first — someone typing
            // "port" wants Port Macquarie before Newport.
            ->orderByRaw('CASE WHEN name LIKE ? THEN 0 ELSE 1 END', ["{$term}%"])
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get(['id', 'name', 'state']);

        return ApiResponse::success([
            'items' => $suburbs->map(fn (Suburb $suburb) => [
                'id' => $suburb->id,
                'name' => $suburb->name,
                'state' => $suburb->state,
                'label' => $suburb->label(),
            ])->all(),
        ]);
    }
}
