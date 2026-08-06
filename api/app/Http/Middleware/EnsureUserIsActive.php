<?php

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks suspended and blocked accounts that still hold a valid token.
 *
 * Login refuses these accounts too, but a token issued before an admin
 * suspended the account would otherwise keep working until it expired.
 *
 * Pending accounts are deliberately allowed through: a carrier has to sign in
 * before it can upload the documents that get it verified.
 */
class EnsureUserIsActive
{
    /** @var list<UserStatus> */
    public const BLOCKED_STATUSES = [UserStatus::Suspended, UserStatus::Blocked];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && in_array($user->status, self::BLOCKED_STATUSES, true)) {
            $user->currentAccessToken()?->delete();

            return ApiResponse::error(
                'This account is '.$user->status->value.'. Please contact support.',
                status: 403,
            );
        }

        return $next($request);
    }
}
