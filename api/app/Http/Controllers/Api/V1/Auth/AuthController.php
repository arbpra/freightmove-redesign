<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\Carrier;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\SubscriptionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

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
                // Chosen here and now, so this account is never shown the
                // legacy password-migration prompt.
                'password_changed_at' => now(),
            ]);

            // Every account gets a profile row so the dashboard never has to
            // handle a missing relation.
            UserProfile::create([
                'user_id' => $user->id,
                'company_name' => $data['company_name'] ?? null,
            ]);

            if ($user->role === UserRole::Carrier) {
                Carrier::create(['user_id' => $user->id]);
                $this->applyChosenPlan($user, $data['subscription_plan']);
            }

            return $user;
        });

        $token = $user->createToken($this->deviceName($request))->plainTextToken;

        return ApiResponse::success([
            'token' => $token,
            'user' => new UserResource($user->load('profile')),
        ], $this->welcomeMessage($user), 201);
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

        $this->upgradeHashIfStale($user, $request->validated('password'));

        $token = $user->createToken($this->deviceName($request))->plainTextToken;

        return ApiResponse::success([
            'token' => $token,
            'user' => new UserResource($user->load('profile')),
        ], 'Signed in successfully.');
    }

    /**
     * Re-hashes a password at the current cost once its owner signs in.
     *
     * Accounts imported from the pre-launch site arrived with bcrypt cost 10
     * hashes (see docs/09-legacy-data-migration.md); this application is
     * configured for cost 12. Those hashes are deliberately left untouched at
     * import time so every existing customer can still sign in with the
     * password they already have.
     *
     * A successful `Hash::check` a moment earlier is the only point where the
     * plaintext is available, so it is also the only point where the stored
     * hash can be strengthened. It happens once per account, silently, and a
     * failure must never block a valid sign-in — the old hash still verifies.
     */
    private function upgradeHashIfStale(User $user, string $plainPassword): void
    {
        if (! Hash::needsRehash($user->password)) {
            return;
        }

        try {
            // forceFill with an already-hashed value rather than assigning the
            // plaintext: the `hashed` cast would otherwise decide for itself
            // whether to hash, and a password that happens to look like a hash
            // would be stored in the clear.
            $user->forceFill(['password' => Hash::make($plainPassword)])->save();
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * PUT /api/v1/auth/password
     *
     * Used by the migration prompt: accounts brought over from the pre-launch
     * site sign in with their existing password, then choose a new one that
     * meets the current policy.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        // Only a real bearer token has an id to spare. Session-guard callers get
        // a TransientToken, which has none — there every token is "other".
        $current = $user->currentAccessToken();
        $currentId = $current instanceof PersonalAccessToken ? $current->getKey() : null;

        $user->forceFill([
            'password' => Hash::make($request->validated('password')),
            'password_changed_at' => now(),
        ])->save();

        // Revoke every other session: if the old password was known to anyone
        // else, changing it has to end their access too. The token in play stays
        // valid so the person doing this is not signed out mid-flow.
        $user->tokens()
            ->when($currentId, fn ($query) => $query->whereKeyNot($currentId))
            ->delete();

        return ApiResponse::success(
            ['user' => new UserResource($user->fresh()->load('profile'))],
            'Password updated. Any other devices have been signed out.',
        );
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

    /**
     * Puts the plan a carrier chose at sign-up onto their new account.
     *
     * The free trial starts immediately — there is nothing to pay. A paid plan
     * is **reserved pending payment** and grants nothing until the money is
     * confirmed, exactly as it does from the subscription page. Signing up is
     * not a payment event, and treating it as one would hand out the paid
     * product to anyone who filled in a form.
     *
     * Runs inside the registration transaction: the plan was validated before
     * anything was written, so the realistic failures are already excluded, and
     * an account created without the plan it asked for is worse than a failed
     * sign-up someone can retry.
     */
    private function applyChosenPlan(User $user, string $planCode): void
    {
        $subscriptions = app(SubscriptionService::class);

        if ($planCode === 'trial') {
            $subscriptions->startTrial($user);

            return;
        }

        $subscriptions->beginCheckout(
            $user,
            SubscriptionPlan::where('code', $planCode)->firstOrFail(),
        );
    }

    /** Tells a new carrier what actually happened to the plan they picked. */
    private function welcomeMessage(User $user): string
    {
        if ($user->role !== UserRole::Carrier) {
            return 'Account created successfully.';
        }

        $subscription = app(SubscriptionService::class)->current($user);

        if ($subscription?->plan?->is_trial) {
            return 'Account created. Your free trial is running until '
                .$subscription->ends_on?->format('j F Y').'.';
        }

        return 'Account created. Your plan is reserved — we will switch it on once payment is confirmed.';
    }
}
