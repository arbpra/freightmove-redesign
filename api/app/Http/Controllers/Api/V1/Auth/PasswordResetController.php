<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class PasswordResetController extends Controller
{
    /**
     * POST /api/v1/auth/forgot-password
     */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $status = Password::sendResetLink($request->only('email'));

        // Always report success: whether an address is registered is not
        // something an unauthenticated caller should be able to probe.
        if ($status === Password::RESET_LINK_SENT || $status === Password::INVALID_USER) {
            return ApiResponse::success(null, 'If that email is registered, a reset link is on its way.');
        }

        return ApiResponse::error(__($status), status: 422);
    }

    /**
     * POST /api/v1/auth/reset-password
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                // Any session opened with the old password is now void.
                $user->tokens()->delete();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return ApiResponse::success(null, 'Password reset successfully. Please sign in.');
        }

        return ApiResponse::error(__($status), ['email' => [__($status)]], 422);
    }
}
