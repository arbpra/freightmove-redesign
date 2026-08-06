<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level role gate: 'role:admin' or 'role:shipper,admin'.
 *
 * Runs after Sanctum authentication, so an unauthenticated request is
 * rejected as 401 by auth:sanctum before it reaches this middleware.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            return ApiResponse::error('Unauthenticated.', status: 401);
        }

        if (! in_array($user->role->value, $roles, true)) {
            return ApiResponse::error(
                'This account does not have access to that area.',
                status: 403,
            );
        }

        return $next($request);
    }
}
