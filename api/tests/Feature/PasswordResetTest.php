<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Forgotten-password flow.
 *
 * The link in the email is the part that was broken: Laravel's default builds
 * it from the `password.reset` web route, which an API-only application does
 * not have, so a *registered* address produced a 500 while an unregistered one
 * looked fine. These tests use a real account for that reason.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_registered_address_is_sent_a_link(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'dana@example.com']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'dana@example.com'])
            ->assertOk()
            ->assertJsonPath('success', true);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    /**
     * The link must land on the Angular app. If it points at the API the user
     * gets a 404 and the reset is unusable, which is what shipped before.
     */
    public function test_the_link_points_at_the_front_end_reset_page(): void
    {
        Notification::fake();
        config(['freightmove.frontend_url' => 'https://freightmove.au']);

        $user = User::factory()->create(['email' => 'dana@example.com']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'dana@example.com'])
            ->assertOk();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $mail = $notification->toMail($user);
            $url = $mail->actionUrl;

            $this->assertStringStartsWith('https://freightmove.au/reset-password/', $url);
            $this->assertStringContainsString('email=dana%40example.com', $url);

            return true;
        });
    }

    public function test_an_unregistered_address_gets_the_same_answer(): void
    {
        Notification::fake();

        // Whether an address is registered is not something an anonymous caller
        // should be able to probe, so the response must not differ.
        $registered = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com']);

        $registered->assertOk()->assertJsonPath('success', true);
        Notification::assertNothingSent();
    }

    public function test_a_valid_token_sets_the_new_password(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'email' => 'dana@example.com',
            'password' => Hash::make('the-old-password-1'),
        ]);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'dana@example.com'])->assertOk();

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => 'dana@example.com',
            'password' => 'a-brand-new-password-9',
            'password_confirmation' => 'a-brand-new-password-9',
        ])->assertOk();

        $this->assertTrue(Hash::check('a-brand-new-password-9', $user->fresh()->password));
    }

    public function test_a_reset_clears_the_legacy_password_prompt(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'email' => 'dana@example.com',
            'password_changed_at' => null,
        ]);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'dana@example.com'])->assertOk();

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => 'dana@example.com',
            'password' => 'a-brand-new-password-9',
            'password_confirmation' => 'a-brand-new-password-9',
        ])->assertOk();

        $this->assertNotNull(
            $user->fresh()->password_changed_at,
            'Choosing a password by reset counts as choosing one here.',
        );
    }

    public function test_an_invalid_token_is_refused(): void
    {
        User::factory()->create(['email' => 'dana@example.com']);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'not-a-real-token',
            'email' => 'dana@example.com',
            'password' => 'a-brand-new-password-9',
            'password_confirmation' => 'a-brand-new-password-9',
        ])->assertStatus(422);
    }

    public function test_a_reset_revokes_existing_sessions(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'dana@example.com']);
        $user->createToken('old-session');

        $this->assertSame(1, $user->tokens()->count());

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'dana@example.com'])->assertOk();

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => 'dana@example.com',
            'password' => 'a-brand-new-password-9',
            'password_confirmation' => 'a-brand-new-password-9',
        ])->assertOk();

        // Anything opened with the old password is void.
        $this->assertSame(0, $user->tokens()->count());
    }
}
