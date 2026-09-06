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

            /*
             * The PayPal client id, for the Pay Later messaging component.
             *
             * A client id is public by design — it identifies the merchant to
             * PayPal's browser SDK and authorises nothing on its own. Served
             * from here rather than compiled into the bundle for the same
             * reason as the Maps key: `deploy/web` is committed to the
             * repository, so anything baked into a build is baked into git
             * history, and rotating it would mean a rebuild rather than an
             * env edit.
             *
             * Only sent when the PayPal gateway is actually the one in use —
             * messaging for a payment method that is not switched on would
             * advertise instalments the checkout cannot honour.
             */
            'paypal_client_id' => config('freightmove.subscriptions.gateway') === 'paypal'
                ? (config('services.paypal.client_id') ?: null)
                : null,

            // Sandbox messaging renders test content, so the client needs to
            // know which environment it is talking to.
            'paypal_mode' => config('services.paypal.mode', 'sandbox'),
        ]);
    }
}
