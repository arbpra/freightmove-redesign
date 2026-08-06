<?php

namespace App\Http\Controllers\Api\V1\Carrier;

use App\Enums\QuoteStatus;
use App\Http\Controllers\Controller;
use App\Models\FreightJob;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OverviewController extends Controller
{
    /**
     * GET /api/v1/carrier/overview
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $quotes = $user->quotes();

        return ApiResponse::success([
            'open_loads' => FreightJob::published()->count(),
            'quotes' => [
                'total' => $quotes->clone()->count(),
                'pending' => $quotes->clone()->where('status', QuoteStatus::Pending)->count(),
                'accepted' => $quotes->clone()->where('status', QuoteStatus::Accepted)->count(),
            ],
            'fleet_size' => $user->carrier?->vehicleTypes()->active()->count() ?? 0,
            'verification_status' => $user->profile?->verification_status->value,
            'rating' => $user->profile?->rating,
            'unread_notifications' => $user->appNotifications()->unread()->count(),
        ]);
    }
}
