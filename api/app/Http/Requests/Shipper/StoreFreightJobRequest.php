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
            'weight_tons' => ['nullable', 'numeric', 'min:0', 'max:200'],
            'vehicle_type_required' => ['nullable', 'string', 'max:64'],
            'trailer_type_required' => ['nullable', 'string', 'max:64'],
            'budget_min' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'budget_max' => ['nullable', 'numeric', 'min:0', 'max:9999999', 'gte:budget_min'],
            // A shipper may only create a job as a draft or publish it now;
            // every later status is reached through the lifecycle endpoints.
            'status' => ['nullable', Rule::in([JobStatus::Draft->value, JobStatus::Published->value])],
            'visibility' => ['nullable', Rule::in(['public', 'private'])],
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
            'weight_tons.max' => 'Enter the weight in tonnes — 200 is the maximum.',
        ];
    }
}
