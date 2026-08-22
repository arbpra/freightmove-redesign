<?php

namespace App\Http\Requests\Shipper;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A photo attached to a load.
 *
 * Both `mimetypes` and `mimes` are applied, as on verification documents:
 * content alone would accept a PDF named `.exe`, extension alone would accept
 * anything at all renamed `.jpg`. See docs/11-security.md.
 */
class StoreLoadImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route is shipper-only and the controller runs the job policy.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxKb = (int) config('freightmove.loads.max_image_kb');

        return [
            'file' => [
                'required',
                'file',
                "max:{$maxKb}",
                'mimetypes:'.implode(',', config('freightmove.loads.allowed_mime_types')),
                // No `svg` here, and no `image/svg+xml` in the config either.
                // These are shown inline on a public page.
                'mimes:jpg,jpeg,png,gif,webp,pdf',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $maxMb = round(((int) config('freightmove.loads.max_image_kb')) / 1024, 1);

        return [
            'file.max' => "That file is too large. The limit is {$maxMb}MB.",
            'file.mimetypes' => 'Upload a JPG, PNG, GIF, WEBP or PDF.',
            'file.mimes' => 'Upload a JPG, PNG, GIF, WEBP or PDF.',
        ];
    }
}
