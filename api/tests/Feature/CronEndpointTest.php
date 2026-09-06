<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The cron URLs.
 *
 * These exist because SiteGround's SSH has no `crontab`, so a URL-fetching cron
 * is the only kind creatable without a shell. That makes them the highest-value
 * unauthenticated target in the application: one GET mails every carrier whose
 * subscription is due a reminder.
 *
 * The previous site had the same idea and shipped it open — `/reminder-one-email`
 * and `/bulk-email` were plain `Route::view(...)` entries. Anyone who guessed
 * either URL could fire mail at the whole user base, repeatedly. Every test here
 * is about that not being true again.
 */
class CronEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private const PATHS = [
        '/api/v1/cron/subscription-reminders',
        '/api/v1/cron/load-alerts',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['freightmove.cron.token' => self::TOKEN]);
    }

    public function test_the_right_token_runs_the_sweep(): void
    {
        $this->getJson('/api/v1/cron/subscription-reminders?token='.self::TOKEN)
            ->assertOk()
            ->assertJsonStructure(['data' => ['expiring', 'expired', 'suppressed', 'skipped']]);
    }

    /** A header keeps the secret out of access logs and referrers. */
    public function test_the_token_can_be_sent_as_a_header(): void
    {
        $this->getJson('/api/v1/cron/subscription-reminders', ['X-Cron-Token' => self::TOKEN])
            ->assertOk();
    }

    public function test_a_cron_url_without_a_token_does_nothing(): void
    {
        foreach (self::PATHS as $path) {
            $this->getJson($path)->assertNotFound();
        }

        Mail::assertNothingSent();
    }

    public function test_a_wrong_token_does_nothing(): void
    {
        foreach (self::PATHS as $path) {
            $this->getJson($path.'?token=wrong')->assertNotFound();
        }

        Mail::assertNothingSent();
    }

    /**
     * Fails closed. An endpoint that mails hundreds of people must refuse when
     * it is unconfigured, not default to open — which is precisely how the
     * previous site's version behaved.
     */
    public function test_an_unconfigured_token_refuses_everything(): void
    {
        config(['freightmove.cron.token' => null]);

        foreach (self::PATHS as $path) {
            $this->getJson($path)->assertNotFound();
            $this->getJson($path.'?token=')->assertNotFound();
        }

        Mail::assertNothingSent();
    }

    /** A short secret is a speed bump, so it is refused like a missing one. */
    public function test_a_token_that_is_too_short_is_refused(): void
    {
        config(['freightmove.cron.token' => 'short']);

        $this->getJson('/api/v1/cron/subscription-reminders?token=short')->assertNotFound();
        Mail::assertNothingSent();
    }

    /**
     * 404, not 401. A wrong secret should not confirm that the endpoint is
     * there for someone to keep guessing at.
     */
    public function test_a_refusal_does_not_confirm_the_route_exists(): void
    {
        $response = $this->getJson('/api/v1/cron/subscription-reminders?token=wrong')->assertNotFound();

        $this->assertStringNotContainsString('token', strtolower($response->getContent()));
        $this->assertStringNotContainsString('cron', strtolower($response->getContent()));
    }

    /**
     * The cron may run every minute. Both sweeps keep ledgers, so repeating
     * the call must not repeat the mail.
     */
    public function test_calling_it_repeatedly_does_not_send_twice(): void
    {
        $carrier = User::factory()->create([
            'role' => UserRole::Carrier,
            'status' => UserStatus::Active,
        ]);

        $this->seed(\Database\Seeders\SubscriptionPlanSeeder::class);
        Subscription::create([
            'user_id' => $carrier->id,
            'subscription_plan_id' => SubscriptionPlan::where('code', 'monthly')->value('id'),
            'status' => 'active',
            'starts_on' => today()->subMonth(),
            'ends_on' => today()->addDays(3),
        ]);

        $first = $this->getJson('/api/v1/cron/subscription-reminders?token='.self::TOKEN)->assertOk();
        $this->assertSame(1, $first->json('data.expiring'));

        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/api/v1/cron/subscription-reminders?token='.self::TOKEN)
                ->assertOk()
                ->assertJsonPath('data.expiring', 0);
        }

        Mail::assertSent(\App\Mail\SubscriptionExpiring::class, 1);
    }

    /** `dry_run=1` lets a new cron be proved without mailing anyone. */
    public function test_a_dry_run_sends_nothing(): void
    {
        $this->getJson('/api/v1/cron/subscription-reminders?token='.self::TOKEN.'&dry_run=1')
            ->assertOk();

        Mail::assertNothingSent();
    }

    /** Cron UIs that can only POST must work too. */
    public function test_post_works_as_well_as_get(): void
    {
        $this->postJson('/api/v1/cron/subscription-reminders?token='.self::TOKEN)->assertOk();
    }
}
