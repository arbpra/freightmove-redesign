<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Mail\SubscriptionExpired;
use App\Mail\SubscriptionExpiring;
use App\Models\Subscription;
use App\Models\SubscriptionReminder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Subscription lifecycle reminders.
 *
 * The previous site ran two of these — `ReminderMailExpire` before the end date
 * and `ReminderMail` after it — from two Blade views with the query written
 * inline (`resources/views/reminder-expiring.blade.php` and
 * `reminder-one-email.blade.php`). That pairing is kept and widened into a
 * cadence: 5, 3 and 1 days before the end date, then 3, 7 and 15 days after it,
 * then one a month on the anniversary.
 *
 * Almost nothing else survives, because each of the following was broken there:
 *
 * - **A missed run does not lose the reminder.** Matching `end_to = today + 3`
 *   exactly — what the old warning did — means a cron that fails on the wrong
 *   night silently skips everyone who needed warning that night, with nothing
 *   in any log to say so. The windows below are ranges, and the ledger, not the
 *   date arithmetic, is what stops repeats.
 * - **Nobody is chased for something they have already paid.** A carrier who
 *   renews early holds two rows; the older one still expires on schedule and
 *   would otherwise trigger "your subscription has ended" days after they paid.
 * - **Copy tells the truth about time.** The milestone decides *whether* to
 *   send; the real elapsed time decides what the email *says*. The old pair
 *   could not: both bodies were static HTML with no interpolation, so the
 *   warning said "3 days" whatever the actual date was — under a subject line
 *   that said five.
 * - **A repeat is impossible.** The old expired notice had no record of what it
 *   had sent: it mailed every lapsed carrier on every single run. The
 *   `email_send` table existed for exactly this and was never written to — it
 *   is empty in the production dump.
 * - **A backlog is never delivered at once.** With a monthly cadence, the first
 *   sweep over a subscription that lapsed two years ago finds every month due
 *   simultaneously. Only the newest is sent; the rest are recorded as skipped
 *   so they can never fire later.
 *
 * The whole thing is guarded end to end. A reminder is downstream of the real
 * work, and a mail outage must not turn a scheduled sweep into a failed command
 * that stops the rest of the schedule.
 */
class SubscriptionReminderService
{
    /**
     * The statuses a post-expiry reminder applies to.
     *
     * `expired` is the whole point and is easy to leave out: the entitling set
     * is `active` and `cancelled`, and reusing it here would have silently
     * excluded 76 of the 85 imported subscriptions — every one the legacy
     * importer stamped `expired` because its end date had already passed. The
     * sweep would have run clean, reported zero, and never reminded the only
     * people it exists for.
     *
     * `pending` stays out: a plan reserved and never paid for is not something
     * to chase someone about.
     */
    private const LAPSED_STATUSES = ['active', 'cancelled', 'expired'];

    public function __construct(private readonly Notifier $notifier) {}

    /**
     * One sweep.
     *
     * `$today` is injected rather than read from the clock so behaviour around
     * a date boundary can be tested without freezing time globally.
     *
     * @return array{expiring: int, expired: int, suppressed: int, skipped: int}
     */
    public function run(?CarbonImmutable $today = null, bool $dryRun = false): array
    {
        $today = $today ?? CarbonImmutable::today();

        $tally = ['expiring' => 0, 'expired' => 0, 'suppressed' => 0, 'skipped' => 0];

        $this->sweepWarnings($today, $dryRun, $tally);
        $this->sweepExpired($today, $dryRun, $tally);

        return $tally;
    }

    /**
     * Before the end date: 5, 3 and 1 days out by default.
     *
     * @param  array{expiring: int, expired: int, suppressed: int, skipped: int}  $tally
     */
    private function sweepWarnings(CarbonImmutable $today, bool $dryRun, array &$tally): void
    {
        $buckets = $this->days('lead_days', [1, 3, 5]);

        if ($buckets === []) {
            return;
        }

        $due = $this->carrierSubscriptions(Subscription::ENTITLING_STATUSES)
            ->whereDate('ends_on', '>=', $today)
            ->whereDate('ends_on', '<=', $today->addDays(max($buckets)))
            ->orderBy('ends_on')
            ->get();

        foreach ($due as $subscription) {
            $daysLeft = $this->wholeDaysBetween($today, $subscription->ends_on);
            $bucket = $this->smallestAtLeast($buckets, $daysLeft);

            if ($bucket === null || $this->alreadyCovered($subscription)) {
                $tally['skipped']++;

                continue;
            }

            $sent = $this->deliver(
                $subscription,
                SubscriptionReminder::KIND_EXPIRING,
                "d{$bucket}",
                $dryRun,
                fn () => new SubscriptionExpiring($subscription, $daysLeft),
                'subscription.expiring',
            );

            $sent ? $tally['expiring']++ : $tally['skipped']++;
        }
    }

    /**
     * After the end date: 3, 7 and 15 days, then monthly on the anniversary.
     *
     * @param  array{expiring: int, expired: int, suppressed: int, skipped: int}  $tally
     */
    private function sweepExpired(CarbonImmutable $today, bool $dryRun, array &$tally): void
    {
        $query = $this->carrierSubscriptions(self::LAPSED_STATUSES)
            ->whereDate('ends_on', '<', $today);

        /*
         * The historical cutoff. 88 of the 90 migrated periods are already
         * expired, some since 2024, and with an open-ended monthly cadence
         * every one of them is due the moment this first runs. Setting this
         * date is what stops that; leaving it blank is a deliberate choice to
         * chase them.
         */
        if ($floor = config('freightmove.subscriptions.reminders.ignore_expiries_before')) {
            $query->whereDate('ends_on', '>=', CarbonImmutable::parse($floor));
        }

        $maxPerSweep = max(1, (int) config('freightmove.subscriptions.reminders.max_per_sweep', 1));

        foreach ($query->orderBy('ends_on')->get() as $subscription) {
            if ($this->alreadyCovered($subscription)) {
                $tally['skipped']++;

                continue;
            }

            $outstanding = $this->outstandingMilestones($subscription, $today);

            if ($outstanding === []) {
                continue;
            }

            // Oldest first, so what gets dropped is stale and what actually
            // goes out describes the current state of affairs.
            $send = array_slice($outstanding, -$maxPerSweep);
            $suppress = array_slice($outstanding, 0, max(0, count($outstanding) - $maxPerSweep));

            foreach ($suppress as $milestone) {
                if (! $dryRun) {
                    $this->record($subscription, SubscriptionReminder::KIND_EXPIRED, $milestone, null);
                }

                $tally['suppressed']++;
            }

            foreach ($send as $milestone) {
                $sent = $this->deliver(
                    $subscription,
                    SubscriptionReminder::KIND_EXPIRED,
                    $milestone,
                    $dryRun,
                    fn () => new SubscriptionExpired(
                        $subscription,
                        $milestone,
                        $this->wholeDaysBetween($subscription->ends_on, $today),
                    ),
                    'subscription.expired',
                );

                $sent ? $tally['expired']++ : $tally['skipped']++;
            }
        }
    }

    /**
     * Every post-expiry milestone this subscription has reached and has no
     * ledger row for, oldest first.
     *
     * @return list<string>
     */
    private function outstandingMilestones(Subscription $subscription, CarbonImmutable $today): array
    {
        $endsOn = CarbonImmutable::parse($subscription->ends_on)->startOfDay();

        $reached = [];

        foreach ($this->days('after_days', [3, 7, 15]) as $d) {
            if ($today >= $endsOn->addDays($d)) {
                $reached[] = ['milestone' => "d{$d}", 'due' => $endsOn->addDays($d)];
            }
        }

        // Monthly, on the anniversary of the end date. A `monthly_months` of 0
        // means it never stops, which is the configured default.
        $cap = (int) config('freightmove.subscriptions.reminders.monthly_months', 0);
        $elapsed = $this->wholeMonthsBetween($endsOn, $today);
        $months = $cap > 0 ? min($cap, $elapsed) : $elapsed;

        for ($m = 1; $m <= $months; $m++) {
            $reached[] = ['milestone' => "m{$m}", 'due' => $endsOn->addMonths($m)];
        }

        if ($reached === []) {
            return [];
        }

        usort($reached, fn (array $a, array $b) => $a['due'] <=> $b['due']);

        $already = SubscriptionReminder::query()
            ->where('subscription_id', $subscription->id)
            ->where('kind', SubscriptionReminder::KIND_EXPIRED)
            ->pluck('milestone')
            ->all();

        return array_values(array_diff(array_column($reached, 'milestone'), $already));
    }

    /**
     * Carrier subscriptions in the given statuses, with a real end date.
     *
     * @param  list<string>  $statuses
     */
    private function carrierSubscriptions(array $statuses): Builder
    {
        return Subscription::query()
            ->with(['user', 'plan'])
            ->whereIn('status', $statuses)
            // The previous site filtered the same way (`ship_car = 2`). A
            // subscription held by anyone else is a data anomaly, and telling
            // them to renew "to keep quoting" describes something they could
            // not do in the first place.
            ->whereHas('user', fn ($q) => $q->where('role', UserRole::Carrier))
            ->whereNotNull('ends_on');
    }

    /**
     * Has this carrier already renewed past the period being reminded about?
     *
     * Renewing early is the normal case for anyone paying attention, and it is
     * exactly the person you least want to send "your subscription has ended".
     */
    private function alreadyCovered(Subscription $subscription): bool
    {
        return Subscription::query()
            ->where('user_id', $subscription->user_id)
            ->where('id', '!=', $subscription->id)
            ->whereIn('status', Subscription::ENTITLING_STATUSES)
            ->where(fn ($q) => $q
                ->whereNull('ends_on')
                ->orWhere('ends_on', '>', $subscription->ends_on))
            ->exists();
    }

    /**
     * Claim the send, then make it.
     *
     * The claim goes in first on purpose. Sending first and recording after
     * loses the record if the process dies between the two, and the next run
     * mails the carrier a second time — the exact failure the ledger exists to
     * prevent. Claiming first inverts the risk to a message that is silently
     * skipped, so a failed send releases the claim to be retried tomorrow.
     */
    private function deliver(
        Subscription $subscription,
        string $kind,
        string $milestone,
        bool $dryRun,
        callable $mailable,
        string $notificationType,
    ): bool {
        if (! $subscription->user?->email) {
            return false;
        }

        if ($dryRun) {
            return ! SubscriptionReminder::query()
                ->where('subscription_id', $subscription->id)
                ->where('kind', $kind)
                ->where('milestone', $milestone)
                ->exists();
        }

        $claim = $this->record($subscription, $kind, $milestone, now());

        if (! $claim) {
            // The unique index did its job: already sent, or a concurrent run
            // got there first. Not an error.
            return false;
        }

        try {
            if (config('freightmove.mail.queue')) {
                Mail::to($subscription->user->email)->queue($mailable());
            } else {
                Mail::to($subscription->user->email)->send($mailable());
            }
        } catch (Throwable $e) {
            // Release the claim so tomorrow's sweep tries again, rather than
            // recording a message that never arrived.
            $claim->delete();

            Log::error('Could not send a subscription reminder.', [
                'subscription' => $subscription->id,
                'milestone' => $milestone,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        // The in-app row is secondary: the email is the point, and a feed write
        // failing must not undo a message the carrier has already received.
        $this->notifier->subscriptionReminder($subscription, $notificationType);

        return true;
    }

    /**
     * Writes one ledger row. A null `$sentAt` records a milestone that was
     * reached but deliberately not emailed.
     */
    private function record(
        Subscription $subscription,
        string $kind,
        string $milestone,
        ?\DateTimeInterface $sentAt,
    ): ?SubscriptionReminder {
        try {
            return SubscriptionReminder::create([
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'kind' => $kind,
                'milestone' => $milestone,
                'sent_at' => $sentAt,
                'skip_reason' => $sentAt ? null : SubscriptionReminder::SKIP_BACKLOG,
            ]);
        } catch (QueryException) {
            return null;
        }
    }

    /**
     * The smallest configured milestone still at or above the days remaining,
     * so a sweep that slips lands in the milestone it would have hit on time
     * rather than skipping the warning altogether.
     *
     * @param  list<int>  $buckets
     */
    private function smallestAtLeast(array $buckets, int $daysLeft): ?int
    {
        $match = null;

        foreach ($buckets as $bucket) {
            if ($bucket >= $daysLeft && ($match === null || $bucket < $match)) {
                $match = $bucket;
            }
        }

        return $match;
    }

    /**
     * Parses "5,3,1" into [1, 3, 5]. Kept out of the config file because a
     * config file is a plain array with nowhere to declare a helper, and
     * declaring one there breaks on the second boot in a test run.
     *
     * @param  list<int>  $default
     * @return list<int>
     */
    private function days(string $key, array $default): array
    {
        $raw = config("freightmove.subscriptions.reminders.{$key}");

        if ($raw === null) {
            return $default;
        }

        $parts = is_array($raw) ? $raw : explode(',', (string) $raw);

        $days = array_filter(
            array_map('intval', array_filter(array_map('trim', (array) $parts), 'strlen')),
            fn (int $d) => $d > 0,
        );

        $days = array_values(array_unique($days));
        sort($days);

        return $days;
    }

    private function wholeDaysBetween(\DateTimeInterface $from, \DateTimeInterface $to): int
    {
        return (int) CarbonImmutable::parse($from)->startOfDay()
            ->diffInDays(CarbonImmutable::parse($to)->startOfDay(), false);
    }

    private function wholeMonthsBetween(\DateTimeInterface $from, \DateTimeInterface $to): int
    {
        return max(0, (int) CarbonImmutable::parse($from)->startOfDay()
            ->diffInMonths(CarbonImmutable::parse($to)->startOfDay(), false));
    }
}
