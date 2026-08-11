<?php

namespace App\Http\Requests\Carrier;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVerificationDocumentRequest extends FormRequest
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
        $types = array_keys(config('freightmove.verification.document_types', []));
        $maxKb = (int) config('freightmove.verification.max_upload_kb');

        return [
            'document_type' => ['required', Rule::in($types)],
            'file' => [
                'required',
                'file',
                "max:{$maxKb}",
                // `mimetypes` inspects the file's contents via finfo; `mimes`
                // checks the extension. Both, deliberately: content alone would
                // accept a PDF named .exe, and extension alone would accept
                // anything at all renamed to .pdf.
                'mimetypes:'.implode(',', config('freightmove.verification.allowed_mime_types')),
                'mimes:pdf,jpg,jpeg,png,webp',
            ],
            // Insurance certificates lapse. Optional because an ABN extract
            // does not expire.
            'expires_at' => ['nullable', 'date', 'after:today'],
        ];
    }

    public function messages(): array
    {
        $maxMb = round(((int) config('freightmove.verification.max_upload_kb')) / 1024, 1);

        return [
            'file.max' => "That file is too large. The limit is {$maxMb}MB.",
            'file.mimetypes' => 'Upload a PDF or an image (JPG, PNG or WEBP).',
            'file.mimes' => 'Upload a PDF or an image (JPG, PNG or WEBP).',
            'expires_at.after' => 'That document has already expired.',
        ];
    }
}
