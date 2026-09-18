<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * An admin setting a new password on someone else's account.
 *
 * The support case this exists for: a carrier who has lost access to the email
 * address their account was registered with cannot use the reset link, because
 * the reset link goes to that address.
 *
 * It is also the most powerful single action in the admin console — it hands
 * over an account, including its private conversations — so the controller
 * wraps it in refusals rather than trusting the form, and every use is logged.
 *
 * `Password::defaults()` rather than a rule of its own, so an admin-set
 * password is held to exactly the standard a self-chosen one is. No
 * `confirmed`: the admin is typing a generated value into one box, and a
 * confirmation field only invites them to paste it twice.
 */
class SetUserPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route carries role:admin; the controller applies the rest.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', Password::defaults()],
            /*
             * Whether to sign the account out everywhere.
             *
             * Defaults to true, and should stay true for the case this exists
             * for. A password reset that leaves live tokens behind does not
             * actually take the account back from whoever had it — which is
             * precisely the situation that prompts the reset.
             */
            'revoke_sessions' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
