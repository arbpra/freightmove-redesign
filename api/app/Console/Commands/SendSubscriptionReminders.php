<?php

namespace App\Console\Commands;

use App\Services\SubscriptionReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * The nightly subscription reminder sweep.
 *
 * Runs from the scheduler; see routes/console.php. Safe to run by hand as often
 * as you like — the ledger in `subscription_reminders` means a second run on
 * the same day sends nothing.
 *
 * `--dry-run` reports exactly what would go out and writes nothing, which is
 * how you should look at it the first time on a server holding real carriers.
 * `--date` moves the sweep's idea of today, so you can see what next month's
 * run will do before it does it.
 */
class SendSubscriptionReminders extends Command
{
    protected $signature = 'subscriptions:remind
                            {--dry-run : Report what would be sent, without sending or recording anything}
                            {--date= : Run as though today were this date (Y-m-d)}';

    protected $description = 'Email carriers whose subscription is about to end, or has ended';

    public function handle(SubscriptionReminderService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $date = $this->option('date');

        try {
            $today = $date ? CarbonImmutable::parse($date)->startOfDay() : CarbonImmutable::today();
        } catch (\Throwable) {
            $this->error("  '{$date}' is not a date. Use Y-m-d.");

            return self::FAILURE;
        }

        $config = (array) config('freightmove.subscriptions.reminders');
        $months = (int) ($config['monthly_months'] ?? 0);
        $floor = $config['ignore_expiries_before'] ?? null;

        $this->line('');
        $this->line('  date      : '.$today->toDateString().($date ? '  (overridden)' : ''));
        $this->line('  before    : '.($config['lead_days'] ?: '(disabled)').' days out');
        $this->line('  after     : '.($config['after_days'] ?: '(none)').' days, then '
            .($months > 0 ? "monthly for {$months} months" : 'monthly indefinitely'));
        $this->line('  cutoff    : '.($floor ?: 'none — every expired period is in scope'));
        $this->line('  transport : '.config('mail.default'));

        if ($dryRun) {
            $this->warn('  DRY RUN — nothing will be sent or recorded.');
        }

        if (config('mail.default') === 'log' && ! $dryRun) {
            $this->warn('  MAIL_MAILER is `log`: reminders go to storage/logs, not to carriers.');
        }

        if (! $floor) {
            $this->warn('  No FM_SUBSCRIPTION_REMINDER_IGNORE_BEFORE set: carriers whose');
            $this->warn('  subscription lapsed years ago are in scope for a monthly email.');
        }

        $this->line('');

        $result = $service->run($today, $dryRun);

        $verb = $dryRun ? 'would send' : 'sent';

        $this->line("  expiring   : {$result['expiring']}  ({$verb})");
        $this->line("  expired    : {$result['expired']}  ({$verb})");
        $this->line("  suppressed : {$result['suppressed']}  (backlog milestones recorded, not emailed)");
        $this->line("  skipped    : {$result['skipped']}  (already sent, already renewed, or no email)");
        $this->line('');

        if (! $dryRun && $result['expiring'] + $result['expired'] > 0) {
            $this->line('  Full record: /admin/reminders');
            $this->line('');
        }

        return self::SUCCESS;
    }
}
