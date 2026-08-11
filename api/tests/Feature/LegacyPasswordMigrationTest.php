<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The journey for the 536 accounts brought over from freightmove.au.
 *
 * The rule these tests defend: an existing customer must be able to sign in
 * with the password they already have. Only after they are in do we invite them
 * to choose a new one. Nothing about the migration may block that first login.
 */
class LegacyPasswordMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const LEGACY_PASSWORD = 'their-old-pw-7';

    /** An imported account: legacy bcrypt cost 10, never changed here. */
    private function legacyUser(string $email = 'existing@customer.com.au'): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'role' => UserRole::Shipper,
            'status' => UserStatus::Active,
        ]);

        // Written raw, exactly as ImportLegacyData writes it, so the model's
        // `hashed` cast cannot touch the hash.
        DB::table('users')->where('id', $user->id)->update([
            'legacy_id' => '1645941147',
            'password' => password_hash(self::LEGACY_PASSWORD, PASSWORD_BCRYPT, ['cost' => 10]),
            'password_changed_at' => null,
        ]);

        return $user->fresh();
    }

    // -- The first login must work --------------------------------------------

    public function test_an_existing_customer_signs_in_with_their_old_password(): void
    {
        $this->legacyUser();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'existing@customer.com.au',
            'password' => self::LEGACY_PASSWORD,
        ])->assertOk()->assertJsonPath('success', true);
    }

    /**
     * The password policy tightened during the rebuild. It applies to passwords
     * being *set*, never to one being *checked* — otherwise every customer whose
     * old password is short would be locked out of their own account.
     */
    public function test_a_weak_legacy_password_still_signs_in(): void
    {
        $user = User::factory()->create([
            'email' => 'weakpw@customer.com.au',
            'role' => UserRole::Carrier,
            'status' => UserStatus::Active,
        ]);

        DB::table('users')->where('id', $user->id)->update([
            'legacy_id' => '999',
            // Would be rejected outright by the current registration policy.
            'password' => password_hash('truck1', PASSWORD_BCRYPT, ['cost' => 10]),
            'password_changed_at' => null,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'weakpw@customer.com.au',
            'password' => 'truck1',
        ])->assertOk();
    }

    // -- Then we invite them to update ----------------------------------------

    public function test_an_imported_account_is_flagged_for_the_prompt(): void
    {
        $this->legacyUser();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'existing@customer.com.au',
            'password' => self::LEGACY_PASSWORD,
        ])->assertOk()->assertJsonPath('data.user.should_update_password', true);
    }

    public function test_a_native_account_is_never_prompted(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'New Customer',
            'email' => 'brand.new@example.com',
            'password' => 'correct-horse-9-battery',
            'password_confirmation' => 'correct-horse-9-battery',
            'role' => 'shipper',
        ])->assertCreated()->assertJsonPath('data.user.should_update_password', false);
    }

    // -- Changing it ----------------------------------------------------------

    public function test_the_prompt_clears_once_they_choose_a_new_password(): void
    {
        $user = $this->legacyUser();

        $this->actingAs($user)->putJson('/api/v1/auth/password', [
            'current_password' => self::LEGACY_PASSWORD,
            'password' => 'my-new-freight-9-pw',
            'password_confirmation' => 'my-new-freight-9-pw',
        ])->assertOk()->assertJsonPath('data.user.should_update_password', false);

        $user->refresh();
        $this->assertNotNull($user->password_changed_at);
        $this->assertTrue(Hash::check('my-new-freight-9-pw', $user->password));
    }

    public function test_the_new_password_must_meet_the_current_policy(): void
    {
        $user = $this->legacyUser();

        $this->actingAs($user)->putJson('/api/v1/auth/password', [
            'current_password' => self::LEGACY_PASSWORD,
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_the_current_password_must_be_correct(): void
    {
        $user = $this->legacyUser();

        $this->actingAs($user)->putJson('/api/v1/auth/password', [
            'current_password' => 'not-their-password',
            'password' => 'my-new-freight-9-pw',
            'password_confirmation' => 'my-new-freight-9-pw',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        // And the stored password is untouched.
        $this->assertTrue(Hash::check(self::LEGACY_PASSWORD, $user->fresh()->password));
    }

    public function test_changing_the_password_signs_other_devices_out(): void
    {
        $user = $this->legacyUser();

        // A session someone else may hold.
        $otherToken = $user->createToken('other-device')->plainTextToken;
        $mine = $user->createToken('this-device')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$mine}")
            ->putJson('/api/v1/auth/password', [
                'current_password' => self::LEGACY_PASSWORD,
                'password' => 'my-new-freight-9-pw',
                'password_confirmation' => 'my-new-freight-9-pw',
            ])->assertOk();

        // Asserted against the token table rather than by replaying the revoked
        // token: the test client keeps the user it resolved for the previous
        // request, so a second call would pass regardless. The rows are what the
        // controller actually governs.
        $remaining = $user->tokens()->pluck('name');

        $this->assertNotContains('other-device', $remaining, 'the other session should be revoked');
        $this->assertContains('this-device', $remaining, 'the current session must survive the change');
        $this->assertCount(1, $remaining);
    }

    public function test_a_guest_cannot_change_a_password(): void
    {
        $this->putJson('/api/v1/auth/password', [
            'current_password' => self::LEGACY_PASSWORD,
            'password' => 'my-new-freight-9-pw',
            'password_confirmation' => 'my-new-freight-9-pw',
        ])->assertUnauthorized();
    }
}
