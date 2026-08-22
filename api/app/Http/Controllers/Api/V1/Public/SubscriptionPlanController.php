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
                    //
                    // Done in whole cents with banker's rounding, which is the
                    // only rule that reproduces both figures the live pricing
                    // page advertises:
                    //
                    //   quarterly  64.99 - 184.99/3  = 3.326667 -> 3.33
                    //   annual     64.99 - 699.90/12 = 6.665000 -> 6.66
                    //
                    // Annual lands exactly on half a cent. Rounding up there
                    // would advertise a saving a third of a cent larger than
                    // the carrier actually receives, and a price claim that
                    // overstates is the wrong kind of wrong. Half-to-even
                    // sends that half-cent to the customer.
                    //
                    // In cents rather than dollars because 6.665 has no exact
                    // binary form — as a float it sits a hair ABOVE half, so
                    // rounding it directly gives 6.67 whatever mode is asked
                    // for. 666.5 cents is exact, so the rule actually applies.
                    'saving_per_month' => $monthly && ! $plan->is_trial && $plan->interval_months > 1
                        ? round(
                            ((float) $monthly->price) * 100 - ((float) $plan->price) * 100 / $plan->interval_months,
                            0,
                            PHP_ROUND_HALF_EVEN,
                        ) / 100
                        : null,
                ];
            })->all(),
            'trial_offer_open' => $this->subscriptions->trialOfferIsOpen(),
        ]);
    }
}
