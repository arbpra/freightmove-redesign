<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives subscription plans a stable code, and room for the free trial.
 *
 * The legacy `subscription_master` has random numeric ids and three rows; the
 * live pricing page advertises **four** offerings, because the two-month free
 * trial exists only as `subscription_details.subscription_type = 4` with no
 * plan row behind it at all.
 *
 * The importer previously resolved `subscription_type` by taking it as a 1-based
 * index into the plan list, which is only correct if that list comes back in the
 * order the codes assume. It did not: quarterly and annual came back swapped, so
 * six carriers who paid $184.99 were recorded on the $699.90 plan and one who
 * paid $699.90 on the $184.99 one. `code` is the fix — plans are now addressed
 * by a name that means something rather than by their position in a result set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->string('code', 32)->nullable()->unique()->after('legacy_id');
            $table->boolean('is_trial')->default(false)->after('is_active');
            // What the trial is "valued at" on the pricing page, and the saving
            // shown against monthly on the longer plans.
            $table->decimal('compare_at_price', 10, 2)->nullable()->after('price');
            $table->unsignedSmallInteger('sort_order')->default(0)->after('is_trial');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn(['code', 'is_trial', 'compare_at_price', 'sort_order']);
        });
    }
};
