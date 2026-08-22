<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restores the load detail fields the legacy form collected.
 *
 * `load_master` held quantity, length, width, height and weight, and the
 * post-a-load form asked for all five. The V2 schema had no home for them, so
 * the importer folded them into the description as prose:
 *
 *     Dimensions (mm): L 2400 x W 1200
 *     Quantity: 3 pallets
 *
 * Nothing was lost, but nothing could be queried either — a carrier cannot
 * filter for what fits on their trailer when the height is a sentence. These
 * are columns again.
 *
 * Weight moves from tonnes to kilograms in the same pass. The legacy field was
 * free text with no unit recorded, so `weight_tons` was always an inference
 * (`ImportLegacyData::weightTons` divides by 1000 above a 50t threshold).
 * Kilograms are what the form asks for and what shippers type, so kilograms are
 * what is stored; tonnes are derived for display. One stored number, no drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('freight_jobs', function (Blueprint $table) {
            // Free text, as it was in legacy: "3", "3 pallets", "2 x crates".
            // Forcing an integer here would reject values already in the data.
            $table->string('quantity', 50)->nullable()->after('description');

            // Millimetres, matching the legacy form's stated unit.
            $table->unsignedInteger('length_mm')->nullable()->after('quantity');
            $table->unsignedInteger('width_mm')->nullable()->after('length_mm');
            $table->unsignedInteger('height_mm')->nullable()->after('width_mm');

            $table->unsignedInteger('weight_kg')->nullable()->after('height_mm');
        });

        // Carry the existing inference across rather than discarding it. Rows
        // where the legacy weight was unparseable stay null, as before.
        DB::table('freight_jobs')
            ->whereNotNull('weight_tons')
            ->update(['weight_kg' => DB::raw('ROUND(weight_tons * 1000)')]);

        Schema::table('freight_jobs', function (Blueprint $table) {
            $table->dropColumn('weight_tons');
        });
    }

    public function down(): void
    {
        Schema::table('freight_jobs', function (Blueprint $table) {
            $table->decimal('weight_tons', 10, 2)->nullable()->after('load_category');
        });

        DB::table('freight_jobs')
            ->whereNotNull('weight_kg')
            ->update(['weight_tons' => DB::raw('weight_kg / 1000')]);

        Schema::table('freight_jobs', function (Blueprint $table) {
            $table->dropColumn(['quantity', 'length_mm', 'width_mm', 'height_mm', 'weight_kg']);
        });
    }
};
