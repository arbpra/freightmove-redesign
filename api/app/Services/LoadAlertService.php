<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Mail\LoadPostedAdmin;
use App\Mail\NewLoadAvailable;
use App\Models\FreightJob;
use App\Models\LoadAlert;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Telling carriers a load has been posted.
 *
 * The previous site intended this and never delivered it. `load_master` carries
 * a `bulk_email` flag and `email_send` exists to record the fan-out, but the
 * loop in `resources/views/bulk-email.blade.php` is commented out — every load
 * sent one message to a developer's own address and then marked itself done.
 * The 7,888 `QuotationMail` jobs it queued at some other point still sit in the
 * `jobs` table with `attempts = 0`, because no worker was ever run. No carrier
 * has received one of these.
 *
 * That history sets the shape of this class:
 *
 * - **A fan-out is not one send.** 295 carriers is 295 messages, any of which
 *   can fail alone. Per-recipient ledger rows are what let a retry resume
 *   rather than restart, and restarting is how somebody gets the same load
 *   three times.
 * - **A flag on the load is not enough.** `bulk_email = 1` says "we tried",
 *   which is exactly the claim that turned out to be false.
 * - **Volume is a product decision, not a default.** ~11,800 messages a month
 *   to an audience that has never had any is a sending-reputation event, and
 *   the domain that carries these also carries password resets. Hence the
 *   `enabled` switch, the audience filter and the per-carrier opt-out.
 */
class LoadAlertService
{
    /**
     * Notify the operator, and every carrier in the audience.
     *
     * Returns how many carriers were emailed. Safe to call twice on the same
     * load: the ledger's unique index means the second call sends nothing.
     *
     * @return array{carriers: int, skipped: int, admin: bool, test_recipient: ?string}
     */
    public function dispatchFor(FreightJob $job, bool $dryRun = false): array
    {
        $result = ['carriers' => 0, 'skipped' => 0, 'admin' => false, 'test_recipient' => null];

        if (! config('freightmove.loads.alerts.enabled')) {
            // The operator still hears about the load; the view says plainly
            // that no carrier alerts went out, so "quiet" is never ambiguous.
            $result['admin'] = $this->notifyOperator($job, 0, $dryRun);

            return $result;
        }

        // Staging: one message to one address instead of the fan-out.
        if ($to = $this->testRecipient()) {
            $result['carriers'] = $this->sendPreviewTo($job, $to, $dryRun) ? 1 : 0;
            $result['test_recipient'] = $to;
            $result['admin'] = $this->notifyOperator($job, $result['carriers'], $dryRun);

            return $result;
        }

        $batch = max(1, (int) config('freightmove.loads.alerts.batch_size', 50));

        $this->audience()
            ->whereDoesntHave('loadAlerts', fn (Builder $q) => $q->where('freight_job_id', $job->id))
            ->chunkById($batch, function ($carriers) use ($job, $dryRun, &$result) {
                foreach ($carriers as $carrier) {
                    $this->sendTo($job, $carrier, $dryRun)
                        ? $result['carriers']++
                        : $result['skipped']++;
                }
            });

        $result['admin'] = $this->notifyOperator($job, $result['carriers'], $dryRun);

        return $result;
    }

    /**
     * Who hears about a new load.
     *
     * `wants_load_alerts` is checked here rather than at send time so an
     * opt-out costs nothing per message and cannot be forgotten by a caller.
     */
    public function audience(): Builder
    {
        $query = User::query()
            ->where('role', UserRole::Carrier)
            ->where('status', UserStatus::Active)
            ->where('wants_load_alerts', true)
            ->whereNotNull('email');

        return match (config('freightmove.loads.alerts.audience')) {
            // Makes the alert part of what the subscription buys.
            'subscribed' => $query->whereHas('subscriptions', fn ($q) => $q->current()),
            'verified' => $query->whereHas('carrier', fn ($q) => $q->where('is_verified', true)),
            default => $query,
        };
    }

    /**
     * One carrier, one load.
     *
     * The ledger row is claimed before the send, so a crash mid-send costs a
     * message rather than duplicating one. A failure stamps the reason and
     * leaves `sent_at` null, which is what makes a retry sweep possible
     * without re-mailing everyone who already received it.
     */
    private function sendTo(FreightJob $job, User $carrier, bool $dryRun): bool
    {
        if ($dryRun) {
            return true;
        }

        try {
            $alert = LoadAlert::create([
                'freight_job_id' => $job->id,
                'user_id' => $carrier->id,
            ]);
        } catch (QueryException) {
            // Already claimed by a concurrent run. Not an error.
            return false;
        }

        try {
            $mail = new NewLoadAvailable($job, $carrier);

            if (config('freightmove.mail.queue')) {
                Mail::to($carrier->email)->queue($mail);
            } else {
                Mail::to($carrier->email)->send($mail);
            }

            $alert->forceFill(['sent_at' => now()])->save();

            return true;
        } catch (Throwable $e) {
            // Kept, not deleted: the row is the record that this carrier is
            // still owed the alert, and `loads:alert --retry` reads it.
            $alert->forceFill(['failure' => mb_substr($e->getMessage(), 0, 190)])->save();

            Log::error('Could not send a load alert.', [
                'job' => $job->id,
                'carrier' => $carrier->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /** A single valid address to divert carrier alerts to, or null. */
    private function testRecipient(): ?string
    {
        $to = trim((string) config('freightmove.loads.alerts.test_recipient'));

        return filter_var($to, FILTER_VALIDATE_EMAIL) !== false ? $to : null;
    }

    /**
     * The carrier email, sent once, to a test address.
     *
     * Rendered for a real carrier from the audience so the content and the
     * unsubscribe link are the genuine article rather than an approximation —
     * a preview that differs from the real thing checks nothing. Falls back to
     * an unsaved stand-in when the audience is empty, so this still works on a
     * fresh database.
     *
     * Not written to the ledger: those rows mean "this carrier was told".
     */
    private function sendPreviewTo(FreightJob $job, string $to, bool $dryRun): bool
    {
        if ($dryRun) {
            return true;
        }

        $carrier = $this->audience()->first() ?? new User(['email' => $to]);

        if (! $carrier->exists) {
            $carrier->id = 0;
        }

        try {
            $mail = new NewLoadAvailable($job, $carrier);

            if (config('freightmove.mail.queue')) {
                Mail::to($to)->queue($mail);
            } else {
                Mail::to($to)->send($mail);
            }

            return true;
        } catch (Throwable $e) {
            Log::error('Could not send the load alert preview.', [
                'job' => $job->id,
                'to' => $to,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /** One message per load, whatever the fan-out did. */
    private function notifyOperator(FreightJob $job, int $carriersNotified, bool $dryRun): bool
    {
        $to = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('freightmove.loads.alerts.admin_recipient')),
        ), fn (string $a) => filter_var($a, FILTER_VALIDATE_EMAIL) !== false));

        if ($to === [] || $dryRun) {
            return false;
        }

        try {
            $mail = new LoadPostedAdmin($job, $carriersNotified);

            if (config('freightmove.mail.queue')) {
                Mail::to($to)->queue($mail);
            } else {
                Mail::to($to)->send($mail);
            }

            return true;
        } catch (Throwable $e) {
            Log::error('Could not send the load posted notice.', [
                'job' => $job->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
