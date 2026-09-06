<?php

namespace Tests\Feature;

use App\Models\FreightJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `legacy:import`, run twice.
 *
 * Re-runnability is the one property this command has to have. A cut-over does
 * not import once — it imports a rehearsal dump, then a fresher dump on the
 * day, over the top of the first. If the second pass fails, the choice on
 * cut-over day is between stale data and an empty database.
 *
 * It did fail. The pivot writes read as `upsert($batch, $keys, [])`, which
 * looks like "insert, and update nothing on conflict" and is not: Laravel's
 * Builder::upsert returns `$this->insert($values)` when the update list is
 * empty, so the whole thing degraded to a plain INSERT and the second run died
 * on a duplicate key. There was no test, so the claim in the docstring was the
 * only thing asserting it.
 *
 * The fixture is deliberately small — two shippers, two loads, the lookup rows
 * the importer needs — because the bug is in the second pass, not the volume.
 */
class LegacyImportRerunTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The fixture lives on its own connection, and that is not incidental.
     *
     * `RefreshDatabase` wraps each test in a transaction, and MySQL commits
     * implicitly on DDL — so creating these tables on the default connection
     * silently ends that transaction and the next savepoint operation fails
     * with "SAVEPOINT trans2 does not exist". An implicit commit is scoped to
     * the session that caused it, so issuing the DDL on a second connection
     * leaves the test's transaction intact.
     *
     * Same database, different session: the importer still finds the tables.
     */
    private const FIXTURE = 'legacy_fixture';

    protected function setUp(): void
    {
        parent::setUp();

        $mysql = config('database.connections.mysql');

        config([
            'database.connections.'.self::FIXTURE => $mysql,
            // What the importer reads from.
            'database.connections.legacy' => $mysql,
        ]);

        // Without the taxonomy the importer's name lookups resolve to nothing,
        // no pivot rows are written, and the very table this test exists to
        // cover stays empty — passing for the wrong reason.
        $this->seed(\Database\Seeders\FreightTaxonomySeeder::class);

        $this->makeLegacyTables();
        $this->seedLegacy();
    }

    protected function tearDown(): void
    {
        // Also on the fixture connection, for the same reason.
        foreach ($this->legacyTables() as $table) {
            Schema::connection(self::FIXTURE)->dropIfExists($table);
        }

        parent::tearDown();
    }

    /** @return list<string> */
    private function legacyTables(): array
    {
        return [
            'shipper', 'load_master', 'load_quotation', 'subscription_master',
            'subscription_details', 'paypal_transaction', 'blog_master',
            'suburb_master', 'distance_calculator',
        ];
    }

    private function makeLegacyTables(): void
    {
        Schema::connection(self::FIXTURE)->create('shipper', function ($t) {
            $t->string('id', 50)->primary();
            $t->integer('ship_car')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('email_id')->nullable();
            $t->string('phone_no', 50)->nullable();
            $t->string('shipper_type', 50)->nullable();
            $t->string('password')->nullable();
            $t->string('street_address')->nullable();
            $t->string('city', 100)->nullable();
            $t->string('state', 50)->nullable();
            $t->string('zip', 20)->nullable();
            $t->string('country', 100)->nullable();
            $t->text('business_profile')->nullable();
            $t->string('company_name')->nullable();
            $t->string('abn_number', 50)->nullable();
            $t->string('profile_img')->nullable();
            $t->dateTime('date_created')->nullable();
            $t->dateTime('date_updated')->nullable();
            $t->string('created_by', 50)->nullable();
            $t->string('updated_by', 50)->nullable();
        });

        Schema::connection(self::FIXTURE)->create('load_master', function ($t) {
            $t->string('id', 50)->primary();
            $t->string('shipper_id', 50);
            $t->text('short_desc')->nullable();
            $t->string('pickup_suburb')->nullable();
            $t->string('pickup_state', 10)->nullable();
            $t->string('dropoff_suburb')->nullable();
            $t->string('dropoff_state', 10)->nullable();
            $t->string('availability')->nullable();
            $t->text('load_dec')->nullable();
            $t->text('categories')->nullable();
            $t->text('truck_type')->nullable();
            $t->string('quantity', 50)->nullable();
            $t->string('length', 50)->nullable();
            $t->string('width', 50)->nullable();
            $t->string('height', 50)->nullable();
            $t->string('weight', 50)->nullable();
            $t->date('readyon')->nullable();
            $t->string('load_img', 250)->nullable();
            $t->integer('bulk_email')->default(0);
            $t->dateTime('date_created')->nullable();
            $t->dateTime('date_updated')->nullable();
            $t->string('created_by', 50)->nullable();
            $t->string('updated_by', 50)->nullable();
        });

        Schema::connection(self::FIXTURE)->create('load_quotation', function ($t) {
            $t->string('id', 50)->primary();
            $t->string('carrier_id', 100)->nullable();
            $t->string('load_id', 100)->nullable();
            $t->float('price_quoted', 15, 2)->nullable();
            $t->text('notes')->nullable();
            $t->string('created_by', 100)->nullable();
            $t->string('updated_by', 100)->nullable();
            $t->dateTime('date_created')->nullable();
            $t->dateTime('date_updated')->nullable();
        });

        Schema::connection(self::FIXTURE)->create('subscription_master', function ($t) {
            $t->string('id', 50)->primary();
            $t->string('item_name', 256)->nullable();
            $t->float('price')->nullable();
            $t->integer('month_no')->nullable();
            $t->dateTime('date_created')->nullable();
            $t->dateTime('date_updated')->nullable();
        });

        Schema::connection(self::FIXTURE)->create('subscription_details', function ($t) {
            $t->string('id', 30)->primary();
            $t->string('paypal_trans', 30)->nullable();
            $t->string('client_id', 30)->nullable();
            $t->integer('subscription_type')->nullable();
            $t->date('start_from')->nullable();
            $t->date('end_to')->nullable();
            $t->dateTime('date_created')->nullable();
        });

        Schema::connection(self::FIXTURE)->create('paypal_transaction', function ($t) {
            $t->string('id', 50)->primary();
            $t->string('user_id', 50)->nullable();
            $t->string('payer_id', 100)->nullable();
            $t->string('payer_name')->nullable();
            $t->string('payer_email')->nullable();
            $t->dateTime('payment_date')->nullable();
            $t->string('subscription', 10)->nullable();
            $t->float('payment_amount')->nullable();
            $t->string('status', 50)->nullable();
            $t->string('currency_code', 10)->nullable();
            $t->dateTime('date_created')->nullable();
            $t->dateTime('date_updated')->nullable();
        });

        // These three mirror the real columns, taken from the production
        // schema rather than guessed — the importer orders by `date_created`,
        // so an invented shape fails on a column that does not exist.
        Schema::connection(self::FIXTURE)->create('blog_master', function ($t) {
            $t->string('id', 30)->primary();
            $t->string('blog_page', 256)->nullable();
            $t->string('page_slug', 256)->nullable();
            $t->longText('blog_description')->nullable();
            $t->text('featured_image')->nullable();
            $t->text('meta_title')->nullable();
            $t->text('meta_des')->nullable();
            $t->text('meta_tags')->nullable();
            $t->dateTime('date_created')->nullable();
            $t->dateTime('date_updated')->nullable();
            $t->string('created_by', 100)->nullable();
            $t->string('update_by', 100)->nullable();
        });

        Schema::connection(self::FIXTURE)->create('suburb_master', function ($t) {
            $t->string('id', 30)->primary();
            $t->string('state', 100)->nullable();
            $t->string('suburb', 200)->nullable();
        });

        Schema::connection(self::FIXTURE)->create('distance_calculator', function ($t) {
            $t->string('id', 50)->primary();
            $t->string('pickup', 50)->nullable();
            $t->string('dropoff', 50)->nullable();
            $t->string('distance', 50)->nullable();
            $t->string('time_duration', 100)->nullable();
            $t->integer('count')->nullable();
            $t->dateTime('date_created')->nullable();
        });
    }

    private function seedLegacy(): void
    {
        DB::connection(self::FIXTURE)->table('shipper')->insert([
            [
                'id' => '1001', 'ship_car' => 1, 'first_name' => 'Wendy', 'last_name' => 'Whitfield',
                'email_id' => 'wendy@example.test', 'phone_no' => '0400000001',
                'company_name' => 'Whitfield Freight', 'city' => 'Sydney', 'state' => 'NSW',
                'password' => md5('secret'), 'date_created' => '2024-01-05 09:00:00',
            ],
            [
                'id' => '1002', 'ship_car' => 2, 'first_name' => 'Bruno', 'last_name' => 'Katsav',
                'email_id' => 'bruno@example.test', 'phone_no' => '0400000002',
                'company_name' => 'Katsav Haulage', 'city' => 'Dubbo', 'state' => 'NSW',
                'password' => md5('secret'), 'date_created' => '2024-02-05 09:00:00',
            ],
        ]);

        // Two loads, each with several categories and truck types — the pivot
        // rows are what broke, so the fixture has to produce plenty of them.
        DB::connection(self::FIXTURE)->table('load_master')->insert([
            [
                'id' => '2001', 'shipper_id' => '1001', 'short_desc' => 'Excavator relocation',
                'pickup_suburb' => 'Sydney', 'pickup_state' => 'NSW',
                'dropoff_suburb' => 'Dubbo', 'dropoff_state' => 'NSW',
                'categories' => 'General Full Load,Bulk Products', 'truck_type' => 'Tipper,Tanker',
                'weight' => '12000', 'length' => '6000', 'width' => '2500', 'height' => '3000',
                'bulk_email' => 1, 'date_created' => '2024-03-05 09:00:00',
            ],
            [
                'id' => '2002', 'shipper_id' => '1001', 'short_desc' => 'Pallets of tiles',
                'pickup_suburb' => 'Newcastle', 'pickup_state' => 'NSW',
                'dropoff_suburb' => 'Sydney', 'dropoff_state' => 'NSW',
                'categories' => 'General Part Load', 'truck_type' => 'Platform',
                // Same key set as the row above: a batched insert builds one
                // column list from the first row, so a shorter second row is
                // a column-count error rather than a null.
                'weight' => '2000', 'length' => null, 'width' => null, 'height' => null,
                'bulk_email' => 1, 'date_created' => '2024-04-05 09:00:00',
            ],
        ]);

        DB::connection(self::FIXTURE)->table('load_quotation')->insert([
            [
                'id' => '3001', 'carrier_id' => '1002', 'load_id' => '2001',
                'price_quoted' => 1450.00, 'notes' => 'Can do Tuesday.',
                'created_by' => '1002', 'updated_by' => '1002',
                'date_created' => '2024-03-06 09:00:00', 'date_updated' => '2024-03-06 09:00:00',
            ],
        ]);

        DB::connection(self::FIXTURE)->table('subscription_master')->insert([
            ['id' => '1583243636', 'item_name' => 'Monthly Subscription', 'price' => 64.99, 'month_no' => 1,
             'date_created' => '2024-01-01 00:00:00', 'date_updated' => '2024-01-01 00:00:00'],
        ]);

        DB::connection(self::FIXTURE)->table('subscription_details')->insert([
            ['id' => '4001', 'paypal_trans' => '5001', 'client_id' => '1002', 'subscription_type' => 1,
             'start_from' => '2024-03-01', 'end_to' => '2024-04-01', 'date_created' => '2024-03-01 09:00:00'],
        ]);

        DB::connection(self::FIXTURE)->table('paypal_transaction')->insert([
            ['id' => '5001', 'user_id' => '1002', 'payer_id' => 'PAYER1', 'payer_name' => 'Bruno Katsav',
             'payer_email' => 'bruno@example.test', 'payment_date' => '2024-03-01 09:00:00',
             'subscription' => '1', 'payment_amount' => 64.99, 'status' => 'COMPLETED',
             'currency_code' => 'AUD', 'date_created' => '2024-03-01 09:00:00',
             'date_updated' => '2024-03-01 09:00:00'],
        ]);
    }

    /**
     * Runs the importer and fails with its own output.
     *
     * `$this->artisan(...)->assertExitCode(0)` reports the number and swallows
     * the reason, which turns "the import broke" into a guessing game.
     */
    private function import(string $options = ''): void
    {
        $exit = Artisan::call(trim('legacy:import '.$options));

        $this->assertSame(0, $exit, "legacy:import failed:
".Artisan::output());
    }

    /**
     * The whole point. A cut-over imports twice: a rehearsal, then the real
     * dump on the day. The second pass used to die on a duplicate key in
     * `category_freight_job`.
     */
    public function test_the_import_can_be_run_twice(): void
    {
        $this->import();

        $usersAfterFirst = User::count();
        $jobsAfterFirst = FreightJob::count();
        $pivotAfterFirst = DB::table('category_freight_job')->count();

        $this->assertGreaterThan(0, $jobsAfterFirst, 'The first pass should import something.');
        $this->assertGreaterThan(0, $pivotAfterFirst, 'The fixture must produce pivot rows.');

        // Second pass over the same data.
        $this->import();

        $this->assertSame($usersAfterFirst, User::count(), 'Re-importing duplicated users.');
        $this->assertSame($jobsAfterFirst, FreightJob::count(), 'Re-importing duplicated loads.');
        $this->assertSame(
            $pivotAfterFirst,
            DB::table('category_freight_job')->count(),
            'Re-importing duplicated the category pivot.',
        );
    }

    /** A fresher dump must update the existing rows, not sit beside them. */
    public function test_a_second_pass_picks_up_changes_and_new_rows(): void
    {
        $this->import();
        $jobsAfterFirst = FreightJob::count();

        // The old site keeps running between the rehearsal and the cut-over.
        DB::connection(self::FIXTURE)->table('load_master')->where('id', '2001')->update(['short_desc' => 'Excavator relocation (urgent)']);
        DB::connection(self::FIXTURE)->table('load_master')->insert([
            'id' => '2003', 'shipper_id' => '1001', 'short_desc' => 'Steel beams',
            'pickup_suburb' => 'Wollongong', 'pickup_state' => 'NSW',
            'dropoff_suburb' => 'Sydney', 'dropoff_state' => 'NSW',
            'categories' => 'Shipping Containers', 'truck_type' => 'Side Loader',
            'bulk_email' => 1, 'date_created' => '2024-05-05 09:00:00',
        ]);

        $this->import();

        $this->assertSame($jobsAfterFirst + 1, FreightJob::count(), 'The new load was not imported.');
        $this->assertSame(
            'Excavator relocation (urgent)',
            FreightJob::where('legacy_id', '2001')->value('title'),
            'The edited load was not updated.',
        );
    }

    /** A dry run must leave the database exactly as it found it. */
    public function test_a_dry_run_writes_nothing(): void
    {
        $before = User::count();

        $this->import('--dry-run');

        $this->assertSame($before, User::count());
        $this->assertSame(0, FreightJob::whereNotNull('legacy_id')->count());
    }
}
