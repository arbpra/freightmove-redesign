<?php

namespace App\Http\Controllers\Api\V1\Shipper;

use App\Enums\JobStatus;
use App\Enums\QuoteStatus;
use App\Http\Controllers\Controller;
use App\Models\FreightJob;
use App\Models\JobQuote;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OverviewController extends Controller
{
    /**
     * GET /api/v1/shipper/overview
     */
    public function __invoke(Request $request): JsonResponse
    {
        $shipperId = $request->user()->id;

        $jobCounts = FreightJob::forShipper($shipperId)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $quotesReceived = JobQuote::whereHas(
            'job',
            fn ($query) => $query->where('shipper_id', $shipperId)
        );

        return ApiResponse::success([
            'jobs' => [
                'total' => $jobCounts->sum(),
                'by_status' => collect(JobStatus::values())
                    ->mapWithKeys(fn (string $status) => [$status => (int) $jobCounts->get($status, 0)]),
            ],
            'quotes' => [
                'received' => $quotesReceived->clone()->count(),
                'pending' => $quotesReceived->clone()->where('status', QuoteStatus::Pending)->count(),
            ],
            'unread_notifications' => $request->user()->appNotifications()->unread()->count(),
        ]);
    }
}
