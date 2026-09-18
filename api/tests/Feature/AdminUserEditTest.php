<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * An admin viewing and editing a customer account.
 *
 * The support cases are real: a shipper who mistyped their email at
 * registration cannot receive the reset link that would let them fix it, and a
 * carrier who has lost the mailbox their account was registered with cannot
 * recover it at all.
 *
 * Almost every test here is about a limit rather than a capability, because
 * this is the part of the console that can take an account away from the
 * person who owns it.
 */
class AdminUserEditTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
        ]);
    }

    private function carrier(array $attributes = []): User
    {
        $user = User::factory()->create([
            'role' => UserRole::Carrier,
            'status' => UserStatus::Active,
            ...$attributes,
        ]);
        UserProfile::factory()->create(['user_id' => $user->id]);

        return $user;
    }

    // -- Reading --------------------------------------------------------------

    public function test_an_admin_sees_a_full_account(): void
    {
        $carrier = $this->carrier(['email' => 'hauler@example.test', 'phone' => '0400 000 000']);
        $carrier->profile()->update(['company_name' => 'Redline Haulage', 'city' => 'Dubbo']);

        $this->actingAs($this->admin())
            ->getJson("/api/v1/admin/users/{$carrier->id}")
            ->assertOk()
            ->assertJsonPath('data.email', 'hauler@example.test')
            ->assertJsonPath('data.phone', '0400 000 000')
            ->assertJsonPath('data.profile.company_name', 'Redline Haulage')
            ->assertJsonPath('data.profile.city', 'Dubbo');
    }

    /** There is nothing an admin can do with a hash that setting one does not do better. */
    public function test_the_password_hash_is_never_returned(): void
    {
        $carrier = $this->carrier();

        $body = $this->actingAs($this->admin())
            ->getJson("/api/v1/admin/users/{$carrier->id}")
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('password"', $body);
        $this->assertStringNotContainsString($carrier->fresh()->password, $body);
    }

    public function test_a_customer_cannot_read_another_account(): void
    {
        $carrier = $this->carrier();

        $this->actingAs($this->carrier())
            ->getJson("/api/v1/admin/users/{$carrier->id}")
            ->assertForbidden();
    }

    // -- Editing --------------------------------------------------------------

    public function test_an_admin_can_correct_contact_details(): void
    {
        $carrier = $this->carrier(['email' => 'typo@example.test']);

        $this->actingAs($this->admin())
            ->patchJson("/api/v1/admin/users/{$carrier->id}", [
                'email' => 'correct@example.test',
                'phone' => '0411 111 111',
                'profile' => ['company_name' => 'Redline Haulage Pty Ltd'],
            ])
            ->assertOk()
            ->assertJsonPath('data.email', 'correct@example.test');

        $carrier->refresh();
        $this->assertSame('correct@example.test', $carrier->email);
        $this->assertSame('Redline Haulage Pty Ltd', $carrier->profile->company_name);
    }

    /** Email is the login identity; two accounts sharing one is unrecoverable. */
    public function test_an_email_already_in_use_is_refused(): void
    {
        $taken = $this->carrier(['email' => 'taken@example.test']);
        $carrier = $this->carrier();

        $this->actingAs($this->admin())
            ->patchJson("/api/v1/admin/users/{$carrier->id}", ['email' => 'taken@example.test'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertSame('taken@example.test', $taken->fresh()->email);
    }

    /**
     * The privilege boundary the whole authorisation layer rests on. An edit
     * form that quietly accepts a role is how it gets crossed by accident.
     */
    public function test_the_edit_cannot_change_a_role(): void
    {
        $carrier = $this->carrier();

        $this->actingAs($this->admin())
            ->patchJson("/api/v1/admin/users/{$carrier->id}", [
                'name' => 'Still A Carrier',
                'role' => UserRole::Admin->value,
            ])
            ->assertOk();

        $this->assertSame(UserRole::Carrier, $carrier->fresh()->role);
    }

    /** Nor may it reinstate a suspended account — that has its own endpoint. */
    public function test_the_edit_cannot_change_a_status(): void
    {
        $carrier = $this->carrier(['status' => UserStatus::Suspended]);

        $this->actingAs($this->admin())
            ->patchJson("/api/v1/admin/users/{$carrier->id}", [
                'status' => UserStatus::Active->value,
            ])
            ->assertOk();

        $this->assertSame(UserStatus::Suspended, $carrier->fresh()->status);
    }

    public function test_one_admin_cannot_edit_another(): void
    {
        $other = $this->admin();

        $this->actingAs($this->admin())
            ->patchJson("/api/v1/admin/users/{$other->id}", ['name' => 'Renamed'])
            ->assertStatus(422);

        $this->assertNotSame('Renamed', $other->fresh()->name);
    }

    // -- Setting a password ---------------------------------------------------

    public function test_an_admin_can_set_a_customer_password(): void
    {
        $carrier = $this->carrier();
        $was = $carrier->password;

        $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/users/{$carrier->id}/password", [
                'password' => 'k7Rm2Qp9Xv4Ld8Ta',
                'reason' => 'Lost access to their registered mailbox.',
            ])
            ->assertOk();

        $carrier->refresh();
        $this->assertNotSame($was, $carrier->password);
        $this->assertTrue(Hash::check('k7Rm2Qp9Xv4Ld8Ta', $carrier->password));
    }

    /**
     * A reset that leaves live tokens behind does not take the account back
     * from whoever had it — which is usually why the reset was asked for.
     */
    public function test_setting_a_password_signs_the_account_out_everywhere(): void
    {
        $carrier = $this->carrier();
        $carrier->createToken('phone');
        $this->assertSame(1, $carrier->tokens()->count());

        $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/users/{$carrier->id}/password", [
                'password' => 'k7Rm2Qp9Xv4Ld8Ta',
            ])
            ->assertOk();

        $this->assertSame(0, $carrier->fresh()->tokens()->count());
    }

    /** One compromised admin account would otherwise be every admin account. */
    public function test_an_admin_password_cannot_be_set_from_here(): void
    {
        $other = $this->admin();
        $was = $other->password;

        $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/users/{$other->id}/password", [
                'password' => 'k7Rm2Qp9Xv4Ld8Ta',
            ])
            ->assertStatus(422);

        $this->assertSame($was, $other->fresh()->password);
    }

    /** Your own goes through the flow that asks for the current password. */
    public function test_an_admin_cannot_set_their_own_password_here(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/users/{$admin->id}/password", [
                'password' => 'k7Rm2Qp9Xv4Ld8Ta',
            ])
            ->assertStatus(422);
    }

    public function test_a_weak_password_is_refused(): void
    {
        $carrier = $this->carrier();

        $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/users/{$carrier->id}/password", ['password' => 'abc'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_a_customer_cannot_set_anyone_password(): void
    {
        $victim = $this->carrier();

        $this->actingAs($this->carrier())
            ->postJson("/api/v1/admin/users/{$victim->id}/password", [
                'password' => 'k7Rm2Qp9Xv4Ld8Ta',
            ])
            ->assertForbidden();
    }
}
