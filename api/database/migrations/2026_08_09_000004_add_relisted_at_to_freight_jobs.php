<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gap G6 (docs/10-domain-rules.md).
 *
 * The legacy site bumped a load back onto the board by touching `date_updated`,
 * which makes an edit and a relist indistinguishable — you cannot tell whether a
 * load is genuinely fresh or someone fixed a typo. A dedicated column keeps them
 * apart, and lets the board order by "recently relisted" honestly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('freight_jobs', function (Blueprint $table) {
            $table->timestamp('relisted_at')->nullable()->after('visibility');
            $table->index('relisted_at');
        });
    }

    public function down(): void
    {
        Schema::table('freight_jobs', function (Blueprint $table) {
            $table->dropIndex(['relisted_at']);
            $table->dropColumn('relisted_at');
        });
    }
};
