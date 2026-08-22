<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Splits the display name back into its parts.
 *
 * The legacy `shipper` table stored `first_name` and `last_name` separately and
 * every form asked for both. The importer joined them with a space into
 * `users.name` (ImportLegacyData line 278), which was fine for display and
 * lossy for everything else — there is no way back to "which part is the given
 * name" once they are one string.
 *
 * `name` stays, because it is what Laravel, the mailer and every existing view
 * read. It is now **derived**: User::syncName() rebuilds it whenever the parts
 * change, so the two can never disagree.
 *
 * The backfill splits on the first space. That is a heuristic and it will get
 * some multi-part surnames wrong ("Mary Jane Van Der Berg" becomes Mary +
 * "Jane Van Der Berg"), which is why re-running `legacy:import` is the better
 * fix where the original rows are still available — it now populates both
 * columns from source.
 *
 * Done in PHP rather than SQL: SUBSTRING_INDEX and LOCATE are MySQL spellings,
 * and this application's default connection is sqlite. The splitting itself
 * lives on the model, so this and `legacy:import` cannot disagree about where
 * one name ends and the next begins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name', 100)->nullable()->after('name');
            $table->string('last_name', 100)->nullable()->after('first_name');
        });

        // Chunked, but this is a few hundred rows on the largest install.
        DB::table('users')
            ->select('id', 'name')
            ->whereNotNull('name')
            ->where('name', '!=', '')
            ->orderBy('id')
            ->chunkById(500, function ($users) {
                foreach ($users as $user) {
                    [$first, $last] = User::splitName($user->name);

                    DB::table('users')->where('id', $user->id)->update([
                        'first_name' => $first !== '' ? $first : null,
                        'last_name' => $last,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name']);
        });
    }
};
