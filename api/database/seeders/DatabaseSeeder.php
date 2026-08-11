<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Order matters: users exist before jobs reference them, and jobs exist
     * before content seeds pick users to attach tickets to.
     */
    public function run(): void
    {
        $this->call([
            FreightTaxonomySeeder::class,
            SubscriptionPlanSeeder::class,
            UserSeeder::class,
            MarketplaceSeeder::class,
            ContentSeeder::class,
        ]);

        $this->command?->newLine();
        $this->command?->info('Demo accounts (password: "password"):');
        $this->command?->line('  admin@freightmove.test    — admin');
        $this->command?->line('  shipper@freightmove.test  — shipper');
        $this->command?->line('  carrier@freightmove.test  — carrier');
    }
}
