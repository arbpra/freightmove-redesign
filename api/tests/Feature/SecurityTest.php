<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\FreightJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Regression cover for the findings in docs/11-security.md.
 *
 * Each test names the finding it guards so a future change that reopens one
 * fails here rather than in production.
 */
class SecurityTest extends TestCase
{
    use RefreshDatabase;

    // -- F1: tokens must expire ----------------------------------------------

    public function test_access_tokens_are_configured_to_expire(): void
    {
        $this->assertIsInt(config('sanctum.expiration'));
        $this->assertGreaterThan(0, config('sanctum.expiration'));
    }

    public function test_an_expired_token_is_refused(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Shipper,
            'status' => UserStatus::Active,
        ]);

        $token = $user->createToken('test')->plainTextToken;

        // Age the token past the configured window.
        $minutes = (int) config('sanctum.expiration');
        PersonalAccessToken::query()->update([
            'created_at' => Carbon::now()->subMinutes($minutes + 10),
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    // -- F2: password policy --------------------------------------------------

    /**
     * Laravel's unconfigured default accepts any 8 characters. These are the
     * passwords that must now be rejected.
     */
    public function test_weak_passwords_are_rejected_at_registration(): void
    {
        foreach (['password', 'abcdefgh', 'Sh0rt1'] as $weak) {
            $this->postJson('/api/v1/auth/register', [
                'name' => 'Test Person',
                'email' => 'weak'.md5($weak).'@example.com',
                'password' => $weak,
                'password_confirmation' => $weak,
                'role' => 'shipper',
            ])->assertStatus(422)->assertJsonValidationErrors('password');
        }
    }

    public function test_a_strong_password_is_accepted(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Test Person',
            'email' => 'strong@example.com',
            'password' => 'correct-horse-9-battery',
            'password_confirmation' => 'correct-horse-9-battery',
            'role' => 'shipper',
        ])->assertCreated();
    }

    // -- F4: privilege escalation --------------------------------------------

    public function test_a_visitor_cannot_register_themselves_as_an_admin(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Escalation Attempt',
            'email' => 'escalate@example.com',
            'password' => 'correct-horse-9-battery',
            'password_confirmation' => 'correct-horse-9-battery',
            'role' => 'admin',
        ])->assertStatus(422)->assertJsonValidationErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'escalate@example.com']);
    }

    public function test_a_shipper_cannot_reach_the_admin_area(): void
    {
        $shipper = User::factory()->create([
            'role' => UserRole::Shipper,
            'status' => UserStatus::Active,
        ]);

        $this->actingAs($shipper)->getJson('/api/v1/admin/overview')->assertForbidden();
    }

    // -- Ownership ------------------------------------------------------------

    public function test_a_job_belonging_to_someone_else_is_never_disclosed(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Shipper, 'status' => UserStatus::Active]);
        $attacker = User::factory()->create(['role' => UserRole::Shipper, 'status' => UserStatus::Active]);

        $job = FreightJob::factory()->create([
            'shipper_id' => $owner->id,
            'title' => 'Commercially sensitive load',
        ]);

        $response = $this->actingAs($attacker)->getJson("/api/v1/shipper/jobs/{$job->id}");

        $response->assertForbidden();
        // The refusal must not echo the record it is protecting.
        $response->assertDontSee('Commercially sensitive load');
    }

    // -- Response hardening ---------------------------------------------------

    public function test_security_headers_are_present_on_api_responses(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertNotNull($response->headers->get('Content-Security-Policy'));
    }

    public function test_password_hashes_are_never_serialised(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Shipper,
            'status' => UserStatus::Active,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/auth/me');

        $response->assertOk();
        $this->assertArrayNotHasKey('password', $response->json('data'));
        $response->assertDontSee($user->password);
    }

    // -- Rate limiting --------------------------------------------------------

    public function test_credential_stuffing_is_throttled(): void
    {
        $email = 'victim@example.com';

        // The limiter allows 6 per minute; the 7th must be refused.
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => $email,
                'password' => "guess-{$attempt}",
            ]);
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'guess-final',
        ])->assertStatus(429);
    }
}
