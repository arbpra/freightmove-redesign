<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An admin editing someone else's account details.
 *
 * **Role and status are not here.** Role is the privilege boundary the whole
 * authorisation layer rests on, and the surrounding controller has always kept
 * it out on purpose — an "edit user" form that happens to carry a role dropdown
 * is how that boundary gets crossed by accident. Status has its own endpoint
 * with its own refusals. Both would be silently accepted by a mass-assign here
 * if they were merely forgotten, so their absence is stated rather than
 * implied.
 *
 * Contact details are in scope because they are what support actually gets
 * asked to change: a shipper who mistyped their email at registration cannot
 * reach the password reset that would let them fix it themselves.
 */
class UpdateUserRequest extends FormRequest
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
        $user = $this->route('user');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'first_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'email' => [
                'sometimes',
                'email',
                'max:255',
                // Unique across everyone but this account — email is the login
                // identity, and two accounts sharing one is unrecoverable.
                Rule::unique('users', 'email')->ignore($user?->id),
            ],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'wants_load_alerts' => ['sometimes', 'boolean'],

            'profile' => ['sometimes', 'array'],
            'profile.company_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'profile.abn_acn' => ['sometimes', 'nullable', 'string', 'max:32'],
            'profile.address_line_1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'profile.address_line_2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'profile.city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'profile.state' => ['sometimes', 'nullable', 'string', 'max:60'],
            'profile.postal_code' => ['sometimes', 'nullable', 'string', 'max:16'],
            'profile.bio' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Another account already uses that email address.',
        ];
    }
}
