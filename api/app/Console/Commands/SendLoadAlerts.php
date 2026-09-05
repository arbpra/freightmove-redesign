<?php

namespace App\Console\Commands;

use App\Enums\JobStatus;
use App\Models\FreightJob;
use App\Models\LoadAlert;
use App\Services\LoadAlertService;
use Illuminate\Console\Command;

/**
 * Load alerts, by hand.
 *
 * Three jobs, and the first is the important one:
 *
 *   --dry-run   how many carriers a load would reach, sending nothing. Run
 *               this before switching alerts on. The answer is roughly 295,
 *               and roughly 11,800 a month at current posting rates.
 *   --job=      alert one specific load, for a post made while alerts were off
 *   --retry     re-send only the ledger rows that recorded a failure
 *
 * Safe to run repeatedly: the ledger's unique index means a carrier who has
 * already been told about a load is never told twice.
 */
class SendLoadAlerts extends Command
{
    protected $signature = 'loads:alert
                            {--job= : Alert carriers about this load id}
                            {--dry-run : Report the reach without sending anything}
                            {--retry : Re-send alerts whose previous attempt failed}';

    protected $description = 'Email carriers about a posted load, or preview the reach';

    public function handle(LoadAlertService $alerts): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $enabled = (bool) config('freightmove.loads.alerts.enabled');
        $audience = (string) config('freightmove.loads.alerts.audience');

        $this->line('');
        $this->line('  alerts    : '.($enabled ? 'ON' : 'OFF'));
        $this->line('  audience  : '.$audience);
        $test = trim((string) config('freightmove.loads.alerts.test_recipient'));

        if ($test !== '') {
            // The single most important line here. Two years of the previous
            // site "sending" bulk email that only ever reached one developer
            // is what this is guarding against.
            $this->line('  reach     : 1  ->  '.$test);
            $this->warn('  TEST MODE — carriers get nothing. Clear');
            $this->warn('  FM_LOAD_ALERT_TEST_RECIPIENT to reach all '.$alerts->audience()->count().' carriers.');
        } else {
            $this->line('  reach     : '.$alerts->audience()->count().' carriers');
        }
        $this->line('  transport : '.config('mail.default'));

        if (! $enabled) {
            $this->warn('  FM_LOAD_ALERTS is false — no carrier alerts will be sent.');
        }

        if ($dryRun) {
            $this->warn('  DRY RUN — nothing will be sent or recorded.');

            // A preview that reports zero because the feature is off answers
            // the wrong question. The one being asked is "what happens if I
            // turn this on", so the preview runs as though it were on.
            if (! $enabled) {
                config(['freightmove.loads.alerts.enabled' => true]);
                $this->warn('  Previewing as though FM_LOAD_ALERTS were true.');
            }
        }

        $this->line('');

        if ($this->option('retry')) {
            return $this->retryFailures($alerts, $dryRun);
        }

        $job = $this->option('job')
            ? FreightJob::find((int) $this->option('job'))
            : FreightJob::where('status', JobStatus::Published)->latest('id')->first();

        if (! $job) {
            $this->error('  No such load.');

            return self::FAILURE;
        }

        $this->line("  load      : #{$job->id}  {$job->title}");

        $result = $alerts->dispatchFor($job, $dryRun);

        $verb = $dryRun ? 'would email' : 'emailed';
        $this->line('');
        $this->line($result['test_recipient']
            ? "  carriers  : 0  (test mode — one preview {$verb} to {$result['test_recipient']})"
            : "  carriers  : {$result['carriers']}  ({$verb})");
        $this->line("  skipped   : {$result['skipped']}  (already told, or the send failed)");
        $this->line('  operator  : '.($result['admin'] ? 'notified' : 'not sent'));
        $this->line('');

        return self::SUCCESS;
    }

    /**
     * Failures only.
     *
     * The ledger keeps a row with `sent_at` null and a reason, so a transport
     * outage can be re-run without re-mailing everyone who did receive it.
     */
    private function retryFailures(LoadAlertService $alerts, bool $dryRun): int
    {
        $failed = LoadAlert::whereNull('sent_at')->whereNotNull('failure')->with('job')->get();

        $this->line("  failed rows: {$failed->count()}");

        if ($dryRun || $failed->isEmpty()) {
            $this->line('');

            return self::SUCCESS;
        }

        // Clearing the claim lets dispatchFor re-take it; the unique index
        // still protects anyone who did get the message.
        $jobs = $failed->pluck('job')->filter()->unique('id');
        LoadAlert::whereIn('id', $failed->pluck('id'))->delete();

        $sent = 0;

        foreach ($jobs as $job) {
            $sent += $alerts->dispatchFor($job)['carriers'];
        }

        $this->line("  re-sent    : {$sent}");
        $this->line('');

        return self::SUCCESS;
    }
}
