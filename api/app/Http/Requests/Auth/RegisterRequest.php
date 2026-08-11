<?php

namespace App\Http\Requests\Auth;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'phone' => ['nullable', 'string', 'max:32'],
            // Self-registration is limited to the two marketplace roles;
            // admins are created internally.
            'role' => ['required', Rule::in([UserRole::Shipper->value, UserRole::Carrier->value])],
            'company_name' => ['nullable', 'string', 'max:255'],

            // Carriers pick a plan as they sign up — the subscription is the
            // product they are here for. Shippers post loads for free, so the
            // field is rejected outright for them rather than ignored: silently
            // dropping a value the client sent is how mismatched expectations
            // survive to production.
            'subscription_plan' => [
                Rule::requiredIf(fn () => $this->input('role') === UserRole::Carrier->value),
                Rule::prohibitedIf(fn () => $this->input('role') !== UserRole::Carrier->value),
                Rule::exists('subscription_plans', 'code')->where('is_active', true),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'role.in' => 'Choose whether you are registering as a shipper or a carrier.',
            'subscription_plan.required' => 'Choose a plan to get started.',
            'subscription_plan.exists' => 'That plan is not available.',
            'subscription_plan.prohibited' => 'Only carriers choose a subscription plan.',
        ];
    }

    /**
     * Guards the trial separately from the plan list.
     *
     * `exists` only proves the plan is real and active; whether the *offer* is
     * still open is a rule of its own, and a closed offer must not be
     * selectable just because the row is still there.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->input('subscription_plan') !== 'trial') {
                return;
            }

            if (! app(\App\Services\SubscriptionService::class)->trialOfferIsOpen()) {
                $validator->errors()->add(
                    'subscription_plan',
                    'The free trial offer has closed. Please choose a paid plan.',
                );
            }
        });
    }
}
