<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\FreightJob;
use App\Models\JobQuote;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\VerificationDocument;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OverviewController extends Controller
{
    /**
     * GET /api/v1/admin/overview
     */
    public function __invoke(Request $request): JsonResponse
    {
        return ApiResponse::success([
            'users' => [
                'shippers' => User::where('role', UserRole::Shipper)->count(),
                'carriers' => User::where('role', UserRole::Carrier)->count(),
            ],
            'jobs' => [
                'total' => FreightJob::count(),
                'open' => FreightJob::published()->count(),
                'completed' => FreightJob::where('status', JobStatus::Completed)->count(),
                'disputed' => FreightJob::where('status', JobStatus::Disputed)->count(),
            ],
            'quotes' => JobQuote::count(),
            'awaiting_approval' => VerificationDocument::awaitingReview()->count(),
            'open_tickets' => SupportTicket::open()->count(),
        ]);
    }
}
