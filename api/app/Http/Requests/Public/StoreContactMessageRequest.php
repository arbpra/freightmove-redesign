<?php

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContactMessageRequest extends FormRequest
{
    /** Public form — anyone may submit, signed in or not. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Bounds match the Angular form so a valid submission there is never
     * rejected here, and an invalid one is rejected in both places.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'role' => ['nullable', Rule::in(['shipper', 'carrier', 'other'])],
            'subject' => ['nullable', 'string', 'max:150'],
            // A floor as well as a ceiling: "hi" is not an enquiry anyone can
            // answer, and it is what most bot submissions look like.
            'message' => ['required', 'string', 'min:20', 'max:2000'],

            // Honeypot. A real browser never fills this — it is hidden and
            // has no label — so anything in it came from a script.
            'company_website' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'message.min' => 'Please tell us a little more so we can answer properly.',
        ];
    }

    /** True when the honeypot was filled, i.e. this is not a person. */
    public function looksAutomated(): bool
    {
        return trim((string) $this->input('company_website')) !== '';
    }
}
