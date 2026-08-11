<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FreightJob;
use App\Models\Review;
use App\Models\User;
use App\Services\Notifier;
use App\Services\ReputationService;
use App\Support\ApiResponse;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reviews on a completed load.
 *
 * Shared by both roles: a shipper rates the carrier who moved the freight, and
 * the carrier rates the shipper who booked them. ReviewPolicy owns the rules.
 */
class ReviewController extends Controller
{
    public function __construct(
        private readonly ReputationService $reputation,
        private readonly Notifier $notifier,
    ) {}

    /**
     * GET /api/v1/jobs/{job}/reviews
     *
     * Both reviews on a load, and whether the caller still owes one.
     */
    public function index(Request $request, FreightJob $job): JsonResponse
    {
        $user = $request->user();
        $carrierId = $job->acceptance?->carrier_id;

        abort_unless($user->id === $job->shipper_id || $user->id === $carrierId, 403);

        $reviews = Review::with(['reviewer:id,name', 'reviewer.profile:id,user_id,company_name'])
            ->where('job_id', $job->id)
            ->get();

        return ApiResponse::success([
            'items' => $reviews->map(fn (Review $review) => [
                'id' => $review->id,
                'rating' => $review->rating,
                'comment' => $review->comment,
                'by' => $review->reviewer?->profile?->company_name ?: $review->reviewer?->name,
                'by_me' => $review->reviewer_id === $user->id,
                'created_at' => $review->created_at?->toIso8601String(),
            ])->values()->all(),
            // Drives whether the client offers the form at all.
            'can_review' => $user->can('create', [Review::class, $job]),
            'already_reviewed' => $reviews->contains('reviewer_id', $user->id),
        ]);
    }

    /**
     * POST /api/v1/jobs/{job}/reviews
     */
    public function store(Request $request, FreightJob $job): JsonResponse
    {
        $this->authorize('create', [Review::class, $job]);

        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1500'],
        ]);

        $user = $request->user();

        // The other party to this load — never chosen by the client, always
        // derived, so a review cannot be aimed at someone uninvolved.
        $reviewedId = $user->id === $job->shipper_id
            ? $job->acceptance?->carrier_id
            : $job->shipper_id;

        if (! $reviewedId) {
            return ApiResponse::error('This load has no counterparty to review.', status: 422);
        }

        try {
            $review = Review::create([
                'job_id' => $job->id,
                'reviewer_id' => $user->id,
                'reviewed_user_id' => $reviewedId,
                'rating' => $validated['rating'],
                'comment' => $validated['comment'] ?? null,
            ]);
        } catch (UniqueConstraintViolationException) {
            // The policy already checked; this is the double-submit race, caught
            // by the unique index on (job_id, reviewer_id).
            return ApiResponse::error('You have already reviewed this load.', status: 409);
        }

        // The rating is derived, so it is recomputed rather than adjusted — an
        // incremental average drifts, and one bad write would leave a number
        // nobody can reconcile against the reviews behind it.
        $reviewed = User::find($reviewedId);

        if ($reviewed) {
            $this->reputation->refresh($reviewed);
            $this->notifier->reviewReceived($job, $reviewedId, $review->rating);
        }

        return ApiResponse::success([
            'id' => $review->id,
            'rating' => $review->rating,
            'comment' => $review->comment,
        ], 'Thanks — your review is published.', 201);
    }
}
