<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SetUserPasswordRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Admin oversight of accounts.
 *
 * Two things are deliberately **not** here.
 *
 * **Role changes.** Nothing in this controller can turn a shipper into an
 * admin. Role is the privilege boundary the whole authorisation layer rests on
 * (docs/11-security.md section 3), and an "edit user" form that happens to
 * include a role dropdown is how that boundary gets crossed by accident. If
 * granting admin is ever needed, it should be a separate, audited action.
 *
 * **Deletion.** Accounts carry loads, quotes and acceptances that other people
 * depend on. Suspending stops someone using the marketplace without erasing
 * what the other side of their transactions relies on.
 */
class UserController extends Controller
{
    private const MAX_PER_PAGE = 100;

    /**
     * GET /api/v1/admin/users
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'role' => ['nullable', Rule::in(UserRole::values())],
            'status' => ['nullable', Rule::in(UserStatus::values())],
            'search' => ['nullable', 'string', 'max:255'],
            'legacy' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ]);

        $users = User::query()
            ->with('profile:id,user_id,company_name,verification_status')
            ->withCount(['freightJobs', 'quotes'])
            ->when($validated['role'] ?? null, fn ($q, $role) => $q->where('role', $role))
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            // Whether an account came across from the old site, which is the
            // question that matters most during cut-over.
            ->when(
                array_key_exists('legacy', $validated) && $validated['legacy'] !== null,
                fn ($q) => $validated['legacy']
                    ? $q->whereNotNull('legacy_id')
                    : $q->whereNull('legacy_id'),
            )
            ->when($validated['search'] ?? null, function ($query, string $term) {
                $escaped = addcslashes($term, '%_\\');

                $query->where(function ($inner) use ($escaped) {
                    $inner->where('name', 'like', "%{$escaped}%")
                        ->orWhere('email', 'like', "%{$escaped}%")
                        ->orWhereHas(
                            'profile',
                            fn ($p) => $p->where('company_name', 'like', "%{$escaped}%")
                        );
                });
            })
            ->latest()
            ->paginate($validated['per_page'] ?? 25)
            ->withQueryString();

        return ApiResponse::success([
            'items' => array_map(fn (User $user) => $this->present($user), $users->items()),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    /**
     * POST /api/v1/admin/users/{user}/status
     *
     * Suspends or reinstates an account.
     */
    public function setStatus(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in([
                UserStatus::Active->value,
                UserStatus::Suspended->value,
                UserStatus::Blocked->value,
            ])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        // Locking yourself out is not a recoverable mistake from inside the
        // application, so it is refused rather than confirmed.
        if ($user->id === $request->user()->id) {
            return ApiResponse::error('You cannot change your own account status.', status: 422);
        }

        // Nor can one admin suspend another: it would let a compromised admin
        // account disable everyone who could stop it.
        if ($user->isAdmin()) {
            return ApiResponse::error('Admin accounts cannot be suspended from here.', status: 422);
        }

        $status = UserStatus::from($validated['status']);
        $user->forceFill(['status' => $status])->save();

        // A suspended account keeps no live sessions — otherwise the token in
        // their browser goes on working until it expires.
        if ($status !== UserStatus::Active) {
            $user->tokens()->delete();
        }

        return ApiResponse::success(
            $this->present($user->fresh()->load('profile')),
            $status === UserStatus::Active
                ? 'Account reinstated.'
                : 'Account suspended and signed out everywhere.',
        );
    }

    /**
     * GET /api/v1/admin/users/{user}
     *
     * One account in full, for the support call that starts "can you check
     * what's on my profile".
     */
    public function show(User $user): JsonResponse
    {
        $user->loadCount(['freightJobs', 'quotes'])->load('profile');

        return ApiResponse::success($this->presentDetail($user));
    }

    /**
     * PATCH /api/v1/admin/users/{user}
     *
     * Contact and profile details. Not role — see the class docblock — and not
     * status, which has its own endpoint and its own refusals.
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $validated = $request->validated();
        $profile = $validated['profile'] ?? null;
        unset($validated['profile']);

        // Changing another admin's details is not support, it is one admin
        // editing the people who can hold them to account. Same boundary as
        // setStatus draws.
        if ($user->isAdmin() && $user->id !== $request->user()->id) {
            return ApiResponse::error('Another admin\'s account cannot be edited from here.', status: 422);
        }

        $before = $user->only(['name', 'email', 'phone']);

        if ($validated !== []) {
            $user->fill($validated)->save();
        }

        if ($profile) {
            $user->profile()->updateOrCreate(['user_id' => $user->id], $profile);
        }

        // Email is the login identity, so a change to it is worth being able
        // to trace back afterwards.
        if (($validated['email'] ?? $before['email']) !== $before['email']) {
            Log::notice('An admin changed an account email.', [
                'admin' => $request->user()->id,
                'user' => $user->id,
                'from' => $before['email'],
                'to' => $user->email,
            ]);
        }

        return ApiResponse::success(
            $this->presentDetail($user->fresh()->loadCount(['freightJobs', 'quotes'])->load('profile')),
            'Account updated.',
        );
    }

    /**
     * POST /api/v1/admin/users/{user}/password
     *
     * The most powerful action here: it hands over an account, private
     * conversations included. Hence the refusals, the forced sign-out and the
     * log line.
     */
    public function setPassword(SetUserPasswordRequest $request, User $user): JsonResponse
    {
        // Your own password goes through the normal change flow, which asks
        // for the current one. This endpoint deliberately does not.
        if ($user->id === $request->user()->id) {
            return ApiResponse::error(
                'Use the change-password form for your own account.',
                status: 422,
            );
        }

        // One admin must not be able to take over another. A single
        // compromised admin account would otherwise be every admin account.
        if ($user->isAdmin()) {
            return ApiResponse::error('An admin password cannot be set from here.', status: 422);
        }

        $user->forceFill([
            'password' => $request->validated('password'),
            'password_changed_at' => now(),
        ])->save();

        $revoke = $request->boolean('revoke_sessions', true);

        if ($revoke) {
            // Otherwise whoever currently holds a token keeps the account,
            // which is usually the reason the reset was asked for.
            $user->tokens()->delete();
        }

        Log::warning('An admin set another account password.', [
            'admin' => $request->user()->id,
            'user' => $user->id,
            'revoked_sessions' => $revoke,
            'reason' => $request->validated('reason'),
        ]);

        return ApiResponse::success(
            $this->presentDetail($user->fresh()->loadCount(['freightJobs', 'quotes'])->load('profile')),
            $revoke
                ? 'Password set, and the account signed out everywhere.'
                : 'Password set.',
        );
    }

    /**
     * One account in full.
     *
     * Everything the list gives, plus the fields support is asked about. The
     * password hash is not among them and never will be: there is nothing an
     * admin can do with it that setting a new one does not do better.
     *
     * @return array<string, mixed>
     */
    private function presentDetail(User $user): array
    {
        return $this->present($user) + [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'wants_load_alerts' => (bool) $user->wants_load_alerts,
            'email_verified' => $user->email_verified_at !== null,
            // No last_login_at: the column does not exist, and a field that is
            // permanently null reads as a broken feature rather than a missing
            // one.
            'password_changed_at' => $user->password_changed_at?->toIso8601String(),
            'profile' => [
                'company_name' => $user->profile?->company_name,
                'abn_acn' => $user->profile?->abn_acn,
                'address_line_1' => $user->profile?->address_line_1,
                'address_line_2' => $user->profile?->address_line_2,
                'city' => $user->profile?->city,
                'state' => $user->profile?->state,
                'postal_code' => $user->profile?->postal_code,
                'bio' => $user->profile?->bio,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role->value,
            'status' => $user->status->value,
            'company_name' => $user->profile?->company_name,
            'verification_status' => $user->profile?->verification_status?->value,
            // Distinguishes a migrated account from one created here, which is
            // the question that matters most during cut-over.
            'is_legacy' => $user->legacy_id !== null,
            'jobs_count' => $user->freight_jobs_count ?? 0,
            'quotes_count' => $user->quotes_count ?? 0,
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
