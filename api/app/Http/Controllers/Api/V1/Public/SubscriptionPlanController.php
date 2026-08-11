<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use App\Services\SubscriptionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * The carrier pricing table.
 *
 * Public: the whole point of the page is to be read before signing up.
 */
class SubscriptionPlanController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /**
     * GET /api/v1/public/subscription-plans
     */
    public function __invoke(): JsonResponse
    {
        $plans = SubscriptionPlan::where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $monthly = $plans->firstWhere('code', 'monthly');

        return ApiResponse::success([
            'items' => $plans->map(function (SubscriptionPlan $plan) use ($monthly) {
                $perMonth = $plan->monthlyEquivalent();

                return [
                    'code' => $plan->code,
                    'name' => $plan->name,
                    'price' => (float) $plan->price,
                    'compare_at_price' => $plan->compare_at_price !== null
                        ? (float) $plan->compare_at_price
                        : null,
                    'currency' => $plan->currency ?? 'AUD',
                    'interval_months' => $plan->interval_months,
                    'is_trial' => $plan->is_trial,
                    'per_month' => $perMonth,
                    // Computed rather than written into the copy, so the figure
                    // on the page can never drift from the prices beside it.
                    'saving_per_month' => $monthly && ! $plan->is_trial && $plan->interval_months > 1
                        ? round(((float) $monthly->price) - $perMonth, 2)
                        : null,
                ];
            })->all(),
            'trial_offer_open' => $this->subscriptions->trialOfferIsOpen(),
        ]);
    }
}
