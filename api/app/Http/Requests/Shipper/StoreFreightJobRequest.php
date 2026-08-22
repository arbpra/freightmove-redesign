<?php

namespace App\Http\Requests\Shipper;

use App\Enums\JobStatus;
use Illuminate\Foundation\Http\FormRequest;
use App\Enums\LoadAvailability;
use Illuminate\Validation\Rule;

class StoreFreightJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Reaching this route already requires the shipper role.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'pickup_location' => ['required', 'string', 'max:255'],
            'delivery_location' => ['required', 'string', 'max:255'],
            'pickup_date' => ['nullable', 'date'],
            // Cannot arrive before it leaves.
            'delivery_date' => ['nullable', 'date', 'after_or_equal:pickup_date'],
            'availability' => ['nullable', Rule::in(LoadAvailability::values())],
            // A load may suit several trailer types and span several
            // categories — 67 of 103 legacy loads do. Ids are checked
            // against the seeded vocabulary.
            'category_ids' => ['nullable', 'array', 'max:10'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
            'truck_type_ids' => ['nullable', 'array', 'max:20'],
            'truck_type_ids.*' => ['integer', 'exists:truck_types,id'],
            'load_category' => ['nullable', 'string', 'max:64'],
            // Free text, as the legacy field was: "3", "3 pallets", "2 crates".
            'quantity' => ['nullable', 'string', 'max:50'],
            // Millimetres. 30,000 mm is 30 m — longer than any legal road
            // combination, so a larger number is a typo or the wrong unit.
            'length_mm' => ['nullable', 'integer', 'min:1', 'max:30000'],
            'width_mm' => ['nullable', 'integer', 'min:1', 'max:30000'],
            'height_mm' => ['nullable', 'integer', 'min:1', 'max:30000'],
            // Kilograms. 100,000 kg is 100 t, comfortably past what moves on a
            // road train, and the ceiling exists to catch grams entered by
            // mistake rather than to police the freight task.
            'weight_kg' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'vehicle_type_required' => ['nullable', 'string', 'max:64'],
            'trailer_type_required' => ['nullable', 'string', 'max:64'],
            'budget_min' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'budget_max' => ['nullable', 'numeric', 'min:0', 'max:9999999', 'gte:budget_min'],
            // A shipper may only create a job as a draft or publish it now;
            // every later status is reached through the lifecycle endpoints.
            'status' => ['nullable', Rule::in([JobStatus::Draft->value, JobStatus::Published->value])],
            'visibility' => ['nullable', Rule::in(['public', 'private'])],

            // Contact details.
            //
            // The legacy `load_master` had no contact columns — the form showed
            // the shipper's own account details and posting kept them current.
            // Same here: these are not stored on the load, they update the
            // account, so one shipper has one set of contact details rather
            // than a different set per load with nothing keeping them true.
            //
            // Optional, because a draft saved in a hurry should not be blocked
            // on a phone number.
            'contact.first_name' => ['nullable', 'string', 'max:100'],
            'contact.last_name' => ['nullable', 'string', 'max:100'],
            // Unique against every other account: this is the login identifier.
            'contact.email' => [
                'nullable', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($this->user()?->id),
            ],
            'contact.phone' => ['nullable', 'string', 'max:32'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'delivery_date.after_or_equal' => 'Delivery cannot be earlier than pickup.',
            'contact.email.unique' => 'Another account already uses that email address.',
            'budget_max.gte' => 'The maximum budget must be at least the minimum.',
            'weight_kg.max' => 'Enter the weight in kilograms — 100,000 kg (100 t) is the maximum.',
            'length_mm.max' => 'Enter the length in millimetres — 30,000 mm (30 m) is the maximum.',
            'width_mm.max' => 'Enter the width in millimetres — 30,000 mm (30 m) is the maximum.',
            'height_mm.max' => 'Enter the height in millimetres — 30,000 mm (30 m) is the maximum.',
        ];
    }
}
