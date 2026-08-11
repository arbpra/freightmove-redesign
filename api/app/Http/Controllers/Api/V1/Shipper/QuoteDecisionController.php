<?php

namespace App\Http\Controllers\Api\V1\Shipper;

use App\Enums\JobStatus;
use App\Enums\QuoteStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\JobQuoteResource;
use App\Models\FreightJob;
use App\Models\JobAcceptance;
use App\Models\JobQuote;
use App\Services\Notifier;
use App\Support\ApiResponse;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The shipper's side of the quote lifecycle: comparing, accepting, declining.
 *
 * Accepting is the only place in the app where several tables must move
 * together — the acceptance record, the winning quote, every losing quote, and
 * the job's status. It runs in one transaction, and the unique index on
 * `job_acceptances.job_id` is what makes a double-click or a race impossible
 * rather than merely unlikely.
 */
class QuoteDecisionController extends Controller
{
    /**
     * Quotes that lost when one was accepted.
     *
     * Collected inside the transaction and notified after it commits, because
     * once they are updated there is no way to distinguish "declined just now"
     * from "declined last week".
     *
     * @var Collection<int, JobQuote>
     */
    private Collection $losingQuotes;

    public function __construct(private readonly Notifier $notifier)
    {
        $this->losingQuotes = new Collection();
    }

    /**
     * GET /api/v1/shipper/jobs/{job}/quotes
     */
    public function index(Request $request, FreightJob $job): JsonResponse
    {
        $this->authorize('view', $job);

        $quotes = $job->quotes()
            ->with('carrier.profile')
            // Cheapest first: the comparison a shipper makes most often. The
            // carrier summary on each row is what stops it being price-only.
            ->orderBy('amount')
            ->get();

        return ApiResponse::success([
            'items' => JobQuoteResource::collection($quotes),
            'job' => [
                'id' => $job->id,
                'title' => $job->title,
                'status' => $job->status->value,
                'can_decide' => in_array($job->status, JobStatus::openForQuotes(), true),
            ],
        ]);
    }

    /**
     * POST /api/v1/shipper/quotes/{quote}/accept
     */
    public function accept(Request $request, JobQuote $quote): JsonResponse
    {
        $quote->load('job');
        $this->authorize('decide', $quote);

        try {
            $acceptance = DB::transaction(function () use ($quote, $request) {
                $job = $quote->job;

                $acceptance = JobAcceptance::create([
                    'job_id' => $job->id,
                    'quote_id' => $quote->id,
                    'carrier_id' => $quote->carrier_id,
                    'shipper_id' => $request->user()->id,
                    'accepted_at' => now(),
                ]);

                $quote->update(['status' => QuoteStatus::Accepted]);

                // Read before the update, because afterwards there is no way to
                // tell who was pending a moment ago from who was declined last
                // week — and only the former should be notified.
                $losing = $job->quotes()
                    ->whereKeyNot($quote->id)
                    ->where('status', QuoteStatus::Pending)
                    ->get();

                // Everyone else is told, rather than left waiting indefinitely.
                $job->quotes()
                    ->whereKeyNot($quote->id)
                    ->where('status', QuoteStatus::Pending)
                    ->update(['status' => QuoteStatus::Rejected]);

                $this->losingQuotes = $losing;

                $job->update([
                    'status' => JobStatus::Accepted,
                    'updated_by' => $request->user()->id,
                ]);

                return $acceptance;
            });
        } catch (UniqueConstraintViolationException) {
            // The unique index on job_acceptances.job_id caught a second accept
            // for the same load — a double submit, or two tabs.
            return ApiResponse::error('A quote has already been accepted for this load.', status: 409);
        }

        // After the transaction commits, never inside it. A notification is a
        // consequence of the booking, and writing it in the same transaction
        // would let a feed problem roll back a confirmed job.
        $this->notifier->quoteAccepted($quote, $quote->job);
        $this->notifier->quotesDeclined(
            $this->losingQuotes,
            $quote->job,
            'The shipper booked another carrier for this load.',
        );

        return ApiResponse::success(
            [
                'quote' => new JobQuoteResource($quote->fresh()->load('carrier.profile', 'job')),
                'accepted_at' => $acceptance->accepted_at->toIso8601String(),
            ],
            'Quote accepted. You can now contact the carrier to arrange pickup.',
        );
    }

    /**
     * POST /api/v1/shipper/quotes/{quote}/decline
     *
     * Declining one quote leaves the load open for the rest.
     */
    public function decline(Request $request, JobQuote $quote): JsonResponse
    {
        $quote->load('job');
        $this->authorize('decide', $quote);

        $quote->update(['status' => QuoteStatus::Rejected]);

        $this->notifier->quotesDeclined(
            [$quote],
            $quote->job,
            'The shipper has passed on this quote. The load may still be open.',
        );

        return ApiResponse::success(
            new JobQuoteResource($quote->fresh()->load('carrier.profile')),
            'Quote declined.',
        );
    }
}
