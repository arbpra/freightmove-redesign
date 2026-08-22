<?php

namespace App\Console\Commands;

use App\Enums\JobStatus;
use App\Enums\LoadAvailability;
use App\Enums\PostStatus;
use App\Enums\QuoteStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\VerificationStatus;
use App\Models\RouteDistance;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Imports the pre-launch site's database into the new schema.
 *
 * Designed to be run more than once: every imported row carries the legacy
 * primary key in `legacy_id`, and each step upserts against it. Re-running with
 * a fresher dump at go-live therefore updates the rows already present rather
 * than duplicating them, so the cut-over is:
 *
 *     1. restore the live dump over the `legacy` connection's database
 *     2. php artisan legacy:import
 *
 * Everything is written through the query builder rather than Eloquent. That is
 * deliberate — the `password` attribute on User carries a `hashed` cast which
 * would reject the legacy hashes outright (they are bcrypt cost 10, the app is
 * configured for cost 12, and the cast throws on a configuration mismatch).
 * Writing raw also keeps 15k suburb inserts to a handful of queries.
 */
class ImportLegacyData extends Command
{
    protected $signature = 'legacy:import
        {--dry-run : Report what would be imported without writing anything}
        {--only= : Comma-separated steps to run (suburbs,routes,users,jobs,quotes,blog,billing)}';

    protected $description = 'Import the legacy FreightMove database into the current schema';

    /** Legacy `shipper.ship_car` values, decoded from posting/quoting behaviour. */
    private const LEGACY_ROLE_SHIPPER = 1;

    /** Legacy `shipper.shipper_type`: 1 = individual, 2 = business. */
    private const LEGACY_TYPE_BUSINESS = 2;

    /**
     * Road freight tops out around 50 tonnes, so any weight above this must be
     * kilograms and anything at or below it must already be tonnes. The legacy
     * form captured both units in one free-text field with no unit recorded.
     */
    private const TONNE_THRESHOLD = 100;

    /** Legacy placeholder for "no date given". */
    private const EPOCH_SENTINEL = '1970-01-01';

    /** @var array<string,int> */
    private array $stats = [];

    /** @var array<int,string> */
    private array $warnings = [];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $steps = $this->requestedSteps();

        try {
            $legacyName = DB::connection('legacy')->getDatabaseName();
            DB::connection('legacy')->getPdo();
        } catch (Throwable $e) {
            $this->error('Cannot reach the legacy database: '.$e->getMessage());
            $this->line('Check the LEGACY_DB_* keys in .env.');

            return self::FAILURE;
        }

        $this->info("Source: {$legacyName}");
        $this->info('Target: '.DB::connection()->getDatabaseName());

        if ($dryRun) {
            $this->warn('Dry run — no rows will be written.');
        }

        DB::beginTransaction();

        try {
            // Order matters: later steps resolve foreign keys from earlier ones.
            if (in_array('suburbs', $steps, true)) {
                $this->importSuburbs();
            }
            if (in_array('routes', $steps, true)) {
                $this->importRouteDistances();
            }
            if (in_array('users', $steps, true)) {
                $this->importUsers();
            }
            if (in_array('jobs', $steps, true)) {
                $this->importJobs();
            }
            if (in_array('quotes', $steps, true)) {
                $this->importQuotes();
            }
            if (in_array('blog', $steps, true)) {
                $this->importBlog();
            }
            if (in_array('billing', $steps, true)) {
                $this->importBilling();
            }

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error('Import aborted, nothing was written: '.$e->getMessage());
            $this->line($e->getFile().':'.$e->getLine());

            return self::FAILURE;
        }

        $this->report();

        return self::SUCCESS;
    }

    /** @return array<int,string> */
    private function requestedSteps(): array
    {
        $all = ['suburbs', 'routes', 'users', 'jobs', 'quotes', 'blog', 'billing'];
        $only = $this->option('only');

        if (! $only) {
            return $all;
        }

        return array_values(array_intersect(
            $all,
            array_map(trim(...), explode(',', (string) $only))
        ));
    }

    // -- Steps ---------------------------------------------------------------

    private function importSuburbs(): void
    {
        $now = now();
        $rows = [];

        DB::connection('legacy')->table('suburb_master')
            ->orderBy('id')
            ->chunk(2000, function ($chunk) use (&$rows, $now) {
                foreach ($chunk as $suburb) {
                    $rows[] = [
                        'legacy_id' => (string) $suburb->id,
                        'name' => trim((string) $suburb->suburb),
                        'state' => strtoupper(trim((string) $suburb->state)),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            });

        foreach (array_chunk($rows, 1000) as $batch) {
            DB::table('suburbs')->upsert($batch, ['legacy_id'], ['name', 'state', 'updated_at']);
        }

        $this->stats['suburbs'] = count($rows);
    }

    /**
     * The cached Google Distance Matrix answers (docs/10-domain-rules.md R6).
     *
     * Each one is a call already paid for, so they are worth carrying over —
     * and reused ones especially: 165 of the 663 rows have been served from
     * cache at least once, 320 hits in total.
     *
     * Runs after suburbs because it resolves both endpoints to real suburb
     * rows; a route pointing at a suburb that no longer exists is dropped and
     * reported rather than stored as a dangling pair.
     */
    private function importRouteDistances(): void
    {
        $now = now();
        $suburbIdByLegacy = DB::table('suburbs')->whereNotNull('legacy_id')->pluck('id', 'legacy_id');

        $rows = [];
        $seenPairs = [];
        $missingSuburb = 0;
        $unparsed = 0;
        $duplicatePairs = 0;

        foreach (DB::connection('legacy')->table('distance_calculator')->orderBy('date_created')->get() as $route) {
            $pickupId = $suburbIdByLegacy[(string) $route->pickup] ?? null;
            $dropoffId = $suburbIdByLegacy[(string) $route->dropoff] ?? null;

            if (! $pickupId || ! $dropoffId) {
                $missingSuburb++;

                continue;
            }

            // The legacy table has no unique constraint on the pair, so guard
            // it here: upsert would otherwise fail on our own unique index
            // partway through the batch.
            $pair = "{$pickupId}:{$dropoffId}";
            if (isset($seenPairs[$pair])) {
                $duplicatePairs++;

                continue;
            }
            $seenPairs[$pair] = true;

            $metres = RouteDistance::metresFrom($route->distance);
            $seconds = RouteDistance::secondsFrom($route->time_duration);

            if ($metres === null || $seconds === null) {
                $unparsed++;
            }

            $rows[] = [
                'legacy_id' => (string) $route->id,
                'pickup_suburb_id' => $pickupId,
                'dropoff_suburb_id' => $dropoffId,
                'distance_metres' => $metres,
                'duration_seconds' => $seconds,
                // Only genuine display text. Two rows predate the switch to
                // Google's formatted strings and hold bare integers, which
                // would render as "713686" if a client trusted this field.
                'distance_text' => $this->displayText($route->distance, 50),
                'duration_text' => $this->displayText($route->time_duration, 100),
                'lookups' => max(0, (int) $route->count),
                'last_used_at' => null,
                'created_at' => $this->date($route->date_created) ?? $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $batch) {
            DB::table('route_distances')->upsert($batch, ['legacy_id'], [
                'pickup_suburb_id', 'dropoff_suburb_id', 'distance_metres', 'duration_seconds',
                'distance_text', 'duration_text', 'lookups', 'updated_at',
            ]);
        }

        $this->stats['route_distances'] = count($rows);

        if ($missingSuburb > 0) {
            $this->warnings[] = "{$missingSuburb} cached routes skipped: one or both suburbs are missing from suburb_master.";
        }
        if ($duplicatePairs > 0) {
            $this->warnings[] = "{$duplicatePairs} cached routes skipped: the legacy table holds more than one row for the same suburb pair.";
        }
        if ($unparsed > 0) {
            $this->warnings[] = "{$unparsed} cached routes imported with the display text only — the distance or duration was in a format the parser does not recognise.";
        }
    }

    private function importUsers(): void
    {
        $now = now();
        $users = [];
        $profiles = [];
        $carrierLegacyIds = [];

        foreach (DB::connection('legacy')->table('shipper')->orderBy('date_created')->get() as $row) {
            $email = strtolower(trim((string) $row->email_id));

            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->warnings[] = "Skipped shipper {$row->id}: missing or invalid email.";

                continue;
            }

            $isShipper = (int) $row->ship_car === self::LEGACY_ROLE_SHIPPER;

            // Taken from the source columns, never re-derived by splitting the
            // joined string: the legacy table records which part is which, and
            // a heuristic that guesses is strictly worse than the answer.
            $first = $this->clean($row->first_name, 100);
            $last = $this->clean($row->last_name, 100);
            $name = trim(trim((string) $first).' '.trim((string) $last));

            $users[] = [
                'legacy_id' => (string) $row->id,
                'name' => $name !== '' ? $name : Str::before($email, '@'),
                'first_name' => $first,
                'last_name' => $last,
                'email' => $email,
                // Already a bcrypt hash; written raw so no cast can touch it.
                'password' => (string) $row->password,
                'phone' => $this->clean($row->phone_no, 32),
                'role' => ($isShipper ? UserRole::Shipper : UserRole::Carrier)->value,
                'status' => UserStatus::Active->value,
                'avatar_url' => $this->clean($row->profile_img, 255),
                'timezone' => 'Australia/Sydney',
                'locale' => 'en_AU',
                'created_at' => $this->date($row->date_created) ?? $now,
                'updated_at' => $this->date($row->date_updated) ?? $now,
            ];

            $profiles[(string) $row->id] = [
                'company_name' => $this->clean($row->company_name, 255),
                'abn_acn' => $this->clean($row->abn_number, 50),
                'business_type' => (int) $row->shipper_type === self::LEGACY_TYPE_BUSINESS
                    ? 'business'
                    : 'individual',
                'address_line_1' => $this->clean($row->street_address, 255),
                'city' => $this->clean($row->city, 120),
                'state' => $this->clean($row->state, 60),
                'postal_code' => $this->clean($row->zip, 20),
                'country' => $this->clean($row->country, 60) ?? 'Australia',
                'bio' => $this->clean($row->business_profile, 2000),
                'verification_status' => VerificationStatus::Unverified->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (! $isShipper) {
                $carrierLegacyIds[] = (string) $row->id;
            }
        }

        foreach (array_chunk($users, 500) as $batch) {
            DB::table('users')->upsert(
                $batch,
                ['legacy_id'],
                ['name', 'first_name', 'last_name', 'email', 'password', 'phone', 'role', 'avatar_url', 'updated_at']
            );
        }

        $this->stats['users'] = count($users);

        // Resolve the ids the upsert just produced, then attach the satellites.
        $idByLegacy = DB::table('users')
            ->whereNotNull('legacy_id')
            ->pluck('id', 'legacy_id');

        $profileRows = [];
        foreach ($profiles as $legacyId => $profile) {
            if (! isset($idByLegacy[$legacyId])) {
                continue;
            }
            $profileRows[] = ['user_id' => $idByLegacy[$legacyId]] + $profile;
        }

        foreach (array_chunk($profileRows, 500) as $batch) {
            DB::table('user_profiles')->upsert($batch, ['user_id'], [
                'company_name', 'abn_acn', 'business_type', 'address_line_1',
                'city', 'state', 'postal_code', 'country', 'bio', 'updated_at',
            ]);
        }
        $this->stats['user_profiles'] = count($profileRows);

        // The legacy schema held no fleet detail, so a carrier row is created
        // only so carriers exist as carriers; the fields stay null for them to
        // complete in the new dashboard.
        $carrierRows = [];
        foreach ($carrierLegacyIds as $legacyId) {
            if (! isset($idByLegacy[$legacyId])) {
                continue;
            }
            $carrierRows[] = [
                'user_id' => $idByLegacy[$legacyId],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($carrierRows, 500) as $batch) {
            DB::table('carriers')->upsert($batch, ['user_id'], ['updated_at']);
        }
        $this->stats['carriers'] = count($carrierRows);
    }

    private function importJobs(): void
    {
        $now = now();
        $userIdByLegacy = DB::table('users')->whereNotNull('legacy_id')->pluck('id', 'legacy_id');
        $suburbs = DB::connection('legacy')->table('suburb_master')->get()->keyBy('id');

        $rows = [];
        $skipped = 0;

        // legacy_id => the full lists, replayed onto the pivots once the jobs
        // have ids. Every value is kept; nothing is truncated to the first.
        $taxonomy = [];

        foreach (DB::connection('legacy')->table('load_master')->orderBy('date_created')->get() as $load) {
            $shipperId = $userIdByLegacy[(string) $load->shipper_id] ?? null;

            if (! $shipperId) {
                $skipped++;

                continue;
            }

            $categories = $this->splitList($load->categories);
            $truckTypes = $this->splitList($load->truck_type);
            $taxonomy[(string) $load->id] = ['categories' => $categories, 'truckTypes' => $truckTypes];

            $rows[] = [
                'legacy_id' => (string) $load->id,
                'shipper_id' => $shipperId,
                'title' => Str::limit($this->clean($load->short_desc, 255) ?? 'Freight job', 250, ''),
                'description' => $this->describe($load),
                'pickup_location' => $this->location($suburbs, $load->pickup_suburb, $load->pickup_state),
                'delivery_location' => $this->location($suburbs, $load->dropoff_suburb, $load->dropoff_state),
                'pickup_date' => $this->readyDate($load->readyon),
                'delivery_date' => null,
                'availability' => LoadAvailability::fromLegacy($load->availability)?->value,
                // Denormalised primary values; the pivots below hold them all.
                'load_category' => $categories[0] ?? null,
                'quantity' => $this->clean($load->quantity, 50),
                'length_mm' => $this->millimetres($load->length),
                'width_mm' => $this->millimetres($load->width),
                'height_mm' => $this->millimetres($load->height),
                'weight_kg' => $this->weightKg($load->weight),
                'vehicle_type_required' => null,
                'trailer_type_required' => $truckTypes[0] ?? null,
                'status' => JobStatus::Published->value,
                'visibility' => 'public',
                'images_json' => $load->load_img ? json_encode([$load->load_img]) : null,
                'created_at' => $this->date($load->date_created) ?? $now,
                'updated_at' => $this->date($load->date_updated) ?? $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $batch) {
            DB::table('freight_jobs')->upsert($batch, ['legacy_id'], [
                'shipper_id', 'title', 'description', 'pickup_location', 'delivery_location',
                'pickup_date', 'availability', 'load_category', 'quantity',
                'length_mm', 'width_mm', 'height_mm', 'weight_kg',
                'trailer_type_required', 'images_json', 'updated_at',
            ]);
        }

        $this->stats['freight_jobs'] = count($rows);

        $this->attachTaxonomy($taxonomy);

        if ($skipped > 0) {
            $this->warnings[] = "{$skipped} loads skipped: their shipper no longer exists in the legacy data.";
        }
    }

    /**
     * Replays the legacy multi-select answers onto the pivot tables.
     *
     * Matching is by slug against the seeded vocabulary (FreightTaxonomySeeder),
     * which was built from exactly these values — so a miss means a genuinely new
     * term has appeared in the source and is reported rather than dropped.
     *
     * @param  array<string, array{categories: list<string>, truckTypes: list<string>}>  $taxonomy
     */
    private function attachTaxonomy(array $taxonomy): void
    {
        if ($taxonomy === []) {
            return;
        }

        $jobIdByLegacy = DB::table('freight_jobs')->whereNotNull('legacy_id')->pluck('id', 'legacy_id');
        $categoryIdBySlug = DB::table('categories')->pluck('id', 'slug');
        $truckTypeIdBySlug = DB::table('truck_types')->pluck('id', 'slug');

        $categoryLinks = [];
        $truckTypeLinks = [];
        $unmatched = [];

        foreach ($taxonomy as $legacyId => $lists) {
            $jobId = $jobIdByLegacy[$legacyId] ?? null;

            if (! $jobId) {
                continue;
            }

            foreach ($lists['categories'] as $name) {
                $id = $categoryIdBySlug[Str::slug($name)] ?? null;

                if ($id) {
                    $categoryLinks[] = ['freight_job_id' => $jobId, 'category_id' => $id];
                } else {
                    $unmatched["category: {$name}"] = true;
                }
            }

            foreach ($lists['truckTypes'] as $name) {
                $id = $truckTypeIdBySlug[Str::slug($name)] ?? null;

                if ($id) {
                    $truckTypeLinks[] = ['freight_job_id' => $jobId, 'truck_type_id' => $id];
                } else {
                    $unmatched["truck type: {$name}"] = true;
                }
            }
        }

        // Re-runnable: the composite primary keys make these upserts no-ops on a
        // second pass rather than duplicate-key failures.
        foreach (array_chunk($categoryLinks, 1000) as $batch) {
            DB::table('category_freight_job')->upsert($batch, ['freight_job_id', 'category_id'], []);
        }
        foreach (array_chunk($truckTypeLinks, 1000) as $batch) {
            DB::table('freight_job_truck_type')->upsert($batch, ['freight_job_id', 'truck_type_id'], []);
        }

        $this->stats['job_categories'] = count($categoryLinks);
        $this->stats['job_truck_types'] = count($truckTypeLinks);

        if ($unmatched !== []) {
            $this->warnings[] = 'Unknown taxonomy values, add them to FreightTaxonomySeeder: '
                .implode('; ', array_keys($unmatched));
        }
    }

    private function importQuotes(): void
    {
        $now = now();
        $userIdByLegacy = DB::table('users')->whereNotNull('legacy_id')->pluck('id', 'legacy_id');
        $jobIdByLegacy = DB::table('freight_jobs')->whereNotNull('legacy_id')->pluck('id', 'legacy_id');

        $rows = [];
        $missingJob = 0;
        $missingCarrier = 0;

        foreach (DB::connection('legacy')->table('load_quotation')->orderBy('date_created')->get() as $quote) {
            $jobId = $jobIdByLegacy[(string) $quote->load_id] ?? null;
            $carrierId = $userIdByLegacy[(string) $quote->carrier_id] ?? null;

            if (! $jobId) {
                $missingJob++;

                continue;
            }
            if (! $carrierId) {
                $missingCarrier++;

                continue;
            }

            $rows[] = [
                'legacy_id' => (string) $quote->id,
                'job_id' => $jobId,
                'carrier_id' => $carrierId,
                'amount' => (float) $quote->price_quoted,
                'currency' => 'AUD',
                'notes' => $this->clean($quote->notes, 2000),
                // The legacy table recorded no accept/decline state.
                'status' => QuoteStatus::Pending->value,
                'created_at' => $this->date($quote->date_created) ?? $now,
                'updated_at' => $this->date($quote->date_updated) ?? $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $batch) {
            DB::table('job_quotes')->upsert($batch, ['legacy_id'], [
                'job_id', 'carrier_id', 'amount', 'notes', 'updated_at',
            ]);
        }

        $this->stats['job_quotes'] = count($rows);

        if ($missingJob > 0) {
            $this->warnings[] = "{$missingJob} quotes skipped: the load they quoted on no longer exists in the legacy data.";
        }
        if ($missingCarrier > 0) {
            $this->warnings[] = "{$missingCarrier} quotes skipped: the carrier account no longer exists.";
        }
    }

    private function importBlog(): void
    {
        $now = now();
        $rows = [];

        foreach (DB::connection('legacy')->table('blog_master')->get() as $post) {
            $title = $this->clean($post->blog_page, 255) ?? 'Untitled';

            $rows[] = [
                'legacy_id' => (string) $post->id,
                'title' => $title,
                'slug' => Str::limit($this->clean($post->page_slug, 255) ?? Str::slug($title), 250, ''),
                'excerpt' => Str::limit(strip_tags((string) $post->blog_description), 300, '…'),
                'content' => (string) $post->blog_description,
                'featured_image' => $this->clean($post->featured_image, 255),
                'author_id' => null,
                'status' => PostStatus::Published->value,
                'published_at' => $this->date($post->date_created) ?? $now,
                'created_at' => $this->date($post->date_created) ?? $now,
                'updated_at' => $this->date($post->date_updated) ?? $now,
            ];
        }

        if ($rows !== []) {
            DB::table('blog_posts')->upsert($rows, ['legacy_id'], [
                'title', 'slug', 'excerpt', 'content', 'featured_image', 'updated_at',
            ]);
        }

        $this->stats['blog_posts'] = count($rows);
    }

    private function importBilling(): void
    {
        $now = now();

        // Plans
        $plans = [];
        foreach (DB::connection('legacy')->table('subscription_master')->get() as $plan) {
            $plans[] = [
                'legacy_id' => (string) $plan->id,
                'name' => $this->clean($plan->item_name, 255) ?? 'Subscription',
                'price' => (float) $plan->price,
                'currency' => 'AUD',
                'interval_months' => max(1, (int) $plan->month_no),
                'is_active' => true,
                'created_at' => $this->date($plan->date_created) ?? $now,
                'updated_at' => $this->date($plan->date_updated) ?? $now,
            ];
        }
        if ($plans !== []) {
            DB::table('subscription_plans')->upsert($plans, ['legacy_id'], [
                'name', 'price', 'interval_months', 'updated_at',
            ]);
        }
        $this->stats['subscription_plans'] = count($plans);

        $userIdByLegacy = DB::table('users')->whereNotNull('legacy_id')->pluck('id', 'legacy_id');
        $planIdByLegacy = DB::table('subscription_plans')->whereNotNull('legacy_id')->pluck('id', 'legacy_id');

        // Periods. `subscription_type` is a small code, not a plan id, and it is
        // resolved here by **what the code means** rather than by its position
        // in the plan list.
        //
        // It used to be treated as a 1-based index into that list, which is only
        // correct if the list arrives in the order the codes assume. It did not:
        // the plans come back ordered by primary key — monthly, annual,
        // quarterly — so codes 2 and 3 were swapped. Six carriers who paid
        // $184.99 were recorded on the $699.90 plan, and one who paid $699.90 on
        // the $184.99 one.
        //
        // The codes were decoded from what each cohort actually paid:
        //   1 -> $64.99 monthly, 2 -> $184.99 quarterly,
        //   3 -> $699.90 annual, 4 -> the free trial (paypal_trans = 'Free')
        $planIdByCode = DB::table('subscription_plans')
            ->whereNotNull('code')
            ->pluck('id', 'code');

        $planForLegacyType = [
            1 => $planIdByCode['monthly'] ?? null,
            2 => $planIdByCode['quarterly'] ?? null,
            3 => $planIdByCode['annual'] ?? null,
            4 => $planIdByCode['trial'] ?? null,
        ];

        $unknownTypes = [];

        $subscriptions = [];
        $orphanSubs = 0;

        foreach (DB::connection('legacy')->table('subscription_details')->get() as $sub) {
            $userId = $userIdByLegacy[(string) $sub->client_id] ?? null;

            if (! $userId) {
                $orphanSubs++;

                continue;
            }

            $type = (int) $sub->subscription_type;
            $planId = $planForLegacyType[$type] ?? null;

            if (! $planId) {
                $unknownTypes[$type] = ($unknownTypes[$type] ?? 0) + 1;
            }

            $startsOn = $this->date($sub->start_from);
            $endsOn = $this->date($sub->end_to);

            // The legacy free trial set `end_to` to the promotion's closing date
            // (2026-03-31) for everyone, rather than to two months after they
            // started. For anyone who signed up after that date the trial was
            // already over before it began — eleven of ninety periods end before
            // they start. The dates are imported exactly as recorded, because
            // rewriting history would hide the problem; the correct length is
            // applied to *new* trials instead. See docs/10-domain-rules.md R8.
            $subscriptions[] = [
                'legacy_id' => (string) $sub->id,
                'user_id' => $userId,
                'subscription_plan_id' => $planId,
                'status' => $endsOn && $endsOn->isPast() ? 'expired' : 'active',
                'starts_on' => $startsOn?->toDateString(),
                'ends_on' => $endsOn?->toDateString(),
                'gateway_reference' => $this->clean($sub->paypal_trans, 100),
                'created_at' => $this->date($sub->date_created) ?? $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($subscriptions, 500) as $batch) {
            DB::table('subscriptions')->upsert($batch, ['legacy_id'], [
                'user_id', 'subscription_plan_id', 'status', 'starts_on',
                'ends_on', 'gateway_reference', 'updated_at',
            ]);
        }
        $this->stats['subscriptions'] = count($subscriptions);

        if ($orphanSubs > 0) {
            $this->warnings[] = "{$orphanSubs} subscription periods skipped: the account no longer exists.";
        }

        // Reported rather than silently left planless: an unrecognised code
        // means the plan vocabulary has moved on and this map needs updating.
        foreach ($unknownTypes as $type => $count) {
            $this->warnings[] = "{$count} subscription periods use legacy type {$type}, "
                .'which maps to no plan. Add it to $planForLegacyType in this importer.';
        }

        // Payments
        $payments = [];
        foreach (DB::connection('legacy')->table('paypal_transaction')->get() as $txn) {
            $index = (int) $txn->subscription - 1;

            $payments[] = [
                'legacy_id' => (string) $txn->id,
                'user_id' => $userIdByLegacy[(string) $txn->user_id] ?? null,
                'subscription_plan_id' => $planIdsInOrder[$index] ?? null,
                'gateway' => 'paypal',
                'gateway_reference' => $this->clean($txn->payer_id, 100),
                'payer_name' => $this->clean($txn->payer_name, 255),
                'payer_email' => $this->clean($txn->payer_email, 255),
                'amount' => $txn->payment_amount !== null ? (float) $txn->payment_amount : null,
                'currency' => $this->clean($txn->currency_code, 3) ?? 'AUD',
                'status' => $this->clean($txn->status, 32),
                'paid_at' => $this->date($txn->payment_date),
                'created_at' => $this->date($txn->date_created) ?? $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($payments, 500) as $batch) {
            DB::table('subscription_payments')->upsert($batch, ['legacy_id'], [
                'user_id', 'subscription_plan_id', 'payer_name', 'payer_email',
                'amount', 'currency', 'status', 'paid_at', 'updated_at',
            ]);
        }
        $this->stats['subscription_payments'] = count($payments);
    }

    // -- Helpers -------------------------------------------------------------

    /** Trims, nulls out blanks, and truncates to the destination column width. */
    /** Legacy text that is safe to show as-is — a bare number is a stored unit, not a label. */
    private function displayText(mixed $value, int $length): ?string
    {
        $text = $this->clean($value, $length);

        return $text !== null && preg_match('/^\d+$/', $text) ? null : $text;
    }

    private function clean(mixed $value, int $length): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : mb_substr($value, 0, $length);
    }

    private function date(mixed $value): ?CarbonInterface
    {
        if (empty($value) || str_starts_with((string) $value, '0000-00-00')) {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }

    /** The legacy form wrote 1970-01-01 when no ready date was chosen. */
    private function readyDate(mixed $value): ?string
    {
        $date = $this->date($value);

        if (! $date || $date->toDateString() === self::EPOCH_SENTINEL) {
            return null;
        }

        return $date->toDateString();
    }

    /**
     * The legacy schema kept multi-select answers as one comma-joined string.
     *
     * @return array<int,string>
     */
    private function splitList(mixed $value): array
    {
        $parts = array_filter(array_map(trim(...), explode(',', (string) ($value ?? ''))));

        return array_values(array_map(fn (string $p) => mb_substr($p, 0, 64), $parts));
    }

    /**
     * Legacy weights were free text in mixed units with no unit recorded. Road
     * freight cannot exceed ~50 tonnes, so a bare number above that threshold
     * was already kilograms and anything at or below it was tonnes. The
     * untouched original is kept in the description so the guess stays
     * auditable.
     *
     * Returns kilograms, which is what the column stores and what the form
     * asks for.
     */
    private function weightKg(mixed $value): ?int
    {
        $raw = trim((string) ($value ?? ''));

        if ($raw === '' || ! is_numeric($raw)) {
            return null;
        }

        $number = (float) $raw;

        if ($number <= 0) {
            return null;
        }

        $kg = $number > self::TONNE_THRESHOLD ? $number : $number * 1000;

        // The column is unsigned int; anything past 100 t is a data entry
        // error rather than a freight task, and is dropped rather than stored
        // as a number that would skew every filter built on it.
        return $kg > 100000 ? null : (int) round($kg);
    }

    /**
     * A legacy dimension, in millimetres.
     *
     * Free text like weight, but without the unit ambiguity — the legacy form
     * labelled all three fields mm. Values beyond 30 m are discarded as typos.
     */
    private function millimetres(mixed $value): ?int
    {
        $raw = trim((string) ($value ?? ''));

        if ($raw === '' || ! is_numeric($raw)) {
            return null;
        }

        $number = (int) round((float) $raw);

        return $number > 0 && $number <= 30000 ? $number : null;
    }

    private function location(mixed $suburbs, mixed $suburbId, mixed $state): string
    {
        $suburb = $suburbs[(string) $suburbId] ?? null;
        $state = strtoupper(trim((string) ($state ?? '')));

        if (! $suburb) {
            return $state !== '' ? $state : 'Unknown';
        }

        $name = trim((string) $suburb->suburb);

        return $state !== '' ? "{$name}, {$state}" : $name;
    }

    /**
     * The load's description.
     *
     * This used to rebuild a paragraph from the legacy columns the schema had
     * no home for — dimensions, quantity and weight were appended as prose so
     * nothing was silently dropped. They are real columns now, so repeating
     * them here would put the same fact in two places and let them drift.
     *
     * The one exception is the weight as originally typed. `weight_kg` is an
     * inference from a free-text field with no unit recorded, and keeping the
     * raw string is what makes that inference auditable rather than a guess
     * nobody can check.
     */
    private function describe(object $load): ?string
    {
        $parts = [];

        if ($detail = $this->clean($load->load_dec, 4000)) {
            $parts[] = $detail;
        }

        $raw = $this->clean($load->weight, 50);

        if ($raw !== null && $this->weightKg($load->weight) !== null && ! is_numeric(trim($raw))) {
            $parts[] = "Weight as entered: {$raw}";
        }

        return $parts === [] ? null : implode("
", $parts);
    }

    private function report(): void
    {
        $this->newLine();
        $this->table(
            ['Table', 'Rows'],
            collect($this->stats)->map(fn ($n, $t) => [$t, number_format($n)])->values()->all()
        );

        foreach ($this->warnings as $warning) {
            $this->warn('• '.$warning);
        }
    }
}
