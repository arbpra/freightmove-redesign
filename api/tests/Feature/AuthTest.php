<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Carrier;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Kim Alvarez',
            'email' => 'kim@example.com',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
            'role' => 'carrier',
        ])->assertCreated();

        $user = User::where('email', 'kim@example.com')->firstOrFail();

        $this->assertSame(UserRole::Carrier, $user->role);
        $this->assertSame(1, Carrier::where('user_id', $user->id)->count());
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
}
