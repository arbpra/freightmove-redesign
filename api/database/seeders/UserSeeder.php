<?php

namespace Database\Seeders;

use App\Enums\DocumentStatus;
use App\Enums\UserStatus;
use App\Models\Carrier;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\VehicleType;
use App\Models\VerificationDocument;
use Illuminate\Database\Seeder;

/**
 * Seeds the three roles plus the demo accounts used for manual QA.
 *
 * Every seeded account uses the password "password".
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::factory()->admin()->create([
            'name' => 'Avery Chen',
            'email' => 'admin@freightmove.test',
        ]);

        UserProfile::factory()->verified()->create([
            'user_id' => $admin->id,
            'company_name' => 'FreightMove Operations',
        ]);

        // Named demo shipper, then a spread of others.
        $this->makeShipper('Jordan Blake', 'shipper@freightmove.test', 'Blake Manufacturing');

        User::factory()->shipper()->count(7)->create()->each(function (User $user) {
            UserProfile::factory()->verified()->create(['user_id' => $user->id]);
        });

        // One shipper still waiting on email verification, for onboarding states.
        $pending = User::factory()->shipper()->pending()->create();
        UserProfile::factory()->create(['user_id' => $pending->id]);

        // Named demo carrier, fully verified with a small fleet.
        $this->makeCarrier('Sam Whitfield', 'carrier@freightmove.test', 'Whitfield Haulage', $admin->id, verified: true);

        for ($i = 0; $i < 9; $i++) {
            $this->makeCarrier(
                fake()->name(),
                fake()->unique()->safeEmail(),
                fake()->company().' Transport',
                $admin->id,
                verified: true,
            );
        }

        // Two carriers sitting in the admin verification queue.
        for ($i = 0; $i < 2; $i++) {
            $this->makeCarrier(
                fake()->name(),
                fake()->unique()->safeEmail(),
                fake()->company().' Freight',
                $admin->id,
                verified: false,
            );
        }
    }

    private function makeShipper(string $name, string $email, string $company): User
    {
        $user = User::factory()->shipper()->create([
            'name' => $name,
            'email' => $email,
        ]);

        UserProfile::factory()->verified()->create([
            'user_id' => $user->id,
            'company_name' => $company,
        ]);

        return $user;
    }

    private function makeCarrier(string $name, string $email, string $company, int $adminId, bool $verified): User
    {
        $user = User::factory()->carrier()->create([
            'name' => $name,
            'email' => $email,
            'status' => $verified ? UserStatus::Active : UserStatus::Pending,
        ]);

        $profile = UserProfile::factory();
        $profile = $verified ? $profile->verified() : $profile->awaitingVerification();
        $profile->create([
            'user_id' => $user->id,
            'company_name' => $company,
        ]);

        $carrier = Carrier::factory()->create(['user_id' => $user->id]);

        VehicleType::factory()
            ->count(fake()->numberBetween(1, 3))
            ->create(['carrier_id' => $carrier->id]);

        // A verified carrier needs an approved document of *each* required type,
        // otherwise the badge and the requirements list contradict each other.
        $requiredTypes = array_keys(array_filter(
            config('freightmove.verification.document_types', []),
            fn (array $type) => $type['required'] ?? false,
        ));

        foreach ($requiredTypes as $type) {
            $factory = VerificationDocument::factory();

            if ($verified) {
                $factory->approved($adminId)->create([
                    'user_id' => $user->id,
                    'document_type' => $type,
                ]);
            } else {
                $factory->create([
                    'user_id' => $user->id,
                    'document_type' => $type,
                    'status' => DocumentStatus::Pending,
                ]);
            }
        }

        return $user;
    }
}
