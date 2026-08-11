<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `legacy_id` holds the pre-launch site's primary key (a random numeric string,
 * not an integer). It is what makes `php artisan legacy:import` re-runnable:
 * the importer upserts against it, so re-importing a fresher dump at go-live
 * updates the existing rows instead of duplicating them.
 *
 * Nullable, because rows created natively on the new platform have no legacy
 * counterpart. Unique, because the upsert depends on it identifying one row.
 */
return new class extends Migration
{
    /** Tables that receive rows from the legacy database. */
    private const TABLES = ['users', 'freight_jobs', 'job_quotes', 'blog_posts'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->string('legacy_id', 50)->nullable()->unique()->after('id');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropUnique([$blueprint->getTable().'_legacy_id_unique']);
                $blueprint->dropColumn('legacy_id');
            });
        }
    }
};
