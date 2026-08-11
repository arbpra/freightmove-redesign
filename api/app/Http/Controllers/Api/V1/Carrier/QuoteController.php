<?php

namespace App\Http\Controllers\Api\V1\Carrier;

use App\Enums\QuoteStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Carrier\StoreQuoteRequest;
use App\Http\Resources\JobQuoteResource;
use App\Models\FreightJob;
use App\Models\JobQuote;
use App\Services\Notifier;
use App\Support\ApiResponse;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A carrier's own quotes.
 */
class QuoteController extends Controller
{
    private const MAX_PER_PAGE = 100;

    public function __construct(private readonly Notifier $notifier) {}

    /**
     * GET /api/v1/carrier/quotes
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'in:'.implode(',', array_column(QuoteStatus::cases(), 'value'))],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ]);

        $quotes = JobQuote::query()
            ->where('carrier_id', $request->user()->id)
            ->with('job')
            ->when(
                $filters['status'] ?? null,
                fn ($query, string $status) => $query->where('status', $status)
            )
            ->latest()
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return ApiResponse::success([
            'items' => JobQuoteResource::collection($quotes->items()),
            'meta' => [
                'current_page' => $quotes->currentPage(),
                'last_page' => $quotes->lastPage(),
                'per_page' => $quotes->perPage(),
                'total' => $quotes->total(),
            ],
        ]);
    }

    /**
     * POST /api/v1/carrier/board/{job}/quotes
     */
    public function store(StoreQuoteRequest $request, FreightJob $job): JsonResponse
    {
        // Quoting a draft, cancelled or already-booked load is meaningless, and
        // a 404 avoids confirming that a private load exists at all.
        abort_unless(
            FreightJob::query()->published()->whereKey($job->getKey())->exists(),
            404,
        );

        $this->authorize('create', [JobQuote::class, $job]);

        try {
            $quote = JobQuote::create([
                'job_id' => $job->id,
                'carrier_id' => $request->user()->id,
                'amount' => $request->validated('amount'),
                'currency' => 'AUD',
                'estimated_delivery_date' => $request->validated('estimated_delivery_date'),
                'notes' => $request->validated('notes'),
                'status' => QuoteStatus::Pending,
            ]);
        } catch (QueryException $e) {
            // The policy already checked for a duplicate; this catches the race
            // where two requests pass that check at the same moment. The unique
            // index on (job_id, carrier_id) is what actually enforces the rule.
            if ($e->getCode() === '23000') {
                return ApiResponse::error('You have already quoted on this load.', status: 409);
            }

            throw $e;
        }

        $this->notifier->quoteReceived($quote->load('carrier.profile'), $job);

        return ApiResponse::success(
            new JobQuoteResource($quote->load('job')),
            'Quote sent. The shipper has been notified.',
            201,
        );
    }

    /**
     * DELETE /api/v1/carrier/quotes/{quote}
     */
    public function destroy(Request $request, JobQuote $quote): JsonResponse
    {
        $this->authorize('delete', $quote);

        // Captured before the delete: the shipper may have been about to pick
        // this one, so they are told it is gone.
        $job = $quote->job;
        $quote->loadMissing('carrier.profile');

        $quote->delete();

        if ($job) {
            $this->notifier->quoteWithdrawn($quote, $job);
        }

        return ApiResponse::success(null, 'Quote withdrawn.');
    }
}
