<?php

namespace App\Http\Controllers\Api\V1\Carrier;

use App\Enums\DocumentStatus;
use App\Enums\VerificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Carrier\StoreVerificationDocumentRequest;
use App\Models\VerificationDocument;
use App\Services\VerificationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Verification documents a carrier submits about themselves.
 *
 * This is the application's first upload endpoint, so it is also where the
 * hardening rules in docs/11-security.md take effect:
 *
 *   - the MIME type is checked against the file's **contents**, not its name
 *   - files are stored on the `local` disk, which roots at storage/app/private
 *     and is not reachable over HTTP by any path
 *   - stored names are random hashes, so a leaked path cannot be guessed and a
 *     hostile original filename never touches the filesystem
 *   - size is capped in config
 *   - reading one back goes through `download()`, behind auth and a policy
 */
class VerificationDocumentController extends Controller
{
    private const DISK = 'local';

    public function __construct(private readonly VerificationService $verification) {}

    /**
     * GET /api/v1/carrier/documents
     */
    public function index(Request $request): JsonResponse
    {
        $documents = $request->user()->verificationDocuments()->latest()->get();

        return ApiResponse::success([
            'items' => $documents->map(fn (VerificationDocument $doc) => $this->present($doc))->all(),
            'missing' => $this->verification->missingTypes($request->user()),
        ]);
    }

    /**
     * POST /api/v1/carrier/documents
     */
    public function store(StoreVerificationDocumentRequest $request): JsonResponse
    {
        $user = $request->user();
        $file = $request->file('file');

        // A hashed name, in a per-user folder. The original filename is kept as
        // data, never as a path — it is attacker-controlled text and has no
        // business deciding where a byte lands.
        $path = $file->store("verification/{$user->id}", self::DISK);

        $document = $user->verificationDocuments()->create([
            'document_type' => $request->validated('document_type'),
            'file_path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            // From finfo, not from the upload's declared type.
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'status' => DocumentStatus::Pending,
            'expires_at' => $request->validated('expires_at'),
        ]);

        $this->markSubmitted($user);

        return ApiResponse::success(
            $this->present($document),
            'Document uploaded. Our team will review it shortly.',
            201,
        );
    }

    /**
     * GET /api/v1/carrier/documents/{document}/download
     *
     * Streams the file to its owner (or an admin). There is no public URL for
     * these, and no signed link either — a link that works without a session is
     * a link that works after it is forwarded.
     */
    public function download(Request $request, VerificationDocument $document): StreamedResponse
    {
        $this->authorize('view', $document);

        abort_unless(Storage::disk(self::DISK)->exists($document->file_path), 404);

        return Storage::disk(self::DISK)->download(
            $document->file_path,
            $document->original_name ?: 'document',
            // Never inline: an SVG or HTML file rendered in the browser under
            // our origin would be a stored XSS, whatever the MIME check said.
            ['Content-Disposition' => 'attachment'],
        );
    }

    /**
     * DELETE /api/v1/carrier/documents/{document}
     */
    public function destroy(Request $request, VerificationDocument $document): JsonResponse
    {
        $this->authorize('delete', $document);

        Storage::disk(self::DISK)->delete($document->file_path);
        $document->delete();

        $this->verification->refresh($request->user());

        return ApiResponse::success(null, 'Document removed.');
    }

    /**
     * Moves an unverified profile into "pending" once something is submitted,
     * so the carrier sees that the ball is in our court.
     */
    private function markSubmitted($user): void
    {
        $profile = $user->profile;

        if (! $profile) {
            return;
        }

        $profile->forceFill([
            'verification_submitted_at' => $profile->verification_submitted_at ?? now(),
        ])->save();

        if ($profile->verification_status !== VerificationStatus::Verified) {
            $this->verification->refresh($user);
        }
    }

    /** @return array<string, mixed> */
    private function present(VerificationDocument $document): array
    {
        return [
            'id' => $document->id,
            'document_type' => $document->document_type,
            'original_name' => $document->original_name,
            'size_bytes' => $document->size_bytes,
            'status' => $document->status->value,
            'review_note' => $document->review_note,
            'expires_at' => $document->expires_at?->toDateString(),
            'has_lapsed' => $document->hasLapsed(),
            'uploaded_at' => $document->created_at?->toIso8601String(),
        ];
    }
}
