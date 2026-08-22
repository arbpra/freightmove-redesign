<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\QuoteStatus;
use App\Models\FreightJob;
use App\Models\JobQuote;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the schema described in docs/05-database-schema.md: the tables build,
 * the casts round-trip, and the uniqueness rules actually hold in the database
 * rather than only in application code.
 */
class SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_documented_table_exists(): void
    {
        $tables = [
            'users', 'user_profiles', 'carriers', 'vehicle_types', 'freight_jobs',
            'job_quotes', 'job_acceptances', 'job_tracking', 'reviews', 'conversations',
            'messages', 'notifications', 'verification_documents', 'payments',
            'blog_posts', 'support_tickets',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(
                \Illuminate\Support\Facades\Schema::hasTable($table),
                "Missing table [{$table}] from docs/05-database-schema.md."
            );
        }
    }

    public function test_job_casts_round_trip_through_the_database(): void
    {
        $job = FreightJob::factory()->create([
            'status' => JobStatus::Quoted,
            'weight_kg' => 12500,
            'images_json' => ['loads/one.jpg', 'loads/two.jpg'],
        ]);

        $fresh = $job->fresh();

        $this->assertSame(JobStatus::Quoted, $fresh->status);
        // Kilograms are stored as an integer; tonnes are derived for display.
        $this->assertSame(12500, $fresh->weight_kg);
        $this->assertSame(12.5, $fresh->weightTons());
        $this->assertSame(['loads/one.jpg', 'loads/two.jpg'], $fresh->images_json);
        $this->assertTrue($fresh->pickup_date->isFuture());
    }

    public function test_a_carrier_cannot_quote_the_same_job_twice(): void
    {
        $job = FreightJob::factory()->create();
        $carrier = User::factory()->carrier()->create();

        JobQuote::factory()->create(['job_id' => $job->id, 'carrier_id' => $carrier->id]);

        $this->expectException(QueryException::class);

        JobQuote::factory()->create(['job_id' => $job->id, 'carrier_id' => $carrier->id]);
    }

    public function test_published_scope_only_returns_jobs_open_for_quotes(): void
    {
        FreightJob::factory()->create(['status' => JobStatus::Draft]);
        FreightJob::factory()->create(['status' => JobStatus::Completed]);
        FreightJob::factory()->create(['status' => JobStatus::Published]);
        FreightJob::factory()->create(['status' => JobStatus::Quoted]);
        FreightJob::factory()->create(['status' => JobStatus::Published, 'visibility' => 'private']);

        $this->assertSame(2, FreightJob::published()->count());
    }

    public function test_deleting_a_job_cascades_to_its_quotes(): void
    {
        $job = FreightJob::factory()->create();
        JobQuote::factory()->count(3)->create(['job_id' => $job->id]);

        $this->assertSame(3, JobQuote::where('job_id', $job->id)->count());

        // forceDelete: the soft delete on freight_jobs deliberately keeps quotes.
        $job->forceDelete();

        $this->assertSame(0, JobQuote::withTrashed()->where('job_id', $job->id)->count());
    }

    public function test_quote_status_defaults_to_pending(): void
    {
        $quote = JobQuote::factory()->create();

        $this->assertSame(QuoteStatus::Pending, $quote->fresh()->status);
    }
}
