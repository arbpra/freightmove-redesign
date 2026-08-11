<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\JobStatus;
use App\Http\Controllers\Controller;
use App\Models\FreightJob;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Every load on the platform, for support and moderation.
 *
 * Read-only on purpose. An admin needs to see what is happening — to answer
 * "where is my load?" on the phone — but editing someone's freight behind their
 * back produces a record neither party recognises. Moderation that needs to
 * change something (cancelling a fraudulent listing) should be its own explicit
 * action with its own audit trail, not a general-purpose edit.
 */
class JobOversightController extends Controller
{
    private const MAX_PER_PAGE = 100;

    /**
     * GET /api/v1/admin/jobs
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(JobStatus::values())],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ]);

        $jobs = FreightJob::query()
            ->with(['shipper:id,name,email', 'shipper.profile:id,user_id,company_name'])
            ->withCount('quotes')
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($validated['search'] ?? null, function ($query, string $term) {
                $escaped = addcslashes($term, '%_\\');

                $query->where(function ($inner) use ($escaped) {
                    $inner->where('title', 'like', "%{$escaped}%")
                        ->orWhere('pickup_location', 'like', "%{$escaped}%")
                        ->orWhere('delivery_location', 'like', "%{$escaped}%");
                });
            })
            ->latest()
            ->paginate($validated['per_page'] ?? 25)
            ->withQueryString();

        return ApiResponse::success([
            'items' => array_map(fn (FreightJob $job) => [
                'id' => $job->id,
                'title' => $job->title,
                'status' => $job->status->value,
                'lane' => "{$job->pickup_location} → {$job->delivery_location}",
                'quotes_count' => $job->quotes_count,
                'shipper' => [
                    'id' => $job->shipper?->id,
                    'name' => $job->shipper?->profile?->company_name ?: $job->shipper?->name,
                    'email' => $job->shipper?->email,
                ],
                'is_legacy' => $job->legacy_id !== null,
                'created_at' => $job->created_at?->toIso8601String(),
            ], $jobs->items()),
            'meta' => [
                'current_page' => $jobs->currentPage(),
                'last_page' => $jobs->lastPage(),
                'per_page' => $jobs->perPage(),
                'total' => $jobs->total(),
            ],
        ]);
    }
}
