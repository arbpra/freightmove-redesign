<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gap G2 (docs/10-domain-rules.md).
 *
 * The legacy schema stored these as comma-separated display names in a single
 * text column, with no lookup table anywhere to resolve against. **67 of 103
 * live loads carry more than one truck type**, so a single-value column keeps
 * the first and silently discards the rest — which is what the first import
 * did, pushing the remainder into the description as prose.
 *
 * Real pivots make the extra values filterable, which is the whole point: the
 * carrier load board's primary filter is "trailers I actually run".
 *
 * `freight_jobs.load_category` and `trailer_type_required` stay as denormalised
 * "primary" values for list display, but these pivots are the source of truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 120)->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('truck_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 120)->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('category_freight_job', function (Blueprint $table) {
            $table->foreignId('freight_job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();

            $table->primary(['freight_job_id', 'category_id']);
            // Board filtering reads category -> jobs, the opposite direction to
            // the primary key, so it needs its own index.
            $table->index('category_id');
        });

        Schema::create('freight_job_truck_type', function (Blueprint $table) {
            $table->foreignId('freight_job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('truck_type_id')->constrained()->cascadeOnDelete();

            $table->primary(['freight_job_id', 'truck_type_id']);
            $table->index('truck_type_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freight_job_truck_type');
        Schema::dropIfExists('category_freight_job');
        Schema::dropIfExists('truck_types');
        Schema::dropIfExists('categories');
    }
};
