<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\TruckType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Freight categories and truck types.
 *
 * These are **not** invented: they are the complete set of distinct values found
 * in the live `old_freightmove.load_master` data, so every imported load can be
 * mapped without inventing or dropping a value. The legacy database has no
 * lookup table of its own — the columns held display names — so this seeder is
 * where that vocabulary becomes real.
 *
 * Idempotent: keyed on slug, so re-running updates rather than duplicates.
 */
class FreightTaxonomySeeder extends Seeder
{
    /**
     * Ordered by how often live customers select them, commonest first, so the
     * pickers put the likely answer at the top.
     *
     * @var list<string>
     */
    private const CATEGORIES = [
        'Machinery (Mobile)',
        'General Part Load',
        'Trucks or Prime Movers',
        'Pallets (Less Than a Load)',
        'Car',
        'Shipping Containers',
        'Machinery (Stationary)',
        'Bulk Products',
        'General Full Load',
        'Trailers to be Towed',
        'Trailers to be carried',
        'Caravan or Camper Trailer',
        'Boat',
    ];

    /** @var list<string> */
    private const TRUCK_TYPES = [
        'Drop Deck',
        'Low Loader',
        'Flat Top',
        'Float',
        'Tiltray',
        'Rigid Flat Top',
        'Tautliner',
        'Crane Truck',
        'Car Carrier',
        'Platform',
        'Bobtail Prime Mover',
        'Side Loader',
        'Tipper',
        'Rigid Panteck',
        'Tanker',
        'Refrigerated',
        'Livestock',
        'UTE',
        'Driver',
    ];

    public function run(): void
    {
        foreach (self::CATEGORIES as $order => $name) {
            Category::updateOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'sort_order' => $order, 'is_active' => true],
            );
        }

        foreach (self::TRUCK_TYPES as $order => $name) {
            TruckType::updateOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'sort_order' => $order, 'is_active' => true],
            );
        }
    }
}
