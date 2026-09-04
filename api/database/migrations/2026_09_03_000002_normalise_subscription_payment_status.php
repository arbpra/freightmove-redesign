<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One spelling of `completed`.
 *
 * The legacy importer passed PayPal's status through verbatim, so the 69
 * imported transactions read `COMPLETED` while everything this application
 * writes reads `completed`.
 *
 * This is **not** a broken query today: MySQL's `utf8mb4_unicode_ci` collation
 * compares case-insensitively, so `where('status', 'completed')` already
 * matches both and no total has ever been wrong. It is a value that leaves the
 * database in two spellings, and that breaks in three places the database
 * cannot see — a strict comparison in PHP, a `status === 'completed'` in the
 * Angular client reading the API's JSON, and any move to a case-sensitive
 * collation. The admin payments screen compares exactly this field.
 *
 * `BINARY` on the predicate matters for the same reason the bug is subtle:
 * without it, `status <> LOWER(status)` is false for every row under a
 * case-insensitive collation and this migration quietly updates nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('subscription_payments')
            ->whereRaw('BINARY status <> BINARY LOWER(status)')
            ->update(['status' => DB::raw('LOWER(status)')]);
    }

    /**
     * Deliberately empty. Restoring mixed-case values would mean recording
     * which rows were shouting, to reinstate a bug.
     */
    public function down(): void {}
};
