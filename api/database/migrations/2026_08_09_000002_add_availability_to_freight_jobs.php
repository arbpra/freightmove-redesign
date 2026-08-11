<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gap G1 (docs/10-domain-rules.md).
 *
 * The legacy `load_master.availability` int is the shipper's urgency signal and
 * is distinct from the job's lifecycle `status`: a load can be `published` and
 * still be months away. Carriers filter on it, so it has to be a column rather
 * than prose.
 *
 * All four legacy values are in live use across the 103 imported loads, and
 * every one was dropped on the first import because nothing could receive it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('freight_jobs', function (Blueprint $table) {
            $table->string('availability', 20)->nullable()->after('delivery_date');
            $table->index(['status', 'availability']);
        });
    }

    public function down(): void
    {
        Schema::table('freight_jobs', function (Blueprint $table) {
            $table->dropIndex(['status', 'availability']);
            $table->dropColumn('availability');
        });
    }
};
