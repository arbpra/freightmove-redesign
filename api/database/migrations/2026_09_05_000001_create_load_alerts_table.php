<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which carrier was told about which load.
 *
 * The legacy site built `email_send` (job, customer, date) for exactly this and
 * never wrote a row to it — the fan-out loop that would have filled it is
 * commented out in `resources/views/bulk-email.blade.php`, and the 7,888
 * `QuotationMail` jobs it queued instead sit in the `jobs` table with
 * `attempts = 0` because no worker ever ran. Carriers have never received one
 * of these.
 *
 * That history is the reason this table exists rather than a `sent` flag on the
 * load: a fan-out to 295 people is not one send, it is 295, and any of them can
 * fail on its own. Per-recipient rows are what make a retry resume instead of
 * starting again — and starting again is how people get the same load twice.
 *
 * The unique index is the guard, not the SELECT before it. A retried queue job
 * and a re-publish race each other; here the second INSERT loses and that send
 * is skipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('load_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('freight_job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->string('failure', 191)->nullable();
            $table->timestamps();

            $table->unique(['freight_job_id', 'user_id'], 'load_alerts_unique');
            $table->index(['sent_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            /*
             * Whether this carrier wants load alerts.
             *
             * Defaults to true so the feature can be switched on without
             * asking 295 people to opt in first — they joined a load board, so
             * "there is a new load" is the thing they signed up for.
             *
             * The unsubscribe is not optional politeness. The Spam Act 2003
             * requires a functional opt-out on commercial electronic messages
             * sent to Australian addresses, and at roughly 390 messages a day
             * the alternative to an unsubscribe link is a spam complaint —
             * which is scored against the domain that also carries password
             * resets and quote notifications.
             */
            $table->boolean('wants_load_alerts')->default(true)->after('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('load_alerts');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('wants_load_alerts');
        });
    }
};
