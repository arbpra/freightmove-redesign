<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records when a user last chose their own password on this platform.
 *
 * Accounts imported from the pre-launch site keep their original bcrypt hash so
 * they can sign in with the password they already have — see
 * docs/09-legacy-data-migration.md. `password_changed_at` stays **null** for
 * them, which is how the app knows to invite them to set a new one after their
 * first successful sign-in.
 *
 * Null therefore means "never chosen here", not "unknown".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('password_changed_at')->nullable()->after('password');
        });

        // Accounts that already existed natively did choose their password here,
        // so backfill them and leave imported rows null.
        DB::table('users')
            ->whereNull('legacy_id')
            ->update(['password_changed_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('password_changed_at');
        });
    }
};
