<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Settings the browser needs that are not worth a rebuild to change.
 *
 * The Google Places key is served from here rather than compiled into the
 * Angular bundle, for one specific reason: **the built bundle is committed**.
 * SiteGround has no Node runtime, so `deploy/web` lives in the repository —
 * which means anything baked into the build lands in git, in every clone and
 * fork, and trips GitHub's secret scanning. Serving it keeps the key in one
 * place, the server's `.env`, where it is already ignored.
 *
 * A Maps key is public by necessity: it has to reach the browser to work, and
 * anyone can read it out of a network request. That is fine and expected. What
 * protects it is the HTTP-referrer restriction on the key itself, not secrecy
 * (docs/11-security.md §5a). This endpoint therefore returns nothing that is
 * not already public by design — and nothing else should be added to it.
 *
 * The side benefit: rotating the key is an `.env` edit and a `config:cache`,
 * not a rebuild and a redeploy of the front end.
 */
class ClientConfigController extends Controller
{
    /**
     * GET /api/v1/public/config
     */
    public function __invoke(): JsonResponse
    {
        return ApiResponse::success([
            // Null rather than an empty string when unset, so the client can
            // tell "not configured" from "configured as blank" without
            // guessing. Either way it falls back to a plain text input.
            'google_maps_key' => config('freightmove.google_maps_key') ?: null,
        ]);
    }
}
