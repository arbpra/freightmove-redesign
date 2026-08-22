<?php

namespace App\Http\Controllers\Api\V1\Shipper;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shipper\StoreLoadImageRequest;
use App\Models\FreightJob;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Photos attached to a load.
 *
 * Unlike verification documents, these are **public**: a carrier deciding
 * whether a machine fits on their trailer has to be able to see it, and the
 * board is browsable without an account. That difference drives every choice
 * here \u2014 public disk, public URL, and a stricter type list, because a file
 * rendered inline on our origin is a script execution risk in a way an
 * attachment never is (docs/11-security.md).
 *
 * What is kept from the private-upload pattern: hashed storage names, so a
 * path cannot be guessed or enumerated, and MIME checked against contents.
 */
class LoadImageController extends Controller
{
    private const DISK = 'public';

    /**
     * POST /api/v1/shipper/jobs/{job}/images
     */
    public function store(StoreLoadImageRequest $request, FreightJob $job): JsonResponse
    {
        $this->authorize('update', $job);

        $images = $job->images_json ?? [];
        $max = (int) config('freightmove.loads.max_images');

        if (count($images) >= $max) {
            return ApiResponse::error(
                "This load already has {$max} photos. Remove one before adding another.",
                [],
                422,
            );
        }

        // A hashed name in a per-load folder. The original filename is
        // attacker-controlled text and never decides where a byte lands.
        $path = $request->file('file')->store("loads/{$job->id}", self::DISK);

        $images[] = $path;
        $job->forceFill(['images_json' => $images])->save();

        return ApiResponse::success(
            ['images' => $this->present($images)],
            'Photo added.',
            201,
        );
    }

    /**
     * DELETE /api/v1/shipper/jobs/{job}/images
     *
     * The path is sent in the body rather than the URL because it contains
     * slashes. It is matched against the stored list before anything is
     * touched, so a caller cannot name a file belonging to another load.
     */
    public function destroy(Request $request, FreightJob $job): JsonResponse
    {
        $this->authorize('update', $job);

        $path = (string) $request->input('path');
        $images = $job->images_json ?? [];

        if (! in_array($path, $images, true)) {
            return ApiResponse::error('That photo is not on this load.', [], 404);
        }

        Storage::disk(self::DISK)->delete($path);

        $job->forceFill([
            'images_json' => array_values(array_filter($images, fn ($p) => $p !== $path)),
        ])->save();

        return ApiResponse::success(
            ['images' => $this->present($job->images_json ?? [])],
            'Photo removed.',
        );
    }

    /**
     * Turns stored paths into what a client needs to render them.
     *
     * Legacy rows hold a bare filename from `public/images/load` rather than a
     * path on our disk. Those are returned with a null url instead of a broken
     * link \u2014 the file itself was never migrated, and a 404 image is worse than
     * an honest absence.
     *
     * @param  list<string>  $paths
     * @return list<array{path: string, url: string|null}>
     */
    private function present(array $paths): array
    {
        return array_values(array_map(fn (string $path) => [
            'path' => $path,
            'url' => str_contains($path, '/') ? Storage::disk(self::DISK)->url($path) : null,
        ], $paths));
    }
}
