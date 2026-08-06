<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\Carrier;
use App\Models\User;
use App\Models\UserProfile;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * POST /api/v1/auth/register
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = DB::transaction(function () use ($data): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'phone' => $data['phone'] ?? null,
                'role' => $data['role'],
                'status' => UserStatus::Active,
            ]);

            // Every account gets a profile row so the dashboard never has to
            // handle a missing relation.
            UserProfile::create([
                'user_id' => $user->id,
                'company_name' => $data['company_name'] ?? null,
            ]);

            if ($user->role === UserRole::Carrier) {
                Carrier::create(['user_id' => $user->id]);
            }

            return $user;
        });

        $token = $user->createToken($this->deviceName($request))->plainTextToken;

        return ApiResponse::success([
            'token' => $token,
            'user' => new UserResource($user->load('profile')),
        ], 'Account created successfully.', 201);
    }

    /**
     * POST /api/v1/auth/login
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            // Same message either way, so the endpoint cannot be used to
            // discover which email addresses are registered.
            return ApiResponse::error('These credentials do not match our records.', [
                'email' => ['These credentials do not match our records.'],
            ], 422);
        }

        if (in_array($user->status, EnsureUserIsActive::BLOCKED_STATUSES, true)) {
            return ApiResponse::error(
                'This account is '.$user->status->value.'. Please contact support.',
                status: 403,
            );
        }

        $token = $user->createToken($this->deviceName($request))->plainTextToken;

        return ApiResponse::success([
            'token' => $token,
            'user' => new UserResource($user->load('profile')),
        ], 'Signed in successfully.');
    }

    /**
     * POST /api/v1/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::success(null, 'Signed out successfully.');
    }

    /**
     * GET /api/v1/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(
            new UserResource($request->user()->load('profile')),
        );
    }

    private function deviceName(Request $request): string
    {
        return $request->input('device_name') ?: 'web';
    }
}
