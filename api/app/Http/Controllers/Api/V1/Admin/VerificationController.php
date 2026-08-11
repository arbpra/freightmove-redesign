<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\DocumentStatus;
use App\Enums\VerificationStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\VerificationDocument;
use App\Services\Notifier;
use App\Services\VerificationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The admin verification queue.
 *
 * Approval is a human judgement about a real business, so nothing here decides
 * anything automatically. An admin approves or rejects one document at a time;
 * VerificationService then works out what that means for the carrier's overall
 * status, which is derived rather than set by hand.
 */
class VerificationController extends Controller
{
    public function __construct(
        private readonly VerificationService $verification,
        private readonly Notifier $notifier,
    ) {}

    /**
     * GET /api/v1/admin/verifications
     *
     * Carriers with something waiting, oldest first — a queue, not a list, so
     * the person who has waited longest is dealt with first.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:'.implode(',', DocumentStatus::values())],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $status = $validated['status'] ?? DocumentStatus::Pending->value;

        $documents = VerificationDocument::with(['user.profile'])
            ->where('status', $status)
            ->oldest()
            ->paginate($validated['per_page'] ?? 25)
            ->withQueryString();

        return ApiResponse::success([
            'items' => array_map(
                fn (VerificationDocument $doc) => [
                    'id' => $doc->id,
                    'document_type' => $doc->document_type,
                    'original_name' => $doc->original_name,
                    'mime_type' => $doc->mime_type,
                    'size_bytes' => $doc->size_bytes,
                    'status' => $doc->status->value,
                    'expires_at' => $doc->expires_at?->toDateString(),
                    'uploaded_at' => $doc->created_at?->toIso8601String(),
                    'carrier' => [
                        'id' => $doc->user->id,
                        'name' => $doc->user->name,
                        'email' => $doc->user->email,
                        'company_name' => $doc->user->profile?->company_name,
                        'abn_acn' => $doc->user->profile?->abn_acn,
                        'verification_status' => $doc->user->profile?->verification_status->value,
                    ],
                ],
                $documents->items(),
            ),
            'meta' => [
                'current_page' => $documents->currentPage(),
                'last_page' => $documents->lastPage(),
                'per_page' => $documents->perPage(),
                'total' => $documents->total(),
            ],
        ]);
    }

    /**
     * POST /api/v1/admin/documents/{document}/approve
     */
    public function approve(Request $request, VerificationDocument $document): JsonResponse
    {
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
            // An admin reading a certificate can enter the date the carrier
            // did not, or correct one they got wrong.
            'expires_at' => ['nullable', 'date', 'after:today'],
        ]);

        return $this->decide($document, DocumentStatus::Approved, $request->user(), $validated);
    }

    /**
     * POST /api/v1/admin/documents/{document}/reject
     */
    public function reject(Request $request, VerificationDocument $document): JsonResponse
    {
        $validated = $request->validate([
            // Required here. A rejection the carrier cannot act on just
            // produces the same document again.
            'note' => ['required', 'string', 'min:10', 'max:1000'],
        ], [
            'note.required' => 'Tell the carrier what was wrong so they can fix it.',
        ]);

        return $this->decide($document, DocumentStatus::Rejected, $request->user(), $validated);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function decide(
        VerificationDocument $document,
        DocumentStatus $status,
        User $admin,
        array $validated,
    ): JsonResponse {
        $carrier = $document->user;

        DB::transaction(function () use ($document, $status, $admin, $validated) {
            $document->forceFill([
                'status' => $status,
                'reviewed_by' => $admin->id,
                'review_note' => $validated['note'] ?? null,
                'reviewed_at' => now(),
                'expires_at' => $validated['expires_at'] ?? $document->expires_at,
            ])->save();
        });

        $wasVerified = $carrier->profile?->verification_status === VerificationStatus::Verified;

        // Recomputed from every document the carrier holds, not inferred from
        // this one: approving an insurance certificate means nothing if the ABN
        // is still outstanding.
        $newStatus = $this->verification->refresh($carrier->refresh());

        $this->notifier->documentReviewed($document, $status === DocumentStatus::Approved);

        // Only on the transition. Telling someone they are verified every time
        // a document is touched is noise.
        if (! $wasVerified && $newStatus === VerificationStatus::Verified) {
            $this->notifier->carrierVerified($carrier);
        }

        return ApiResponse::success([
            'document_status' => $status->value,
            'carrier_verification_status' => $newStatus->value,
            'still_missing' => $this->verification->missingTypes($carrier),
        ], $status === DocumentStatus::Approved ? 'Document approved.' : 'Document rejected.');
    }
}
