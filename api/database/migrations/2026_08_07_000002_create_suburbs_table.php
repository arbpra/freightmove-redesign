<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Australian suburb reference data, carried over from the legacy
 * `suburb_master` table (~15,400 rows).
 *
 * The legacy schema stored only a suburb id on each load and resolved the name
 * at read time. New freight jobs store resolved location strings instead, so
 * this table exists to power location autocomplete and to let the importer turn
 * legacy suburb ids back into real place names.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suburbs', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_id', 50)->nullable()->unique();
            $table->string('name', 200);
            $table->string('state', 10);
            $table->timestamps();

            // Autocomplete queries filter by state then match on name.
            $table->index(['state', 'name']);
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suburbs');
    }
};
