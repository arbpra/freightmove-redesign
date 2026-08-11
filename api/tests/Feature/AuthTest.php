<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Carrier;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_shipper_can_register(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Dana Reid',
            'email' => 'dana@example.com',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
            'role' => 'shipper',
            'company_name' => 'Reid Freight',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.role', 'shipper')
            ->assertJsonPath('data.user.profile.company_name', 'Reid Freight')
            ->assertJsonStructure(['success', 'data' => ['token', 'user'], 'message']);

        $this->assertDatabaseHas('users', ['email' => 'dana@example.com']);
        // A profile row always exists, so dashboards never hit a null relation.
        $this->assertSame(1, UserProfile::whereRelation('user', 'email', 'dana@example.com')->count());
    }

    public function test_registering_as_a_carrier_also_creates_the_carrier_record(): void
    {
        $this->seed(\Database\Seeders\SubscriptionPlanSeeder::class);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Kim Alvarez',
            'email' => 'kim@example.com',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
            'role' => 'carrier',
            'subscription_plan' => 'trial',
        ])->assertCreated();

        $user = User::where('email', 'kim@example.com')->firstOrFail();

        $this->assertSame(UserRole::Carrier, $user->role);
        $this->assertSame(1, Carrier::where('user_id', $user->id)->count());
    }

    /** The subscription is the product a carrier is signing up for. */
    public function test_a_carrier_must_choose_a_plan(): void
    {
        $this->seed(\Database\Seeders\SubscriptionPlanSeeder::class);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'No Plan',
            'email' => 'noplan@example.com',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
            'role' => 'carrier',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.subscription_plan.0', 'Choose a plan to get started.');

        // Nothing is written, so they can retry with the same address.
        $this->assertDatabaseMissing('users', ['email' => 'noplan@example.com']);
    }

    /** Shippers post loads for free; the field is rejected, not ignored. */
    public function test_a_shipper_is_not_asked_for_a_plan(): void
    {
        $this->seed(\Database\Seeders\SubscriptionPlanSeeder::class);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Dana Reid',
            'email' => 'dana2@example.com',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
            'role' => 'shipper',
            'subscription_plan' => 'monthly',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('subscription_plan');
    }

    public function test_choosing_the_trial_at_sign_up_starts_it_immediately(): void
    {
        $this->seed(\Database\Seeders\SubscriptionPlanSeeder::class);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Trial Carrier',
            'email' => 'trial@example.com',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
            'role' => 'carrier',
            'subscription_plan' => 'trial',
        ])->assertCreated();

        $user = User::where('email', 'trial@example.com')->firstOrFail();
        $subscription = Subscription::where('user_id', $user->id)->sole();

        $this->assertSame('active', $subscription->status);
        $this->assertSame(today()->addMonths(2)->toDateString(), $subscription->ends_on->toDateString());
    }

    /**
     * Signing up is not a payment event. A paid plan is reserved and grants
     * nothing until the money is confirmed — otherwise anyone who filled in the
     * form would have the paid product.
     */
    public function test_choosing_a_paid_plan_at_sign_up_reserves_it_pending_payment(): void
    {
        $this->seed(\Database\Seeders\SubscriptionPlanSeeder::class);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Paid Carrier',
            'email' => 'paid@example.com',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
            'role' => 'carrier',
            'subscription_plan' => 'annual',
        ])->assertCreated();

        $user = User::where('email', 'paid@example.com')->firstOrFail();

        $this->assertSame('pending', Subscription::where('user_id', $user->id)->sole()->status);
        $this->assertFalse($user->hasActiveSubscription());
    }

    public function test_an_unknown_plan_is_refused(): void
    {
        $this->seed(\Database\Seeders\SubscriptionPlanSeeder::class);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Chancer',
            'email' => 'chancer@example.com',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
            'role' => 'carrier',
            'subscription_plan' => 'free-forever',
        ])->assertStatus(422)->assertJsonValidationErrors('subscription_plan');

        $this->assertDatabaseMissing('users', ['email' => 'chancer@example.com']);
    }

    /** A closed offer must not be selectable just because the row still exists. */
    public function test_the_trial_cannot_be_chosen_once_the_offer_has_closed(): void
    {
        $this->seed(\Database\Seeders\SubscriptionPlanSeeder::class);
        config(['freightmove.subscriptions.trial_offer_ends' => today()->subDay()->toDateString()]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Too Late',
            'email' => 'toolate@example.com',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
            'role' => 'carrier',
            'subscription_plan' => 'trial',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('subscription_plan');

        $this->assertDatabaseMissing('users', ['email' => 'toolate@example.com']);
    }

    public function test_a_user_cannot_self_register_as_an_admin(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Sneaky',
            'email' => 'sneaky@example.com',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
            'role' => 'admin',
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.role.0', 'Choose whether you are registering as a shipper or a carrier.');

        $this->assertDatabaseMissing('users', ['email' => 'sneaky@example.com']);
    }

    public function test_registration_rejects_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Someone',
            'email' => 'taken@example.com',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
            'role' => 'shipper',
        ])->assertStatus(422)->assertJsonPath('success', false);
    }

    public function test_a_user_can_log_in_and_receive_a_token(): void
    {
        User::factory()->create([
            'email' => 'jo@example.com',
            'password' => Hash::make('correct-horse'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'jo@example.com',
            'password' => 'correct-horse',
        ]);

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_login_fails_with_a_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'jo@example.com',
            'password' => Hash::make('correct-horse'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'jo@example.com',
            'password' => 'wrong',
        ])->assertStatus(422)->assertJsonPath('success', false);
    }

    public function test_login_does_not_reveal_whether_an_email_is_registered(): void
    {
        User::factory()->create([
            'email' => 'known@example.com',
            'password' => Hash::make('correct-horse'),
        ]);

        $known = $this->postJson('/api/v1/auth/login', [
            'email' => 'known@example.com',
            'password' => 'wrong',
        ]);

        $unknown = $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong',
        ]);

        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame($known->json('message'), $unknown->json('message'));
    }

    public function test_a_suspended_account_cannot_log_in(): void
    {
        User::factory()->create([
            'email' => 'banned@example.com',
            'password' => Hash::make('correct-horse'),
            'status' => UserStatus::Suspended,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'banned@example.com',
            'password' => 'correct-horse',
        ])->assertStatus(403)->assertJsonPath('success', false);
    }

    public function test_me_returns_the_authenticated_user(): void
    {
        $user = User::factory()->carrier()->create();
        UserProfile::factory()->verified()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.role', 'carrier')
            ->assertJsonPath('data.profile.verification_status', 'verified');
    }

    public function test_me_requires_a_token(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = User::factory()->create();
        $keep = $user->createToken('phone')->plainTextToken;
        $revoke = $user->createToken('web')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$revoke}")
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(1, $user->tokens()->count());

        $this->withHeader('Authorization', "Bearer {$keep}")
            ->getJson('/api/v1/auth/me')
            ->assertOk();
    }

    public function test_the_password_reset_endpoint_does_not_leak_registered_emails(): void
    {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    /**
     * Accounts imported from the pre-launch site carry bcrypt cost 10 hashes
     * while this app is configured for cost 12. They must still be able to
     * sign in, and the stored hash should be strengthened once they do.
     */
    public function test_a_legacy_low_cost_hash_still_signs_in_and_is_upgraded(): void
    {
        $legacyHash = password_hash('freight-2019', PASSWORD_BCRYPT, ['cost' => 10]);

        $user = User::factory()->create([
            'email' => 'legacy@example.com',
            'role' => UserRole::Carrier,
            'status' => UserStatus::Active,
        ]);

        // Written raw so the model's `hashed` cast cannot touch it, exactly as
        // the legacy importer writes it.
        DB::table('users')->where('id', $user->id)->update(['password' => $legacyHash]);
        $this->assertTrue(Hash::needsRehash($legacyHash));

        $this->postJson('/api/v1/auth/login', [
            'email' => 'legacy@example.com',
            'password' => 'freight-2019',
        ])->assertOk()->assertJsonPath('success', true);

        $stored = User::find($user->id)->password;

        $this->assertNotSame($legacyHash, $stored, 'the hash should have been upgraded');
        $this->assertFalse(Hash::needsRehash($stored), 'the new hash should match the configured cost');
        $this->assertTrue(Hash::check('freight-2019', $stored), 'the password must still verify');
    }
}
