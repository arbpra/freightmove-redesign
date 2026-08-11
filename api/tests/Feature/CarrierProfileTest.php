<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\VerificationStatus;
use App\Models\Carrier;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A carrier managing their own profile.
 *
 * The interesting cases are the ones where a carrier tries to assert something
 * the platform is supposed to assert about them.
 */
class CarrierProfileTest extends TestCase
{
    use RefreshDatabase;

    private function carrier(array $profile = []): User
    {
        $user = User::factory()->create([
            'role' => UserRole::Carrier,
            'status' => UserStatus::Active,
        ]);
        UserProfile::factory()->create(['user_id' => $user->id, ...$profile]);
        Carrier::factory()->create(['user_id' => $user->id]);

        return $user;
    }

    public function test_a_carrier_reads_their_own_profile(): void
    {
        $carrier = $this->carrier(['company_name' => 'Whitfield Haulage']);

        $this->actingAs($carrier)
            ->getJson('/api/v1/carrier/profile')
            ->assertOk()
            ->assertJsonPath('data.profile.company_name', 'Whitfield Haulage')
            ->assertJsonPath('data.profile.email', $carrier->email)
            ->assertJsonStructure([
                'data' => [
                    'profile' => ['verification' => ['status', 'documents']],
                    'requirements' => ['document_types', 'missing', 'max_upload_kb'],
                ],
            ]);
    }

    public function test_a_carrier_updates_their_business_details(): void
    {
        $carrier = $this->carrier();

        $this->actingAs($carrier)
            ->patchJson('/api/v1/carrier/profile', [
                'company_name' => 'Whitfield Haulage',
                'abn_acn' => '51 824 753 556',
                'fleet_size' => 12,
                'preferred_regions' => ['NSW', 'QLD'],
                'insurance_provider' => 'Zurich',
            ])
            ->assertOk()
            ->assertJsonPath('data.profile.company_name', 'Whitfield Haulage')
            // Spaces are stripped before validation, so a natural paste works.
            ->assertJsonPath('data.profile.abn_acn', '51824753556')
            ->assertJsonPath('data.profile.fleet_size', 12)
            ->assertJsonPath('data.profile.preferred_regions', ['NSW', 'QLD']);
    }

    public function test_an_abn_of_the_wrong_length_is_refused(): void
    {
        $this->actingAs($this->carrier())
            ->patchJson('/api/v1/carrier/profile', ['abn_acn' => '123'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('abn_acn');
    }

    public function test_operating_since_cannot_be_in_the_future(): void
    {
        $this->actingAs($this->carrier())
            ->patchJson('/api/v1/carrier/profile', ['operating_since' => (int) date('Y') + 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('operating_since');
    }

    /**
     * `verification_status` is fillable on UserProfile because admins set it.
     * The form request is what stops a carrier setting it, so this is the test
     * that would catch someone widening the whitelist later.
     */
    public function test_a_carrier_cannot_verify_themselves(): void
    {
        $carrier = $this->carrier(['verification_status' => VerificationStatus::Unverified]);

        $this->actingAs($carrier)
            ->patchJson('/api/v1/carrier/profile', [
                'company_name' => 'Whitfield Haulage',
                'verification_status' => 'verified',
            ])
            ->assertOk();

        $this->assertSame(
            VerificationStatus::Unverified,
            $carrier->fresh()->profile->verification_status,
        );
    }

    public function test_a_carrier_cannot_award_themselves_a_rating(): void
    {
        $carrier = $this->carrier(['rating' => 3.0, 'completed_jobs_count' => 2]);

        $this->actingAs($carrier)
            ->patchJson('/api/v1/carrier/profile', [
                'rating' => 5,
                'completed_jobs_count' => 900,
            ])
            ->assertOk();

        $profile = $carrier->fresh()->profile;
        $this->assertSame('3.00', $profile->rating);
        $this->assertSame(2, $profile->completed_jobs_count);
    }

    /**
     * Verification was granted against a specific ABN and insurer. Changing
     * either means the approval no longer describes this business.
     */
    public function test_changing_a_verified_abn_sends_the_profile_back_for_review(): void
    {
        $carrier = $this->carrier([
            'verification_status' => VerificationStatus::Verified,
            'verified_at' => now(),
            'abn_acn' => '51824753556',
        ]);

        $this->actingAs($carrier)
            ->patchJson('/api/v1/carrier/profile', ['abn_acn' => '12345678901'])
            ->assertOk()
            ->assertJsonPath('data.profile.verification.status', 'pending');

        $this->assertNull($carrier->fresh()->profile->verified_at);
    }

    public function test_editing_something_harmless_keeps_verification(): void
    {
        $carrier = $this->carrier([
            'verification_status' => VerificationStatus::Verified,
            'verified_at' => now(),
        ]);

        $this->actingAs($carrier)
            ->patchJson('/api/v1/carrier/profile', ['bio' => 'Family run since 1998.'])
            ->assertOk()
            ->assertJsonPath('data.profile.verification.status', 'verified');
    }

    public function test_a_shipper_cannot_reach_the_carrier_profile(): void
    {
        $shipper = User::factory()->create([
            'role' => UserRole::Shipper,
            'status' => UserStatus::Active,
        ]);

        $this->actingAs($shipper)->getJson('/api/v1/carrier/profile')->assertForbidden();
    }

    public function test_guests_are_refused(): void
    {
        $this->getJson('/api/v1/carrier/profile')->assertUnauthorized();
    }
}
