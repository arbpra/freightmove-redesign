<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\RouteDistance;
use App\Models\Suburb;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Driving distance between two suburbs, served from the cache built up by the
 * previous site (docs/10-domain-rules.md R6).
 *
 * A miss answers 200 with `cached: false` rather than 404: the caller is a form
 * showing an optional "about 3,616 km" hint, and a missing hint is not an
 * error. Nothing here calls Google — populating a miss needs a Distance Matrix
 * key, and the legacy key is still to be rotated (docs/11-security.md).
 */
class RouteDistanceController extends Controller
{
    /**
     * GET /api/v1/public/routes/{pickup}/{dropoff}
     *
     * Both parameters are suburb ids, resolved through GET /public/suburbs.
     */
    public function __invoke(Suburb $pickup, Suburb $dropoff): JsonResponse
    {
        // Directional on purpose. The legacy cache holds both directions
        // separately for 20 pairs and the figures differ, so B->A is not
        // treated as an answer for A->B.
        $route = RouteDistance::query()
            ->where('pickup_suburb_id', $pickup->id)
            ->where('dropoff_suburb_id', $dropoff->id)
            ->first();

        if (! $route) {
            return ApiResponse::success([
                'cached' => false,
                'pickup' => $pickup->label(),
                'dropoff' => $dropoff->label(),
            ], 'No cached distance for this lane yet.');
        }

        $route->recordHit();

        return ApiResponse::success([
            'cached' => true,
            'pickup' => $pickup->label(),
            'dropoff' => $dropoff->label(),
            'distance_metres' => $route->distance_metres,
            'distance_km' => $route->distance_metres !== null
                ? round($route->distance_metres / 1000, 1)
                : null,
            'duration_seconds' => $route->duration_seconds,
            // Google's own wording, which is also an honest record of the
            // precision: "3,616 km" was rounded before we ever stored it.
            'distance_text' => $route->distance_text,
            'duration_text' => $route->duration_text,
        ]);
    }
}
