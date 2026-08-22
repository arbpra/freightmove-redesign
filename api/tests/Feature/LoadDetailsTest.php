<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\FreightJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The load detail fields the legacy form collected.
 *
 * `load_master` held quantity, length, width, height, weight and an image, and
 * the post-a-load form asked for all of them. The first cut of this schema had
 * nowhere to put them, so `legacy:import` folded them into the description as
 * prose — readable, and impossible to filter on.
 *
 * These are columns again, weight is stored in the kilograms shippers actually
 * type, and the contact block edits the account rather than being copied onto
 * every load.
 */
class LoadDetailsTest extends TestCase
{
    use RefreshDatabase;

    private function shipper(array $attributes = []): User
    {
        return User::factory()->create([
            'role' => UserRole::Shipper,
            'status' => UserStatus::Active,
            ...$attributes,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Excavator, Brisbane to Perth',
            'pickup_location' => 'Brisbane, QLD',
            'delivery_location' => 'Perth, WA',
        ], $overrides);
    }

    // -- Dimensions ----------------------------------------------------------

    public function test_a_load_carries_quantity_dimensions_and_weight(): void
    {
        $shipper = $this->shipper();

        $this->actingAs($shipper)
            ->postJson('/api/v1/shipper/jobs', $this->payload([
                'quantity' => '2 pallets',
                'length_mm' => 2400,
                'width_mm' => 1200,
                'height_mm' => 1150,
                'weight_kg' => 1850,
            ]))
            ->assertCreated()
            ->assertJsonPath('data.quantity', '2 pallets')
            ->assertJsonPath('data.length_mm', 2400)
            ->assertJsonPath('data.weight_kg', 1850);

        $this->assertDatabaseHas('freight_jobs', [
            'shipper_id' => $shipper->id,
            'quantity' => '2 pallets',
            'length_mm' => 2400,
            'width_mm' => 1200,
            'height_mm' => 1150,
            'weight_kg' => 1850,
        ]);
    }

    /**
     * Quantity stays free text because the legacy column was. Requiring an
     * integer would reject values already in the data.
     */
    public function test_quantity_accepts_the_way_people_actually_write_it(): void
    {
        foreach (['3', '2 pallets', '1 x crate'] as $quantity) {
            $this->actingAs($this->shipper())
                ->postJson('/api/v1/shipper/jobs', $this->payload(['quantity' => $quantity]))
                ->assertCreated()
                ->assertJsonPath('data.quantity', $quantity);
        }
    }

    public function test_dimensions_beyond_a_road_combination_are_rejected(): void
    {
        // 40 m. Anything this size is a typo or the wrong unit entirely.
        $this->actingAs($this->shipper())
            ->postJson('/api/v1/shipper/jobs', $this->payload(['length_mm' => 40000]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('length_mm');
    }

    public function test_the_dimensions_label_reads_as_one_measurement(): void
    {
        $job = FreightJob::factory()->create([
            'length_mm' => 12000, 'width_mm' => 2400, 'height_mm' => null,
        ]);

        // Only the dimensions given, never a null padded out with a zero.
        $this->assertSame('12,000 × 2,400 mm', $job->dimensionsLabel());

        $bare = FreightJob::factory()->create([
            'length_mm' => null, 'width_mm' => null, 'height_mm' => null,
        ]);
        $this->assertNull($bare->dimensionsLabel());
    }

    // -- Weight --------------------------------------------------------------

    /**
     * Kilograms are stored; tonnes are derived. The board reads tonnes, so both
     * appear in the payload, but only one of them is a fact.
     */
    public function test_tonnes_are_derived_from_the_stored_kilograms(): void
    {
        $job = FreightJob::factory()->create(['weight_kg' => 24500]);

        $this->assertSame(24.5, $job->weightTons());

        $this->actingAs(User::find($job->shipper_id))
            ->getJson("/api/v1/shipper/jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('data.weight_kg', 24500)
            ->assertJsonPath('data.weight_tons', 24.5);
    }

    /** 750 kg is a real load; as tonnes it was 0.75 and rounded badly. */
    public function test_a_small_weight_survives_the_round_trip(): void
    {
        $shipper = $this->shipper();

        $this->actingAs($shipper)
            ->postJson('/api/v1/shipper/jobs', $this->payload(['weight_kg' => 750]))
            ->assertCreated()
            ->assertJsonPath('data.weight_kg', 750);

        $this->assertSame(750, FreightJob::sole()->weight_kg);
    }

    public function test_an_absurd_weight_is_rejected(): void
    {
        $this->actingAs($this->shipper())
            ->postJson('/api/v1/shipper/jobs', $this->payload(['weight_kg' => 250000]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('weight_kg');
    }

    // -- Contact -------------------------------------------------------------

    /**
     * The legacy load table had no contact columns, and neither does this one.
     * The form edits the account, so one shipper has one set of details rather
     * than a stale copy on every load they ever posted.
     */
    public function test_the_contact_block_updates_the_account(): void
    {
        $shipper = $this->shipper(['name' => 'Jordan Blake', 'phone' => '0400 000 000']);

        $this->actingAs($shipper)
            ->postJson('/api/v1/shipper/jobs', $this->payload([
                'contact' => [
                    'first_name' => 'Jordan',
                    'last_name' => 'Blake-Smith',
                    'phone' => '0412 345 678',
                ],
            ]))
            ->assertCreated();

        $shipper->refresh();
        $this->assertSame('Jordan', $shipper->first_name);
        $this->assertSame('Blake-Smith', $shipper->last_name);
        // The display name is rebuilt from the parts, never left to disagree.
        $this->assertSame('Jordan Blake-Smith', $shipper->name);
        $this->assertSame('0412 345 678', $shipper->phone);
    }

    public function test_contact_details_are_not_stored_on_the_load(): void
    {
        $this->actingAs($this->shipper())
            ->postJson('/api/v1/shipper/jobs', $this->payload([
                'contact' => ['first_name' => 'Jordan', 'phone' => '0412 345 678'],
            ]))
            ->assertCreated();

        $columns = array_keys(FreightJob::sole()->getAttributes());
        foreach (['first_name', 'last_name', 'email', 'phone', 'contact'] as $leaked) {
            $this->assertNotContains($leaked, $columns);
        }
    }

    /** A form that omits the block must not blank an account. */
    public function test_omitting_contact_leaves_the_account_alone(): void
    {
        $shipper = $this->shipper(['name' => 'Jordan Blake', 'phone' => '0400 000 000']);

        $this->actingAs($shipper)
            ->postJson('/api/v1/shipper/jobs', $this->payload())
            ->assertCreated();

        $shipper->refresh();
        $this->assertSame('Jordan Blake', $shipper->name);
        $this->assertSame('0400 000 000', $shipper->phone);
    }

    /** Email is the login identifier, so it cannot collide with another account. */
    public function test_the_contact_email_cannot_take_another_account(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($this->shipper())
            ->postJson('/api/v1/shipper/jobs', $this->payload([
                'contact' => ['email' => 'taken@example.com'],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('contact.email');
    }

    public function test_keeping_your_own_email_is_not_a_collision(): void
    {
        $shipper = $this->shipper(['email' => 'mine@example.com']);

        $this->actingAs($shipper)
            ->postJson('/api/v1/shipper/jobs', $this->payload([
                'contact' => ['email' => 'mine@example.com'],
            ]))
            ->assertCreated();
    }

    // -- Name splitting ------------------------------------------------------

    public function test_titles_and_post_nominals_are_not_names(): void
    {
        $this->assertSame(['Camron', 'Kling'], User::splitName('Mr. Camron Kling III'));
        $this->assertSame(['Lola', 'Hansen'], User::splitName('Prof. Lola Hansen DVM'));
        $this->assertSame(['Jordan', 'Blake'], User::splitName('Jordan Blake'));
        // A surname nobody recorded is null, not the given name repeated.
        $this->assertSame(['Cher', null], User::splitName('Cher'));
        // Never strip away the only word there is.
        $this->assertSame(['Dr', null], User::splitName('Dr'));
    }

    // -- Photos --------------------------------------------------------------

    public function test_a_shipper_can_attach_a_photo(): void
    {
        Storage::fake('public');
        $shipper = $this->shipper();
        $job = FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'status' => JobStatus::Draft,
        ]);

        $this->actingAs($shipper)
            ->postJson("/api/v1/shipper/jobs/{$job->id}/images", [
                'file' => UploadedFile::fake()->image('excavator.jpg'),
            ])
            ->assertCreated()
            ->assertJsonCount(1, 'data.images');

        $stored = $job->fresh()->images_json;
        $this->assertCount(1, $stored);
        Storage::disk('public')->assertExists($stored[0]);
        // A hashed name in a per-load folder: the original filename is data,
        // never a path.
        $this->assertStringStartsWith("loads/{$job->id}/", $stored[0]);
        $this->assertStringNotContainsString('excavator', $stored[0]);
    }

    /**
     * These are rendered inline on a public board, so an SVG here would be
     * script running on our origin in someone else's browser.
     */
    public function test_svg_is_refused(): void
    {
        Storage::fake('public');
        $shipper = $this->shipper();
        $job = FreightJob::factory()->create(['shipper_id' => $shipper->id]);

        $svg = UploadedFile::fake()->createWithContent(
            'logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        );

        $this->actingAs($shipper)
            ->postJson("/api/v1/shipper/jobs/{$job->id}/images", ['file' => $svg])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSame([], $job->fresh()->images_json ?? []);
    }

    public function test_one_shipper_cannot_attach_a_photo_to_anothers_load(): void
    {
        Storage::fake('public');
        $job = FreightJob::factory()->create(['shipper_id' => $this->shipper()->id]);

        $this->actingAs($this->shipper())
            ->postJson("/api/v1/shipper/jobs/{$job->id}/images", [
                'file' => UploadedFile::fake()->image('nice-try.jpg'),
            ])
            ->assertForbidden();
    }

    public function test_the_photo_limit_is_enforced(): void
    {
        Storage::fake('public');
        config(['freightmove.loads.max_images' => 2]);

        $shipper = $this->shipper();
        $job = FreightJob::factory()->create(['shipper_id' => $shipper->id]);

        foreach (['one.jpg', 'two.jpg'] as $name) {
            $this->actingAs($shipper)
                ->postJson("/api/v1/shipper/jobs/{$job->id}/images", [
                    'file' => UploadedFile::fake()->image($name),
                ])
                ->assertCreated();
        }

        $this->actingAs($shipper)
            ->postJson("/api/v1/shipper/jobs/{$job->id}/images", [
                'file' => UploadedFile::fake()->image('three.jpg'),
            ])
            ->assertStatus(422);
    }

    public function test_a_photo_can_be_removed(): void
    {
        Storage::fake('public');
        $shipper = $this->shipper();
        $job = FreightJob::factory()->create(['shipper_id' => $shipper->id]);

        $this->actingAs($shipper)
            ->postJson("/api/v1/shipper/jobs/{$job->id}/images", [
                'file' => UploadedFile::fake()->image('gone.jpg'),
            ])
            ->assertCreated();

        $path = $job->fresh()->images_json[0];

        $this->actingAs($shipper)
            ->deleteJson("/api/v1/shipper/jobs/{$job->id}/images", ['path' => $path])
            ->assertOk();

        Storage::disk('public')->assertMissing($path);
        $this->assertSame([], $job->fresh()->images_json);
    }

    /** A path from another load must not be deletable through this one. */
    public function test_a_photo_on_another_load_cannot_be_named(): void
    {
        Storage::fake('public');
        $shipper = $this->shipper();
        $mine = FreightJob::factory()->create(['shipper_id' => $shipper->id]);
        $theirs = FreightJob::factory()->create([
            'shipper_id' => $shipper->id,
            'images_json' => ['loads/999/secret.jpg'],
        ]);

        $this->actingAs($shipper)
            ->deleteJson("/api/v1/shipper/jobs/{$mine->id}/images", [
                'path' => 'loads/999/secret.jpg',
            ])
            ->assertNotFound();

        $this->assertSame(['loads/999/secret.jpg'], $theirs->fresh()->images_json);
    }
}
