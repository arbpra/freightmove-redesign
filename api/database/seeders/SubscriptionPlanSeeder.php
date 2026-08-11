<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

/**
 * The carrier subscription plans, exactly as advertised on
 * https://www.freightmove.au/carriers-subscription.
 *
 * Three of the four exist in the legacy `subscription_master`; the free trial
 * does not, despite being on the page and despite nineteen carriers having been
 * given one. It is seeded here so it is a real plan with a real price rather
 * than a `subscription_type` code with nothing behind it.
 *
 * Matched on `code`, so re-running is safe and the legacy importer can find
 * each plan by name rather than by position.
 */
class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        $monthly = 64.99;

        $plans = [
            [
                'code' => 'trial',
                'name' => 'Free Trial',
                'price' => 0.00,
                // "Valued at $64.99" on the pricing page.
                'compare_at_price' => $monthly,
                'interval_months' => 2,
                'is_trial' => true,
                'sort_order' => 0,
            ],
            [
                'code' => 'monthly',
                'name' => 'Monthly Subscription',
                'price' => $monthly,
                'compare_at_price' => null,
                'interval_months' => 1,
                'is_trial' => false,
                'sort_order' => 1,
            ],
            [
                'code' => 'quarterly',
                'name' => 'Quarterly Subscription',
                'price' => 184.99,
                // Saves $3.33 a month against monthly, as the page says.
                'compare_at_price' => $monthly * 3,
                'interval_months' => 3,
                'is_trial' => false,
                'sort_order' => 2,
            ],
            [
                'code' => 'annual',
                'name' => 'Annual Subscription',
                'price' => 699.90,
                // Saves $6.66 a month.
                'compare_at_price' => $monthly * 12,
                'interval_months' => 12,
                'is_trial' => false,
                'sort_order' => 3,
            ],
        ];

        foreach ($plans as $plan) {
            // Keyed on interval as well as code, so a plan already imported from
            // `subscription_master` is adopted rather than duplicated: the
            // legacy rows arrive first and carry the legacy_id worth keeping.
            $existing = SubscriptionPlan::where('code', $plan['code'])->first()
                ?? SubscriptionPlan::whereNull('code')
                    ->where('interval_months', $plan['interval_months'])
                    ->where('price', $plan['price'])
                    ->first();

            if ($existing) {
                $existing->forceFill($plan + ['is_active' => true])->save();

                continue;
            }

            SubscriptionPlan::create($plan + ['currency' => 'AUD', 'is_active' => true]);
        }
    }
}
