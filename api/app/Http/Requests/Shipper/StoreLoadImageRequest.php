<?php

namespace App\Http\Requests\Shipper;

use App\Support\UploadLimit;
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
        // What PHP will actually accept, which on a default install sits well
        // below what the product asks for. See UploadLimit.
        $maxKb = UploadLimit::maxKb((int) config('freightmove.loads.max_image_kb'));

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
        $max = UploadLimit::label(
            UploadLimit::maxKb((int) config('freightmove.loads.max_image_kb'))
        );

        return [
            'file.max' => "That photo is too large. The limit is {$max}.",
            'file.mimetypes' => 'Upload a JPG, PNG, GIF, WEBP or PDF.',
            'file.mimes' => 'Upload a JPG, PNG, GIF, WEBP or PDF.',
            // PHP discards a file over upload_max_filesize before Laravel ever
            // sees its contents. The stock message for that is "the file
            // failed to upload", which sends people hunting for a network
            // fault instead of a size limit.
            'file.uploaded' => "That photo is too large. The limit is {$max}.",
            'file.required' => "Choose a photo. If you did choose one, it may be over the {$max} limit.",
        ];
    }
}
