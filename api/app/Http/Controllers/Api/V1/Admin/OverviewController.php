<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\JobStatus;
use App\Enums\QuoteStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\VerificationStatus;
use App\Http\Controllers\Controller;
use App\Models\FreightJob;
use App\Models\JobQuote;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\VerificationDocument;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The admin console's headline numbers.
 *
 * Chosen to answer the questions an operator of a two-sided marketplace
 * actually has: is supply keeping up with demand, is anything stuck waiting on
 * us, and how is the cut-over from the old platform going.
 */
class OverviewController extends Controller
{
    /** The window for "recent" figures. */
    private const RECENT_DAYS = 30;

    /**
     * GET /api/v1/admin/overview
     */
    public function __invoke(Request $request): JsonResponse
    {
        $since = now()->subDays(self::RECENT_DAYS);

        return ApiResponse::success([
            'users' => [
                'shippers' => User::where('role', UserRole::Shipper)->count(),
                'carriers' => User::where('role', UserRole::Carrier)->count(),
                'suspended' => User::where('status', UserStatus::Suspended)->count(),
                'new_this_month' => User::where('created_at', '>=', $since)->count(),
            ],

            'jobs' => [
                'total' => FreightJob::count(),
                'open' => FreightJob::published()->count(),
                'completed' => FreightJob::where('status', JobStatus::Completed)->count(),
                'disputed' => FreightJob::where('status', JobStatus::Disputed)->count(),
                'new_this_month' => FreightJob::where('created_at', '>=', $since)->count(),
            ],

            'quotes' => [
                'total' => JobQuote::count(),
                'pending' => JobQuote::where('status', QuoteStatus::Pending)->count(),
                'accepted' => JobQuote::where('status', QuoteStatus::Accepted)->count(),
                'new_this_month' => JobQuote::where('created_at', '>=', $since)->count(),
            ],

            'marketplace' => $this->marketplaceHealth($since),
            'queues' => [
                'documents_awaiting_review' => VerificationDocument::awaitingReview()->count(),
                'open_tickets' => SupportTicket::open()->count(),
            ],
            'migration' => $this->migrationProgress(),
        ]);
    }

    /**
     * Whether the two sides are actually meeting.
     *
     * A marketplace can look busy on both counts and still be failing if the
     * two never connect, so these are ratios rather than totals.
     *
     * @return array<string, mixed>
     */
    private function marketplaceHealth(\DateTimeInterface $since): array
    {
        $recentJobs = FreightJob::where('created_at', '>=', $since)->count();
        $quotedJobs = FreightJob::where('created_at', '>=', $since)->has('quotes')->count();
        $bookedJobs = FreightJob::where('created_at', '>=', $since)->has('acceptance')->count();

        return [
            'window_days' => self::RECENT_DAYS,
            'loads_posted' => $recentJobs,
            // The number that matters most: a load nobody quotes on is a
            // shipper who does not come back.
            'loads_with_a_quote' => $quotedJobs,
            'loads_booked' => $bookedJobs,
            'quote_rate' => $this->percentage($quotedJobs, $recentJobs),
            'booking_rate' => $this->percentage($bookedJobs, $recentJobs),
            'average_quotes_per_load' => $recentJobs > 0
                ? round(
                    JobQuote::whereHas('job', fn ($q) => $q->where('created_at', '>=', $since))->count()
                        / $recentJobs,
                    1,
                )
                : 0.0,
            // Never quoted, still open — the loads to chase carriers about.
            'open_without_quotes' => FreightJob::published()->doesntHave('quotes')->count(),
        ];
    }

    /**
     * How the cut-over is going.
     *
     * The three flags in config/freightmove.php all hinge on these numbers, so
     * they are on the dashboard rather than buried in a migration report.
     *
     * @return array<string, mixed>
     */
    private function migrationProgress(): array
    {
        $legacyCarriers = User::where('role', UserRole::Carrier)->whereNotNull('legacy_id')->count();

        return [
            'legacy_users' => User::whereNotNull('legacy_id')->count(),
            'legacy_users_signed_in' => User::whereNotNull('legacy_id')
                ->whereNotNull('password_changed_at')
                ->count(),
            'legacy_carriers' => $legacyCarriers,
            // Each of these gates a config flag that is currently off. See
            // config/freightmove.php for what turning it on would cost today.
            'carriers_verified' => UserProfile::whereHas(
                'user',
                fn ($q) => $q->where('role', UserRole::Carrier)
            )->where('verification_status', VerificationStatus::Verified)->count(),
            'carriers_with_active_subscription' => User::where('role', UserRole::Carrier)
                ->whereHas('subscriptions', fn ($q) => $q
                    ->where('status', '!=', 'cancelled')
                    ->where(fn ($inner) => $inner->whereNull('ends_on')->orWhere('ends_on', '>=', today())))
                ->count(),
            'gates' => [
                'verification_required_to_quote' => (bool) config('freightmove.verification.require_to_quote'),
                'subscription_required_to_quote' => (bool) config('freightmove.quoting.require_subscription'),
            ],
        ];
    }

    private function percentage(int $part, int $whole): float
    {
        return $whole > 0 ? round(($part / $whole) * 100, 1) : 0.0;
    }
}
