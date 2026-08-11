<?php

namespace App\Http\Requests\Shipper;

use Illuminate\Foundation\Http\FormRequest;
use App\Enums\LoadAvailability;
use Illuminate\Validation\Rule;

/**
 * Partial update. Every field is `sometimes`, so a PATCH carrying one key
 * cannot blank the rest — but any field present is validated in full.
 */
class UpdateFreightJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Ownership is enforced by FreightJobPolicy in the controller.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'pickup_location' => ['sometimes', 'required', 'string', 'max:255'],
            'delivery_location' => ['sometimes', 'required', 'string', 'max:255'],
            'pickup_date' => ['sometimes', 'nullable', 'date'],
            'delivery_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:pickup_date'],
            'availability' => ['sometimes', 'nullable', Rule::in(LoadAvailability::values())],
            // A load may suit several trailer types and span several
            // categories — 67 of 103 legacy loads do. Ids are checked
            // against the seeded vocabulary.
            'category_ids' => ['sometimes', 'nullable', 'array', 'max:10'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
            'truck_type_ids' => ['sometimes', 'nullable', 'array', 'max:20'],
            'truck_type_ids.*' => ['integer', 'exists:truck_types,id'],
            'load_category' => ['sometimes', 'nullable', 'string', 'max:64'],
            'weight_tons' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:200'],
            'vehicle_type_required' => ['sometimes', 'nullable', 'string', 'max:64'],
            'trailer_type_required' => ['sometimes', 'nullable', 'string', 'max:64'],
            'budget_min' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999'],
            'budget_max' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999', 'gte:budget_min'],
            'visibility' => ['sometimes', Rule::in(['public', 'private'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'delivery_date.after_or_equal' => 'Delivery cannot be earlier than pickup.',
            'budget_max.gte' => 'The maximum budget must be at least the minimum.',
        ];
    }
}
