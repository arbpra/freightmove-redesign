<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Response hardening headers.
 *
 * This is a JSON API, so the usual page-level defences do not all apply — but
 * these three do, and each closes a specific hole:
 *
 * - `nosniff` stops a browser second-guessing our Content-Type. A JSON response
 *   reflecting attacker-controlled text can otherwise be coaxed into executing
 *   as HTML.
 * - `DENY` framing removes clickjacking against any endpoint that ever renders
 *   markup (Laravel's error pages do).
 * - The restrictive CSP matters for the same reason: our JSON is not a document,
 *   so nothing should ever load or execute from it.
 *
 * HSTS is set only over HTTPS — sending it over plain HTTP is meaningless and,
 * on a shared host, can lock unrelated sibling sites out of HTTP.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-site');

        // `default-src 'none'` is right for JSON — nothing should ever load or
        // execute from it — but it would strip the styles off any HTML the app
        // serves, including Laravel's own error pages. So the strict policy is
        // scoped to the API and HTML keeps a policy it can actually live with.
        $response->headers->set(
            'Content-Security-Policy',
            $request->is('api/*')
                ? "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'"
                : "frame-ancestors 'none'; base-uri 'self'; object-src 'none'"
        );

        if ($request->secure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains'
            );
        }

        return $response;
    }
}
