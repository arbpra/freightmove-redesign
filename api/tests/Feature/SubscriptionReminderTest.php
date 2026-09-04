<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Mail\SubscriptionExpired;
use App\Mail\SubscriptionExpiring;
use App\Models\Notification;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionReminder;
use App\Models\User;
use App\Services\SubscriptionReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Subscription lifecycle reminders.
 *
 * The cadence is 5, 3 and 1 days before the end date, then 3, 7 and 15 days
 * after it, then one a month on the anniversary — indefinitely.
 *
 * The previous site sent two of these from Blade views with the query inline
 * and no record of what had already gone out. The behaviour worth pinning is
 * not that an email is sent; it is everything around it, because every failure
 * here is silent:
 *
 * - a sweep run twice must not email twice;
 * - a sweep that misses a night must not lose the reminder;
 * - a carrier who has already renewed must never be told they expired;
 * - a monthly cadence must never deliver its backlog in one go.
 */
class SubscriptionReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\SubscriptionPlanSeeder::class);
        Mail::fake();
    }

    private function carrier(string $email = 'carrier@example.test'): User
    {
        return User::factory()->create([
            'role' => UserRole::Carrier,
            'status' => UserStatus::Active,
            'email' => $email,
        ]);
    }

    private function subscription(User $user, string $endsOn, string $status = 'active'): Subscription
    {
        return Subscription::create([
            'user_id' => $user->id,
            'subscription_plan_id' => SubscriptionPlan::where('code', 'monthly')->value('id'),
            'status' => $status,
            'starts_on' => CarbonImmutable::parse($endsOn)->subMonth(),
            'ends_on' => $endsOn,
        ]);
    }

    private function sweep(string $today, bool $dryRun = false): array
    {
        return app(SubscriptionReminderService::class)
            ->run(CarbonImmutable::parse($today), $dryRun);
    }

    // --- Before the end date: 5, 3, 1 ---------------------------------------

    /** @return list<array{0: string, 1: int, 2: string}> */
    public static function warningDays(): array
    {
        return [
            'five days out' => ['2026-09-15', 5, 'd5'],
            'three days out' => ['2026-09-13', 3, 'd3'],
            'one day out' => ['2026-09-11', 1, 'd1'],
            'ends today' => ['2026-09-10', 0, 'd1'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('warningDays')]
    public function test_each_warning_milestone_fires(string $endsOn, int $daysLeft, string $milestone): void
    {
        $this->subscription($this->carrier(), $endsOn);

        $result = $this->sweep('2026-09-10');

        $this->assertSame(1, $result['expiring']);
        $this->assertDatabaseHas('subscription_reminders', [
            'kind' => 'expiring',
            'milestone' => $milestone,
        ]);
        Mail::assertSent(SubscriptionExpiring::class, fn ($m) => $m->daysLeft === $daysLeft);
    }

    public function test_all_three_warnings_reach_one_carrier(): void
    {
        $this->subscription($this->carrier(), '2026-09-20');

        $this->sweep('2026-09-15');   // 5 out
        $this->sweep('2026-09-17');   // 3 out
        $this->sweep('2026-09-19');   // 1 out

        $this->assertSame(3, SubscriptionReminder::where('kind', 'expiring')->count());
        Mail::assertSent(SubscriptionExpiring::class, 3);
    }

    public function test_the_email_states_the_real_days_remaining_not_the_milestone(): void
    {
        $this->subscription($this->carrier(), '2026-09-14');

        // Four days out falls in the five-day milestone, but "5 days" would be
        // a lie. A sweep that slips must still say something true.
        $this->sweep('2026-09-10');

        Mail::assertSent(SubscriptionExpiring::class, fn (SubscriptionExpiring $mail) => $mail->daysLeft === 4
            && str_contains($mail->envelope()->subject, '4 days'));
    }

    public function test_a_missed_night_is_caught_up_rather_than_lost(): void
    {
        $this->subscription($this->carrier(), '2026-09-20');

        // Nothing ran on the 15th, 16th or 17th.
        $result = $this->sweep('2026-09-18');

        $this->assertSame(1, $result['expiring']);
        Mail::assertSent(SubscriptionExpiring::class, fn ($m) => $m->daysLeft === 2);
    }

    // --- After the end date: 3, 7, 15, then monthly --------------------------

    /** @return list<array{0: string, 1: string}> */
    public static function afterDays(): array
    {
        return [
            'three days after' => ['2026-09-13', 'd3'],
            'seven days after' => ['2026-09-17', 'd7'],
            'fifteen days after' => ['2026-09-25', 'd15'],
            'one month after' => ['2026-10-10', 'm1'],
            'two months after' => ['2026-11-10', 'm2'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('afterDays')]
    public function test_each_post_expiry_milestone_fires(string $sweepOn, string $milestone): void
    {
        $this->subscription($this->carrier(), '2026-09-10');

        $this->sweep($sweepOn);

        $this->assertDatabaseHas('subscription_reminders', [
            'kind' => 'expired',
            'milestone' => $milestone,
        ]);
    }

    public function test_the_full_cadence_runs_day_by_day_without_repeating(): void
    {
        $this->subscription($this->carrier(), '2026-09-10');

        // Every day for three months. Six milestones are reachable in that
        // window: d3, d7, d15, m1, m2, m3.
        for ($day = CarbonImmutable::parse('2026-09-11'); $day <= CarbonImmutable::parse('2026-12-10'); $day = $day->addDay()) {
            app(SubscriptionReminderService::class)->run($day);
        }

        $sent = SubscriptionReminder::where('kind', 'expired')->delivered()->pluck('milestone')->all();

        sort($sent);
        $this->assertSame(['d15', 'd3', 'd7', 'm1', 'm2', 'm3'], $sent);
        Mail::assertSent(SubscriptionExpired::class, 6);
    }

    public function test_the_monthly_cadence_can_be_capped(): void
    {
        config(['freightmove.subscriptions.reminders.monthly_months' => 2]);
        $this->subscription($this->carrier(), '2026-01-10');

        $this->sweep('2026-09-10');

        $this->assertSame(
            2,
            SubscriptionReminder::where('milestone', 'like', 'm%')->count(),
            'A cap of 2 should produce m1 and m2 and nothing further.',
        );
    }

    /**
     * The one that matters most on this data. 88 of the 90 migrated periods
     * expired long ago, so the first sweep finds every past milestone due at
     * once. Delivering them would be a year of email in one go.
     */
    public function test_a_long_lapsed_subscription_gets_one_email_not_a_backlog(): void
    {
        $this->subscription($this->carrier(), '2024-03-07');

        $result = $this->sweep('2026-09-10');

        $this->assertSame(1, $result['expired'], 'Only the newest milestone should be emailed.');
        $this->assertGreaterThan(20, $result['suppressed'], 'The rest should be recorded, not sent.');
        Mail::assertSent(SubscriptionExpired::class, 1);
    }

    public function test_a_suppressed_milestone_never_fires_later(): void
    {
        $this->subscription($this->carrier(), '2024-03-07');

        $this->sweep('2026-09-10');
        Mail::fake();

        // The next day nothing new is due, so nothing more may go out.
        $this->sweep('2026-09-11');

        Mail::assertNothingSent();
    }

    public function test_the_long_lapsed_email_changes_its_tone(): void
    {
        $this->subscription($this->carrier(), '2024-03-07');

        $this->sweep('2026-09-10');

        Mail::assertSent(SubscriptionExpired::class, fn (SubscriptionExpired $mail) => $mail->isLongLapsed()
            && str_contains($mail->envelope()->subject, 'Come back'));
    }

    /**
     * The legacy importer stamps any period whose end date had already passed
     * as `expired`, which is 76 of the 85 rows in the development database.
     * Reusing the entitling status set here would exclude every one of them —
     * the sweep would report zero and look like it was working.
     */
    public function test_a_subscription_already_marked_expired_is_reminded_about(): void
    {
        $this->subscription($this->carrier(), '2026-09-01', 'expired');

        $result = $this->sweep('2026-09-10');

        $this->assertSame(1, $result['expired']);
        Mail::assertSent(SubscriptionExpired::class);
    }

    /** But it must not receive an end-date warning it is already past. */
    public function test_an_expired_subscription_gets_no_pre_expiry_warning(): void
    {
        $this->subscription($this->carrier(), '2026-09-20', 'expired');

        $this->assertSame(0, $this->sweep('2026-09-15')['expiring']);
        Mail::assertNothingSent();
    }

    public function test_an_unpaid_subscription_is_not_chased_after_it_lapses(): void
    {
        $this->subscription($this->carrier(), '2026-09-01', 'pending');

        $this->assertSame(0, $this->sweep('2026-09-10')['expired']);
        Mail::assertNothingSent();
    }

    public function test_the_cutoff_leaves_historical_lapses_alone(): void
    {
        config(['freightmove.subscriptions.reminders.ignore_expiries_before' => '2026-01-01']);
        $this->subscription($this->carrier(), '2024-03-07');

        $result = $this->sweep('2026-09-10');

        $this->assertSame(0, $result['expired']);
        Mail::assertNothingSent();
    }

    // --- Never twice, never the wrong person --------------------------------

    public function test_running_the_sweep_twice_sends_one_email(): void
    {
        $this->subscription($this->carrier(), '2026-09-20');

        $this->sweep('2026-09-15');
        $second = $this->sweep('2026-09-15');

        $this->assertSame(0, $second['expiring']);
        Mail::assertSentCount(1);
    }

    /**
     * The single most damaging thing this could do: tell someone who paid last
     * week that they have expired.
     */
    public function test_a_carrier_who_has_already_renewed_is_not_chased(): void
    {
        $carrier = $this->carrier();
        $this->subscription($carrier, '2026-09-20');
        $this->subscription($carrier, '2026-10-20');

        $result = $this->sweep('2026-09-15');

        $this->assertSame(0, $result['expiring']);
        Mail::assertNothingSent();
    }

    public function test_a_renewed_carrier_is_not_told_the_old_period_expired(): void
    {
        $carrier = $this->carrier();
        $this->subscription($carrier, '2026-09-02');
        $this->subscription($carrier, '2026-10-02');

        $this->assertSame(0, $this->sweep('2026-09-10')['expired']);
        Mail::assertNothingSent();
    }

    /**
     * `pending` means a plan was reserved and never paid for. Reminding
     * someone to renew something they never bought is a bill for nothing.
     */
    public function test_an_unpaid_subscription_is_never_reminded_about(): void
    {
        $this->subscription($this->carrier(), '2026-09-20', 'pending');

        $this->assertSame(0, $this->sweep('2026-09-15')['expiring']);
        Mail::assertNothingSent();
    }

    /**
     * Cancelling means "do not renew", not "lock me out now" — the carrier
     * keeps the period they paid for, so the end-date warning still applies.
     */
    public function test_a_cancelled_subscription_still_gets_its_end_date_warning(): void
    {
        $this->subscription($this->carrier(), '2026-09-20', 'cancelled');

        $this->assertSame(1, $this->sweep('2026-09-15')['expiring']);
    }

    /**
     * The previous site scoped its sweep to `ship_car = 2`. A shipper holding
     * a subscription row would be told to renew "to keep quoting" — something
     * they could not do either way.
     */
    public function test_only_carriers_are_reminded(): void
    {
        $shipper = User::factory()->create([
            'role' => UserRole::Shipper,
            'status' => UserStatus::Active,
        ]);
        $this->subscription($shipper, '2026-09-20');

        $this->assertSame(0, $this->sweep('2026-09-15')['expiring']);
        Mail::assertNothingSent();
    }

    // --- Mechanics ----------------------------------------------------------

    public function test_a_dry_run_sends_nothing_and_records_nothing(): void
    {
        $this->subscription($this->carrier(), '2026-09-20');

        $result = $this->sweep('2026-09-15', dryRun: true);

        $this->assertSame(1, $result['expiring'], 'A dry run should still report the work.');
        $this->assertSame(0, SubscriptionReminder::count());
        Mail::assertNothingSent();
    }

    public function test_the_reminder_also_lands_in_the_notification_feed(): void
    {
        $carrier = $this->carrier();
        $this->subscription($carrier, '2026-09-20');

        $this->sweep('2026-09-15');

        $note = Notification::where('user_id', $carrier->id)->first();

        $this->assertNotNull($note);
        $this->assertSame('subscription.expiring', $note->type);
        $this->assertSame('subscription', $note->related_type);
    }

    /**
     * The feed entry must not also produce a generic notification email —
     * that would be two emails about one event, the second worse than the
     * first. See Notifier::subscriptionReminder().
     */
    public function test_subscription_types_are_not_in_the_generic_email_list(): void
    {
        $notify = (array) config('freightmove.mail.notify');

        $this->assertNotContains('subscription.expiring', $notify);
        $this->assertNotContains('subscription.expired', $notify);
    }

    /** A failed send must leave nothing behind, so tomorrow retries. */
    public function test_a_failed_send_releases_its_claim(): void
    {
        $this->subscription($this->carrier(), '2026-09-20');

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('transport down'));

        $result = $this->sweep('2026-09-15');

        $this->assertSame(0, $result['expiring']);
        $this->assertSame(0, SubscriptionReminder::count());
    }

    public function test_the_ledger_records_who_and_when(): void
    {
        $carrier = $this->carrier();
        $subscription = $this->subscription($carrier, '2026-09-20');

        $this->sweep('2026-09-15');

        $row = SubscriptionReminder::first();

        $this->assertSame($carrier->id, $row->user_id);
        $this->assertSame($subscription->id, $row->subscription_id);
        $this->assertNotNull($row->sent_at);
        $this->assertSame('5 days before expiry', $row->label());
    }

    public function test_the_command_runs_and_reports(): void
    {
        $this->subscription($this->carrier(), '2026-09-20');

        $this->artisan('subscriptions:remind', ['--date' => '2026-09-15'])
            ->assertExitCode(0);

        Mail::assertSent(SubscriptionExpiring::class);
    }

    public function test_the_command_rejects_a_date_it_cannot_read(): void
    {
        $this->artisan('subscriptions:remind', ['--date' => 'not-a-date'])
            ->assertExitCode(1);
    }
}
