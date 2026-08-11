<?php

namespace App\Http\Controllers\Api\V1\Carrier;

use App\Enums\LoadAvailability;
use App\Http\Controllers\Controller;
use App\Http\Resources\FreightJobResource;
use App\Models\FreightJob;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The open load board.
 *
 * Filters combine freely — pickup state, delivery state, availability, truck
 * type, search — built as one query with `when()` clauses rather than a branch
 * per combination, which is what the legacy controller did with seven
 * hand-written if/else blocks (docs/10-domain-rules.md R7).
 */
class LoadBoardController extends Controller
{
    private const MAX_PER_PAGE = 100;

    /**
     * GET /api/v1/carrier/board
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'pickup_state' => ['nullable', 'string', 'max:10'],
            'delivery_state' => ['nullable', 'string', 'max:10'],
            'availability' => ['nullable', 'string', 'in:'.implode(',', LoadAvailability::values())],
            'truck_type_id' => ['nullable', 'integer', 'exists:truck_types,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'search' => ['nullable', 'string', 'max:255'],
            // Lets a carrier hide loads they have already priced.
            'unquoted' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ]);

        $carrierId = $request->user()->id;

        $jobs = FreightJob::query()
            ->published()
            ->recent()
            ->with(['categories', 'truckTypes'])
            ->withCount('quotes')
            // So the client can show "you have quoted this" without another call.
            ->withExists(['quotes as quoted_by_me' => fn ($query) => $query->where('carrier_id', $carrierId)])
            ->when(
                $filters['pickup_state'] ?? null,
                fn ($query, string $state) => $query->where('pickup_location', 'like', "%{$state}")
            )
            ->when(
                $filters['delivery_state'] ?? null,
                fn ($query, string $state) => $query->where('delivery_location', 'like', "%{$state}")
            )
            ->when(
                $filters['availability'] ?? null,
                fn ($query, string $value) => $query->where('availability', $value)
            )
            ->when(
                $filters['truck_type_id'] ?? null,
                fn ($query, int $id) => $query->whereHas('truckTypes', fn ($q) => $q->whereKey($id))
            )
            ->when(
                $filters['category_id'] ?? null,
                fn ($query, int $id) => $query->whereHas('categories', fn ($q) => $q->whereKey($id))
            )
            ->when(
                $filters['search'] ?? null,
                fn ($query, string $term) => $query->where(fn ($inner) => $inner
                    ->where('title', 'like', "%{$term}%")
                    ->orWhere('pickup_location', 'like', "%{$term}%")
                    ->orWhere('delivery_location', 'like', "%{$term}%"))
            )
            ->when(
                filter_var($filters['unquoted'] ?? false, FILTER_VALIDATE_BOOLEAN),
                fn ($query) => $query->whereDoesntHave('quotes', fn ($q) => $q->where('carrier_id', $carrierId))
            )
            ->boardOrder()
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return ApiResponse::success([
            'items' => FreightJobResource::collection($jobs->items()),
            'meta' => [
                'current_page' => $jobs->currentPage(),
                'last_page' => $jobs->lastPage(),
                'per_page' => $jobs->perPage(),
                'total' => $jobs->total(),
                // Surfaced so the UI can explain why older loads are absent.
                'recency_days' => (int) config('freightmove.board.recency_days'),
            ],
            'can_quote' => $request->user()->canQuote(),
        ]);
    }

    /**
     * GET /api/v1/carrier/board/{job}
     */
    public function show(Request $request, FreightJob $job): JsonResponse
    {
        // Only loads genuinely open for quotes are visible here — a carrier must
        // not be able to read a draft or a cancelled load by guessing its id.
        abort_unless(
            FreightJob::query()->published()->whereKey($job->getKey())->exists(),
            404,
        );

        $carrierId = $request->user()->id;

        $job->load(['categories', 'truckTypes'])
            ->loadCount('quotes')
            ->loadExists(['quotes as quoted_by_me' => fn ($query) => $query->where('carrier_id', $carrierId)]);

        return ApiResponse::success(new FreightJobResource($job));
    }
}
