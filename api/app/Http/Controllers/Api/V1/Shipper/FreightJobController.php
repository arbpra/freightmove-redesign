<?php

namespace App\Http\Controllers\Api\V1\Shipper;

use App\Enums\JobStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shipper\StoreFreightJobRequest;
use App\Http\Requests\Shipper\UpdateFreightJobRequest;
use App\Http\Resources\FreightJobResource;
use App\Models\Category;
use App\Models\FreightJob;
use App\Mail\LoadPosted;
use App\Models\TruckType;
use App\Services\Notifier;
use App\Services\ReputationService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A shipper's own freight jobs.
 *
 * Every query is scoped to the authenticated shipper, so one shipper can never
 * enumerate another's loads. Individual records are additionally checked
 * through FreightJobPolicy, which owns the lifecycle rules.
 */
class FreightJobController extends Controller
{
    /** Page size cap, so a client cannot ask for everything at once. */
    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly ReputationService $reputation,
        private readonly Notifier $notifier,
    ) {}

    /**
     * GET /api/v1/shipper/jobs
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:'.implode(',', JobStatus::values())],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ]);

        $jobs = FreightJob::forShipper($request->user()->id)
            ->withCount('quotes')
            ->when(
                $validated['status'] ?? null,
                fn ($query, string $status) => $query->where('status', $status)
            )
            ->when(
                $validated['search'] ?? null,
                fn ($query, string $term) => $query->where(function ($inner) use ($term) {
                    $inner->where('title', 'like', "%{$term}%")
                        ->orWhere('pickup_location', 'like', "%{$term}%")
                        ->orWhere('delivery_location', 'like', "%{$term}%");
                })
            )
            ->latest()
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return ApiResponse::success([
            'items' => FreightJobResource::collection($jobs->items()),
            'meta' => [
                'current_page' => $jobs->currentPage(),
                'last_page' => $jobs->lastPage(),
                'per_page' => $jobs->perPage(),
                'total' => $jobs->total(),
            ],
        ]);
    }

    /**
     * POST /api/v1/shipper/jobs
     */
    public function store(StoreFreightJobRequest $request): JsonResponse
    {
        $data = $request->validated();

        $job = FreightJob::create([
            ...Arr::except($data, ['category_ids', 'truck_type_ids']),
            'shipper_id' => $request->user()->id,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
            'status' => $request->validated('status', JobStatus::Draft->value),
            'visibility' => $request->validated('visibility', 'public'),
        ]);

        $this->syncTaxonomy($job, $data);

        if ($job->status === JobStatus::Published) {
            $this->confirmPosted($job, $request->user());
        }

        return ApiResponse::success(
            new FreightJobResource($job->load(['categories', 'truckTypes'])),
            $job->status === JobStatus::Published
                ? 'Your load is live. Carriers on this lane have been notified.'
                : 'Draft saved.',
            201,
        );
    }

    /**
     * GET /api/v1/shipper/jobs/{job}
     */
    public function show(Request $request, FreightJob $job): JsonResponse
    {
        $this->authorize('view', $job);

        return ApiResponse::success(
            new FreightJobResource($job->load(['categories', 'truckTypes'])->loadCount('quotes')),
        );
    }

    /**
     * PATCH /api/v1/shipper/jobs/{job}
     */
    public function update(UpdateFreightJobRequest $request, FreightJob $job): JsonResponse
    {
        $this->authorize('update', $job);

        $data = $request->validated();

        $job->update([
            ...Arr::except($data, ['category_ids', 'truck_type_ids']),
            'updated_by' => $request->user()->id,
        ]);

        $this->syncTaxonomy($job, $data);

        return ApiResponse::success(
            new FreightJobResource($job->load(['categories', 'truckTypes'])->loadCount('quotes')),
            'Load updated.',
        );
    }

    /**
     * DELETE /api/v1/shipper/jobs/{job}
     *
     * Soft delete — the record is retained for the carriers who quoted on it
     * and for dispute history.
     */
    public function destroy(Request $request, FreightJob $job): JsonResponse
    {
        $this->authorize('delete', $job);

        $job->delete();

        return ApiResponse::success(null, 'Load removed.');
    }

    /**
     * Applies the multi-select answers, and keeps the denormalised singular
     * columns in step with the first value so list rows stay cheap to render.
     *
     * Absent keys are left alone: a PATCH that does not mention categories must
     * not wipe them.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncTaxonomy(FreightJob $job, array $data): void
    {
        if (array_key_exists('category_ids', $data)) {
            $ids = $data['category_ids'] ?? [];
            $job->categories()->sync($ids);
            $job->forceFill([
                'load_category' => Category::whereKey($ids)->value('name'),
            ])->save();
        }

        if (array_key_exists('truck_type_ids', $data)) {
            $ids = $data['truck_type_ids'] ?? [];
            $job->truckTypes()->sync($ids);
            $job->forceFill([
                'trailer_type_required' => TruckType::whereKey($ids)->value('name'),
            ])->save();
        }
    }

    /**
     * POST /api/v1/shipper/jobs/{job}/publish
     */
    public function publish(Request $request, FreightJob $job): JsonResponse
    {
        $this->authorize('publish', $job);

        $job->update([
            'status' => JobStatus::Published,
            'updated_by' => $request->user()->id,
        ]);

        $this->confirmPosted($job, $request->user());

        return ApiResponse::success(
            new FreightJobResource($job->loadCount('quotes')),
            'Your load is live. Carriers on this lane have been notified.',
        );
    }

    /**
     * Emails the shipper a confirmation that their load is live.
     *
     * The previous site sent this, so shippers expect it. Wrapped so a mail
     * failure can never fail the post itself — the load is on the board either
     * way, and that is the part that matters.
     */
    private function confirmPosted(FreightJob $job, $shipper): void
    {
        if (! $shipper?->email) {
            return;
        }

        try {
            $mail = new LoadPosted($job);

            if (config('freightmove.mail.queue')) {
                Mail::to($shipper->email)->queue($mail);
            } else {
                Mail::to($shipper->email)->send($mail);
            }
        } catch (Throwable $e) {
            Log::error('Could not send the load confirmation email.', [
                'job' => $job->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * POST /api/v1/shipper/jobs/{job}/relist
     *
     * Bumps an open load back to the top of the carrier board and restarts its
     * recency window. Deliberately a separate action rather than a side effect
     * of PATCH: the legacy site conflated the two by touching `date_updated` on
     * every edit, which made "changed the pickup date" and "wants attention"
     * indistinguishable (docs/10-domain-rules.md R5).
     */
    public function relist(Request $request, FreightJob $job): JsonResponse
    {
        $this->authorize('relist', $job);

        $cooldown = (int) config('freightmove.board.relist_cooldown_hours');
        $nextAllowedAt = $job->relisted_at?->addHours($cooldown);

        if ($cooldown > 0 && $nextAllowedAt?->isFuture()) {
            return ApiResponse::error(
                'This load was relisted recently. You can bump it again '
                    .$nextAllowedAt->diffForHumans().'.',
                ['next_relist_at' => $nextAllowedAt->toIso8601String()],
                429,
            );
        }

        $job->update([
            'relisted_at' => now(),
            'updated_by' => $request->user()->id,
        ]);

        return ApiResponse::success(
            new FreightJobResource($job->loadCount('quotes')),
            'Load bumped back to the top of the board.',
        );
    }

    /**
     * POST /api/v1/shipper/jobs/{job}/complete
     *
     * Closes out a booked load. This was the missing step in the lifecycle:
     * nothing could reach `completed`, so the "Completed" filter, the admin
     * count and every completed-jobs figure were reporting on a state the
     * application had no way to enter.
     */
    public function complete(Request $request, FreightJob $job): JsonResponse
    {
        $this->authorize('complete', $job);

        $job->update([
            'status' => JobStatus::Completed,
            'updated_by' => $request->user()->id,
        ]);

        $carrier = $job->acceptance?->carrier;

        // Both sides' track records change the moment a job closes, whether or
        // not anyone writes a review.
        $this->reputation->refresh($request->user());

        if ($carrier) {
            $this->reputation->refresh($carrier);
            $this->notifier->jobCompleted($job, $carrier->id);
        }

        return ApiResponse::success(
            new FreightJobResource($job->loadCount('quotes')),
            'Load marked complete. You can now leave a review.',
        );
    }

    /**
     * POST /api/v1/shipper/jobs/{job}/cancel
     */
    public function cancel(Request $request, FreightJob $job): JsonResponse
    {
        $this->authorize('cancel', $job);

        $job->update([
            'status' => JobStatus::Cancelled,
            'updated_by' => $request->user()->id,
        ]);

        return ApiResponse::success(
            new FreightJobResource($job->loadCount('quotes')),
            'Load cancelled.',
        );
    }
}
