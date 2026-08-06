<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Carrier;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Each dashboard area is reachable only by its own role.
 */
class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, string}>
     */
    public static function areaProvider(): array
    {
        return [
            'shipper area' => ['shipper', '/api/v1/shipper/overview'],
            'carrier area' => ['carrier', '/api/v1/carrier/overview'],
            'admin area' => ['admin', '/api/v1/admin/overview'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('areaProvider')]
    public function test_the_matching_role_is_allowed_in(string $role, string $url): void
    {
        $user = $this->userWithRole($role);

        $this->actingAs($user)->getJson($url)
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('areaProvider')]
    public function test_other_roles_are_refused(string $role, string $url): void
    {
        foreach (['shipper', 'carrier', 'admin'] as $other) {
            if ($other === $role) {
                continue;
            }

            $this->actingAs($this->userWithRole($other))
                ->getJson($url)
                ->assertStatus(403)
                ->assertJsonPath('success', false);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('areaProvider')]
    public function test_guests_are_refused(string $role, string $url): void
    {
        $this->getJson($url)
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_a_suspended_user_with_a_valid_token_is_cut_off(): void
    {
        $user = $this->userWithRole('shipper');
        $token = $user->createToken('web')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/shipper/overview')
            ->assertOk();

        $user->update(['status' => UserStatus::Suspended]);

        // The auth manager memoises the resolved user across requests within a
        // single test. A real request boots a fresh container, so drop the
        // cached guard to reproduce what the browser would actually get.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/shipper/overview')
            ->assertStatus(403);

        // The middleware also burns the token, so it cannot be replayed.
        $this->assertSame(0, $user->fresh()->tokens()->count());
    }

    public function test_a_pending_account_can_still_reach_its_dashboard(): void
    {
        // Carriers sign in while unverified in order to upload documents.
        $user = $this->userWithRole('carrier');
        $user->update(['status' => UserStatus::Pending]);

        $this->actingAs($user)
            ->getJson('/api/v1/carrier/overview')
            ->assertOk();
    }

    public function test_the_shipper_overview_reports_that_shippers_own_jobs(): void
    {
        $user = $this->userWithRole('shipper');
        \App\Models\FreightJob::factory()->count(3)->create(['shipper_id' => $user->id]);
        \App\Models\FreightJob::factory()->count(2)->create();

        $this->actingAs($user)
            ->getJson('/api/v1/shipper/overview')
            ->assertOk()
            ->assertJsonPath('data.jobs.total', 3);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        UserProfile::factory()->create(['user_id' => $user->id]);

        if ($role === 'carrier') {
            Carrier::factory()->create(['user_id' => $user->id]);
        }

        return $user;
    }
}
