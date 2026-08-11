<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cached driving distance between two suburbs (docs/10-domain-rules.md R6).
 *
 * Every lookup on the legacy site cost a Google Distance Matrix call, so the
 * answer was cached against the suburb pair and reused. 663 rows built up that
 * way; they import into this table and stay useful.
 *
 * Two departures from the legacy `distance_calculator`:
 *
 * 1. Values are stored as numbers. The legacy table stored whatever the API
 *    happened to return — mostly pre-formatted display strings like
 *    "3,616 km" and "1 day 15 hours", occasionally the raw metre/second
 *    integers from an earlier code path. That is unusable for sorting or
 *    filtering, so the importer parses both shapes into canonical units and
 *    keeps the original text alongside for display.
 * 2. The pair is a real unique constraint on foreign keys, not two loose
 *    varchar columns that could point at suburbs which no longer exist.
 *
 * The cache is directional. Legacy held both directions separately for 20
 * pairs and the figures differ (one-way roads, ferry legs), so A->B is not
 * assumed to equal B->A.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_distances', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_id', 50)->nullable()->unique();

            $table->foreignId('pickup_suburb_id')->constrained('suburbs')->cascadeOnDelete();
            $table->foreignId('dropoff_suburb_id')->constrained('suburbs')->cascadeOnDelete();

            // Canonical units. Nullable because a legacy row could in principle
            // carry text we cannot parse; the importer reports those rather
            // than guessing.
            $table->unsignedBigInteger('distance_metres')->nullable();
            $table->unsignedBigInteger('duration_seconds')->nullable();

            // Exactly what the provider said, for display. Google's own wording
            // reads better than anything we would reconstruct from the numbers,
            // and it records the precision we actually have: "3,616 km" was
            // already rounded before we ever saw it.
            $table->string('distance_text', 50)->nullable();
            $table->string('duration_text', 100)->nullable();

            // How often the cached answer was reused, i.e. calls not made.
            $table->unsignedInteger('lookups')->default(0);
            $table->timestamp('last_used_at')->nullable();

            $table->timestamps();

            $table->unique(['pickup_suburb_id', 'dropoff_suburb_id'], 'route_distances_pair_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_distances');
    }
};
