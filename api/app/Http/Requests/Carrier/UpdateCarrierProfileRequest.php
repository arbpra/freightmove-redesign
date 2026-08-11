<?php

namespace App\Http\Requests\Carrier;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What a carrier may change about their own profile.
 *
 * The whitelist is the security boundary. `verification_status`, `rating` and
 * `completed_jobs_count` are all fillable on the models behind this, and all
 * three are claims the platform makes about a carrier rather than claims the
 * carrier makes about themselves — so none of them appear here, and the
 * controller only ever passes `validated()` through.
 */
class UpdateCarrierProfileRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],

            'company_name' => ['sometimes', 'nullable', 'string', 'max:180'],
            // 11 digits for an ABN, 9 for an ACN. Punctuation and spaces are
            // normalised away in the controller before this runs.
            'abn_acn' => ['sometimes', 'nullable', 'string', 'regex:/^\d{9}$|^\d{11}$/'],
            'business_type' => ['sometimes', 'nullable', 'string', 'max:60'],
            'address_line_1' => ['sometimes', 'nullable', 'string', 'max:180'],
            'address_line_2' => ['sometimes', 'nullable', 'string', 'max:180'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'state' => ['sometimes', 'nullable', 'string', 'max:10'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:12'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:1500'],

            'fleet_size' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000'],
            'service_radius_km' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000'],
            'preferred_regions' => ['sometimes', 'nullable', 'array', 'max:16'],
            'preferred_regions.*' => ['string', 'max:60'],
            'insurance_provider' => ['sometimes', 'nullable', 'string', 'max:180'],
            'insurance_policy_number' => ['sometimes', 'nullable', 'string', 'max:120'],
            'operating_since' => [
                'sometimes', 'nullable', 'integer',
                'min:1900', 'max:'.date('Y'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'abn_acn.regex' => 'An ABN is 11 digits and an ACN is 9. Check the number and try again.',
            'operating_since.max' => 'Operating since cannot be in the future.',
        ];
    }

    /** Strips the spaces people naturally type into an ABN. */
    protected function prepareForValidation(): void
    {
        if ($this->has('abn_acn') && is_string($this->input('abn_acn'))) {
            $digits = preg_replace('/\D/', '', $this->input('abn_acn'));

            $this->merge(['abn_acn' => $digits === '' ? null : $digits]);
        }
    }

    /** Fields that live on `user_profiles`. */
    public function profileData(): array
    {
        return $this->safe()->only([
            'company_name', 'abn_acn', 'business_type', 'address_line_1',
            'address_line_2', 'city', 'state', 'postal_code', 'bio',
        ]);
    }

    /** Fields that live on `carriers`. */
    public function carrierData(): array
    {
        return $this->safe()->only([
            'fleet_size', 'service_radius_km', 'preferred_regions',
            'insurance_provider', 'insurance_policy_number', 'operating_since',
        ]);
    }

    /** Fields that live on `users`. */
    public function userData(): array
    {
        return $this->safe()->only(['name', 'phone']);
    }

    /**
     * Whether this change touches something verification was granted on.
     *
     * A carrier who was verified against one ABN and one insurer must not stay
     * verified after quietly swapping either.
     */
    public function touchesVerifiedFacts(): bool
    {
        return array_intersect(
            array_keys($this->safe()->all()),
            ['abn_acn', 'company_name', 'insurance_provider', 'insurance_policy_number'],
        ) !== [];
    }
}
