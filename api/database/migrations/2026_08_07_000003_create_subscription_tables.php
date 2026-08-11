<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recurring carrier subscriptions, carried over from the legacy
 * `subscription_master`, `subscription_details` and `paypal_transaction`
 * tables.
 *
 * The Phase 2 schema in docs/05-database-schema.md only models per-job
 * `payments`; it has nowhere to record who is on a paid plan or when their
 * access lapses. These three tables fill that gap so the legacy billing history
 * (90 subscription periods, 69 completed PayPal payments) survives the move.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_id', 50)->nullable()->unique();
            $table->string('name');
            $table->decimal('price', 10, 2);
            $table->string('currency', 3)->default('AUD');
            $table->unsignedSmallInteger('interval_months');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_id', 50)->nullable()->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16)->default('active');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            // Legacy 'Free' periods carry no transaction reference.
            $table->string('gateway_reference', 100)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('ends_on');
        });

        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_id', 50)->nullable()->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subscription_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('gateway', 32)->default('paypal');
            $table->string('gateway_reference', 100)->nullable();
            $table->string('payer_name')->nullable();
            $table->string('payer_email')->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->string('currency', 3)->default('AUD');
            $table->string('status', 32)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('paid_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('subscription_plans');
    }
};
