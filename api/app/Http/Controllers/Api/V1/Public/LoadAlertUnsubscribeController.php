<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Turning load alerts off, from the link inside one.
 *
 * Deliberately unauthenticated. Someone who wants these to stop is precisely
 * the person who will not sign in to make them stop — asking them to is how
 * "stop emailing me" becomes a spam complaint instead, which is scored against
 * the domain that also carries password resets and quote notifications.
 *
 * The signed URL is the authorisation: Laravel verifies the signature against
 * APP_KEY before this runs, so the id in the link cannot be swapped for
 * someone else's, and the link expires on its own.
 *
 * A GET that changes state is unusual and correct here — a mail client can only
 * follow a link. The blast radius is one boolean on one account, and the
 * carrier can turn it back on from their profile.
 */
class LoadAlertUnsubscribeController extends Controller
{
    /**
     * GET /api/v1/public/load-alerts/unsubscribe/{user}
     */
    public function __invoke(Request $request, User $user): RedirectResponse
    {
        $user->forceFill(['wants_load_alerts' => false])->save();

        $base = rtrim((string) config('freightmove.frontend_url'), '/');

        // Back into the app, so the confirmation is a real page rather than a
        // bare JSON body sitting in a browser tab.
        return redirect()->away("{$base}/carrier/profile?load_alerts=off");
    }
}
