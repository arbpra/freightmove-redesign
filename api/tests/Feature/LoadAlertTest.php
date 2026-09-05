<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Mail\LoadPostedAdmin;
use App\Mail\NewLoadAvailable;
use App\Models\FreightJob;
use App\Models\LoadAlert;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\LoadAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Telling carriers a load has been posted.
 *
 * The previous site intended this and never delivered one: the fan-out loop in
 * `bulk-email.blade.php` is commented out, and the 7,888 QuotationMail jobs it
 * queued instead sit unprocessed with `attempts = 0`.
 *
 * Which sets what is worth pinning. This is the highest-volume message the
 * application sends — 295 carriers per load, ~11,800 a month — so the failure
 * that matters is not "no email"; it is the same carrier being told twice, or
 * an opt-out that does not hold.
 */
class LoadAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config([
            'freightmove.loads.alerts.enabled' => true,
            'freightmove.loads.alerts.audience' => 'all',
            'freightmove.loads.alerts.admin_recipient' => 'ops@freightmove.test',
        ]);
    }

    private function carrier(string $email): User
    {
        return User::factory()->create([
            'role' => UserRole::Carrier,
            'status' => UserStatus::Active,
            'email' => $email,
        ]);
    }

    private function load(): FreightJob
    {
        $shipper = User::factory()->create(['role' => UserRole::Shipper, 'status' => UserStatus::Active]);
        UserProfile::factory()->create(['user_id' => $shipper->id, 'company_name' => 'Whitfield Freight']);

        return FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Published,
            'title' => 'Excavator, Sydney to Dubbo',
            'pickup_location' => 'Sydney NSW',
            'delivery_location' => 'Dubbo NSW',
        ]);
    }

    private function dispatch(FreightJob $job, bool $dryRun = false): array
    {
        return app(LoadAlertService::class)->dispatchFor($job, $dryRun);
    }

    public function test_every_carrier_and_the_operator_are_told(): void
    {
        $this->carrier('one@example.test');
        $this->carrier('two@example.test');

        $result = $this->dispatch($this->load());

        $this->assertSame(2, $result['carriers']);
        $this->assertTrue($result['admin']);
        Mail::assertSent(NewLoadAvailable::class, 2);
        Mail::assertSent(LoadPostedAdmin::class, fn ($m) => $m->hasTo('ops@freightmove.test'));
    }

    /** The subject is the lane, because that is what decides an open. */
    public function test_the_subject_is_the_lane(): void
    {
        $this->carrier('one@example.test');

        $this->dispatch($this->load());

        Mail::assertSent(
            NewLoadAvailable::class,
            fn (NewLoadAvailable $m) => $m->envelope()->subject === 'New load: Sydney NSW to Dubbo NSW',
        );
    }

    /**
     * The failure that matters at this volume. A retried queue job, a
     * re-publish, two workers racing — none of them may re-mail 295 people.
     */
    public function test_dispatching_twice_tells_nobody_twice(): void
    {
        $this->carrier('one@example.test');
        $job = $this->load();

        $this->dispatch($job);
        $second = $this->dispatch($job);

        $this->assertSame(0, $second['carriers']);
        Mail::assertSent(NewLoadAvailable::class, 1);
        $this->assertSame(1, LoadAlert::where('freight_job_id', $job->id)->count());
    }

    public function test_a_second_load_does_reach_the_same_carrier(): void
    {
        $this->carrier('one@example.test');

        $this->dispatch($this->load());
        $this->dispatch($this->load());

        Mail::assertSent(NewLoadAvailable::class, 2);
    }

    // --- Who is in the audience ---------------------------------------------

    public function test_an_opted_out_carrier_is_not_emailed(): void
    {
        $this->carrier('yes@example.test');
        $this->carrier('no@example.test')->forceFill(['wants_load_alerts' => false])->save();

        $this->assertSame(1, $this->dispatch($this->load())['carriers']);
        Mail::assertSent(NewLoadAvailable::class, fn ($m) => $m->hasTo('yes@example.test'));
        Mail::assertNotSent(NewLoadAvailable::class, fn ($m) => $m->hasTo('no@example.test'));
    }

    public function test_a_suspended_carrier_is_not_emailed(): void
    {
        $this->carrier('active@example.test');
        $this->carrier('gone@example.test')->forceFill(['status' => UserStatus::Suspended])->save();

        $this->assertSame(1, $this->dispatch($this->load())['carriers']);
    }

    public function test_shippers_are_never_in_the_audience(): void
    {
        $this->carrier('carrier@example.test');
        User::factory()->create(['role' => UserRole::Shipper, 'status' => UserStatus::Active]);

        $this->assertSame(1, $this->dispatch($this->load())['carriers']);
    }

    /** `subscribed` makes the alert part of what the subscription buys. */
    public function test_the_audience_can_be_narrowed_to_subscribers(): void
    {
        config(['freightmove.loads.alerts.audience' => 'subscribed']);

        $paying = $this->carrier('paying@example.test');
        $this->carrier('free@example.test');

        $this->seed(\Database\Seeders\SubscriptionPlanSeeder::class);
        Subscription::create([
            'user_id' => $paying->id,
            'subscription_plan_id' => SubscriptionPlan::where('code', 'monthly')->value('id'),
            'status' => 'active',
            'starts_on' => today()->subDay(),
            'ends_on' => today()->addMonth(),
        ]);

        $this->assertSame(1, $this->dispatch($this->load())['carriers']);
        Mail::assertSent(NewLoadAvailable::class, fn ($m) => $m->hasTo('paying@example.test'));
    }

    // --- The switch ---------------------------------------------------------

    /**
     * Off by default, and off must mean off: ~11,800 messages a month to an
     * audience that has never had any is not something to start by accident.
     */
    public function test_nothing_reaches_carriers_while_alerts_are_off(): void
    {
        config(['freightmove.loads.alerts.enabled' => false]);
        $this->carrier('one@example.test');

        $result = $this->dispatch($this->load());

        $this->assertSame(0, $result['carriers']);
        Mail::assertNotSent(NewLoadAvailable::class);
        $this->assertSame(0, LoadAlert::count());
    }

    /** The operator still hears about the load, and is told the fan-out was nil. */
    public function test_the_operator_is_told_even_when_alerts_are_off(): void
    {
        config(['freightmove.loads.alerts.enabled' => false]);
        $this->carrier('one@example.test');

        $this->dispatch($this->load());

        Mail::assertSent(LoadPostedAdmin::class, fn (LoadPostedAdmin $m) => $m->carriersNotified === 0);
    }

    // --- Test mode: staging sends one, live sends all -----------------------

    /**
     * With a test recipient set, the fan-out is replaced by one message.
     *
     * This is what the previous site did — except it did it by commenting the
     * carrier loop out and hard-coding an address inside a Blade view, which
     * is why nobody noticed for two years that no carrier was being reached.
     */
    public function test_a_test_recipient_replaces_the_fan_out(): void
    {
        config(['freightmove.loads.alerts.test_recipient' => 'arbpra@example.test']);
        $this->carrier('one@example.test');
        $this->carrier('two@example.test');

        $result = $this->dispatch($this->load());

        $this->assertSame('arbpra@example.test', $result['test_recipient']);
        Mail::assertSent(NewLoadAvailable::class, 1);
        Mail::assertSent(NewLoadAvailable::class, fn ($m) => $m->hasTo('arbpra@example.test'));
        Mail::assertNotSent(NewLoadAvailable::class, fn ($m) => $m->hasTo('one@example.test'));
    }

    /** The operator copy is unaffected — it goes in both modes. */
    public function test_the_operator_is_still_told_in_test_mode(): void
    {
        config(['freightmove.loads.alerts.test_recipient' => 'arbpra@example.test']);
        $this->carrier('one@example.test');

        $this->dispatch($this->load());

        Mail::assertSent(LoadPostedAdmin::class, fn ($m) => $m->hasTo('ops@freightmove.test'));
    }

    /**
     * A test send must not claim a carrier was told. Those rows are what stop
     * a real alert going out later, so writing them here would silently cost
     * carriers the first alert they were ever due.
     */
    public function test_a_test_send_writes_no_carrier_ledger_rows(): void
    {
        config(['freightmove.loads.alerts.test_recipient' => 'arbpra@example.test']);
        $this->carrier('one@example.test');

        $this->dispatch($this->load());

        $this->assertSame(0, LoadAlert::count());
    }

    /** Clearing the address is the whole difference between staging and live. */
    public function test_clearing_the_test_recipient_reaches_every_carrier(): void
    {
        config(['freightmove.loads.alerts.test_recipient' => null]);
        $this->carrier('one@example.test');
        $this->carrier('two@example.test');

        $result = $this->dispatch($this->load());

        $this->assertNull($result['test_recipient']);
        $this->assertSame(2, $result['carriers']);
        Mail::assertSent(NewLoadAvailable::class, 2);
    }

    /** Junk in the setting must not silently divert or drop the fan-out. */
    public function test_an_unusable_test_recipient_is_ignored(): void
    {
        config(['freightmove.loads.alerts.test_recipient' => 'not-an-address']);
        $this->carrier('one@example.test');

        $result = $this->dispatch($this->load());

        $this->assertNull($result['test_recipient']);
        $this->assertSame(1, $result['carriers']);
    }

    public function test_a_dry_run_sends_nothing_and_records_nothing(): void
    {
        $this->carrier('one@example.test');

        $result = $this->dispatch($this->load(), dryRun: true);

        $this->assertSame(1, $result['carriers'], 'A dry run still reports the reach.');
        $this->assertSame(0, LoadAlert::count());
        Mail::assertNothingSent();
    }

    // --- The unsubscribe ----------------------------------------------------

    /**
     * It has to work without a login. Someone who wants these to stop will not
     * sign in to stop them, and a link that asks them to is a spam complaint.
     */
    public function test_the_unsubscribe_link_works_signed_out(): void
    {
        $carrier = $this->carrier('one@example.test');

        $url = URL::temporarySignedRoute('load-alerts.unsubscribe', now()->addYear(), ['user' => $carrier->id]);

        $this->get($url)->assertRedirect();
        $this->assertFalse($carrier->fresh()->wants_load_alerts);
    }

    /** The signature is the authorisation — an unsigned link must not work. */
    public function test_an_unsigned_unsubscribe_is_refused(): void
    {
        $carrier = $this->carrier('one@example.test');

        $this->get("/api/v1/public/load-alerts/unsubscribe/{$carrier->id}")->assertForbidden();
        $this->assertTrue($carrier->fresh()->wants_load_alerts);
    }

    /** And it cannot be re-pointed at somebody else by editing the id. */
    public function test_a_signed_link_cannot_be_aimed_at_another_account(): void
    {
        $mine = $this->carrier('mine@example.test');
        $theirs = $this->carrier('theirs@example.test');

        $url = URL::temporarySignedRoute('load-alerts.unsubscribe', now()->addYear(), ['user' => $mine->id]);
        $tampered = str_replace("/{$mine->id}?", "/{$theirs->id}?", $url);

        $this->get($tampered)->assertForbidden();
        $this->assertTrue($theirs->fresh()->wants_load_alerts);
    }

    public function test_the_alert_carries_an_unsubscribe_link(): void
    {
        $this->carrier('one@example.test');

        $this->dispatch($this->load());

        Mail::assertSent(NewLoadAvailable::class, function (NewLoadAvailable $mail) {
            return str_contains($mail->render(), 'load-alerts/unsubscribe')
                && str_contains($mail->render(), 'Stop load alerts');
        });
    }

    // --- Mechanics ----------------------------------------------------------

    /** A failed send is kept as a row, so a retry resumes rather than restarts. */
    public function test_a_failed_send_is_recorded_rather_than_lost(): void
    {
        $this->carrier('one@example.test');
        $job = $this->load();

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('transport down'));

        $result = $this->dispatch($job);

        $this->assertSame(0, $result['carriers']);
        $row = LoadAlert::first();
        $this->assertNotNull($row);
        $this->assertNull($row->sent_at);
        $this->assertStringContainsString('transport down', $row->failure);
    }

    public function test_the_command_previews_without_sending(): void
    {
        $this->carrier('one@example.test');
        $job = $this->load();

        $this->artisan("loads:alert --job={$job->id} --dry-run")->assertExitCode(0);

        Mail::assertNothingSent();
        $this->assertSame(0, LoadAlert::count());
    }

    public function test_the_command_can_alert_one_load(): void
    {
        $this->carrier('one@example.test');
        $job = $this->load();

        $this->artisan("loads:alert --job={$job->id}")->assertExitCode(0);

        Mail::assertSent(NewLoadAvailable::class, 1);
    }
}
