<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\VerificationStatus;
use App\Models\Carrier;
use App\Models\FreightJob;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\VerificationDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Verification: uploading documents, admin review, and what it gates.
 *
 * This is the application's first upload endpoint, so the file-handling rules
 * from docs/11-security.md are pinned here rather than left to inspection.
 */
class CarrierVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

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

    private function admin(): User
    {
        return User::factory()->create([
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
        ]);
    }

    // -- Uploading -----------------------------------------------------------

    public function test_a_carrier_uploads_a_document(): void
    {
        $carrier = $this->carrier();

        $this->actingAs($carrier)
            ->postJson('/api/v1/carrier/documents', [
                'document_type' => 'abn',
                'file' => UploadedFile::fake()->create('abn-extract.pdf', 200, 'application/pdf'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.document_type', 'abn')
            ->assertJsonPath('data.original_name', 'abn-extract.pdf')
            ->assertJsonPath('data.status', 'pending');

        $document = VerificationDocument::sole();
        Storage::disk('local')->assertExists($document->file_path);
    }

    /**
     * The stored name must not be the uploaded one: a filename is
     * attacker-controlled text, and it has no business deciding where a byte
     * lands or what a later reader thinks a file is.
     */
    public function test_the_stored_filename_is_randomised(): void
    {
        $carrier = $this->carrier();

        $this->actingAs($carrier)
            ->postJson('/api/v1/carrier/documents', [
                'document_type' => 'abn',
                'file' => UploadedFile::fake()->create('../../evil.pdf', 10, 'application/pdf'),
            ])
            ->assertCreated();

        $path = VerificationDocument::sole()->file_path;

        $this->assertStringNotContainsString('evil', $path);
        $this->assertStringNotContainsString('..', $path);
        $this->assertStringStartsWith('verification/', $path);
    }

    public function test_the_stored_path_is_never_serialised(): void
    {
        $carrier = $this->carrier();

        $response = $this->actingAs($carrier)
            ->postJson('/api/v1/carrier/documents', [
                'document_type' => 'abn',
                'file' => UploadedFile::fake()->create('abn.pdf', 10, 'application/pdf'),
            ])->assertCreated();

        $this->assertStringNotContainsString('file_path', $response->getContent());
    }

    /**
     * The whole point of the `mimetypes` rule: right extension, right declared
     * type, wrong bytes.
     *
     * This uses a **real** UploadedFile rather than `UploadedFile::fake()`.
     * Laravel's fake overrides `getMimeType()` to derive the type from the
     * filename, so a faked upload cannot exercise content detection at all —
     * it would report `application/pdf` for any file called `.pdf` and the test
     * would pass while proving nothing.
     */
    public function test_an_executable_disguised_as_a_pdf_is_refused(): void
    {
        $carrier = $this->carrier();

        $path = tempnam(sys_get_temp_dir(), 'fm-test-').'.pdf';
        file_put_contents($path, "MZ\x90\x00 this is a DOS executable, not a PDF");

        $upload = new UploadedFile($path, 'payload.pdf', 'application/pdf', null, true);

        try {
            $this->actingAs($carrier)
                ->postJson('/api/v1/carrier/documents', [
                    'document_type' => 'abn',
                    'file' => $upload,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('file');

            $this->assertSame(0, VerificationDocument::count());
        } finally {
            @unlink($path);
        }
    }

    /** The same mechanism the other way round: real PDF bytes are accepted. */
    public function test_a_genuine_pdf_passes_the_content_check(): void
    {
        $carrier = $this->carrier();

        $path = tempnam(sys_get_temp_dir(), 'fm-test-').'.pdf';
        file_put_contents($path, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");

        $upload = new UploadedFile($path, 'abn-extract.pdf', 'application/pdf', null, true);

        try {
            $this->actingAs($carrier)
                ->postJson('/api/v1/carrier/documents', [
                    'document_type' => 'abn',
                    'file' => $upload,
                ])
                ->assertCreated();

            $this->assertSame('application/pdf', VerificationDocument::sole()->mime_type);
        } finally {
            @unlink($path);
        }
    }

    public function test_an_oversized_file_is_refused(): void
    {
        config(['freightmove.verification.max_upload_kb' => 100]);

        $this->actingAs($this->carrier())
            ->postJson('/api/v1/carrier/documents', [
                'document_type' => 'abn',
                'file' => UploadedFile::fake()->create('big.pdf', 500, 'application/pdf'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_an_unknown_document_type_is_refused(): void
    {
        $this->actingAs($this->carrier())
            ->postJson('/api/v1/carrier/documents', [
                'document_type' => 'passport-and-bank-details',
                'file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('document_type');
    }

    public function test_an_already_expired_document_is_refused(): void
    {
        $this->actingAs($this->carrier())
            ->postJson('/api/v1/carrier/documents', [
                'document_type' => 'insurance',
                'file' => UploadedFile::fake()->create('cert.pdf', 10, 'application/pdf'),
                'expires_at' => now()->subDay()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('expires_at');
    }

    public function test_uploading_moves_the_profile_into_pending(): void
    {
        $carrier = $this->carrier(['verification_status' => VerificationStatus::Unverified]);

        $this->actingAs($carrier)
            ->postJson('/api/v1/carrier/documents', [
                'document_type' => 'abn',
                'file' => UploadedFile::fake()->create('abn.pdf', 10, 'application/pdf'),
            ])->assertCreated();

        $profile = $carrier->fresh()->profile;
        $this->assertSame(VerificationStatus::Pending, $profile->verification_status);
        $this->assertNotNull($profile->verification_submitted_at);
    }

    // -- Reading back --------------------------------------------------------

    public function test_a_carrier_downloads_their_own_document(): void
    {
        $carrier = $this->carrier();

        $this->actingAs($carrier)->postJson('/api/v1/carrier/documents', [
            'document_type' => 'abn',
            'file' => UploadedFile::fake()->create('abn.pdf', 10, 'application/pdf'),
        ])->assertCreated();

        $document = VerificationDocument::sole();

        $response = $this->actingAs($carrier)
            ->get("/api/v1/carrier/documents/{$document->id}/download")
            ->assertOk();

        // Never inline: a file rendered under our own origin would be stored
        // XSS regardless of what the MIME check allowed.
        $this->assertStringContainsString(
            'attachment',
            $response->headers->get('content-disposition'),
        );
    }

    public function test_one_carrier_cannot_download_anothers_document(): void
    {
        $owner = $this->carrier();
        $stranger = $this->carrier();

        $document = VerificationDocument::factory()->create([
            'user_id' => $owner->id,
            'status' => DocumentStatus::Pending,
        ]);

        $this->actingAs($stranger)
            ->get("/api/v1/carrier/documents/{$document->id}/download")
            ->assertForbidden();
    }

    // -- Withdrawing ---------------------------------------------------------

    public function test_a_pending_document_can_be_withdrawn(): void
    {
        $carrier = $this->carrier();

        $this->actingAs($carrier)->postJson('/api/v1/carrier/documents', [
            'document_type' => 'abn',
            'file' => UploadedFile::fake()->create('abn.pdf', 10, 'application/pdf'),
        ])->assertCreated();

        $document = VerificationDocument::sole();
        $path = $document->file_path;

        $this->actingAs($carrier)
            ->deleteJson("/api/v1/carrier/documents/{$document->id}")
            ->assertOk();

        $this->assertSame(0, VerificationDocument::count());
        Storage::disk('local')->assertMissing($path);
    }

    /**
     * A rejected document is the record of why a decision went the way it did.
     * Deleting it would let a carrier erase the history and resubmit as though
     * the rejection never happened.
     */
    public function test_a_reviewed_document_cannot_be_deleted(): void
    {
        $carrier = $this->carrier();

        foreach ([DocumentStatus::Approved, DocumentStatus::Rejected] as $status) {
            $document = VerificationDocument::factory()->create([
                'user_id' => $carrier->id,
                'status' => $status,
            ]);

            $this->actingAs($carrier)
                ->deleteJson("/api/v1/carrier/documents/{$document->id}")
                ->assertForbidden();
        }
    }

    // -- Admin review --------------------------------------------------------

    public function test_the_admin_queue_lists_pending_documents_oldest_first(): void
    {
        $carrier = $this->carrier();

        $newer = VerificationDocument::factory()->create([
            'user_id' => $carrier->id,
            'status' => DocumentStatus::Pending,
            'created_at' => now()->subDay(),
        ]);
        $older = VerificationDocument::factory()->create([
            'user_id' => $carrier->id,
            'status' => DocumentStatus::Pending,
            'created_at' => now()->subWeek(),
        ]);

        $items = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/verifications')
            ->assertOk()
            ->json('data.items');

        $this->assertSame([$older->id, $newer->id], array_column($items, 'id'));
    }

    public function test_approving_every_required_document_verifies_the_carrier(): void
    {
        $carrier = $this->carrier(['verification_status' => VerificationStatus::Pending]);
        $admin = $this->admin();

        $abn = VerificationDocument::factory()->create([
            'user_id' => $carrier->id,
            'document_type' => 'abn',
            'status' => DocumentStatus::Pending,
        ]);
        $insurance = VerificationDocument::factory()->create([
            'user_id' => $carrier->id,
            'document_type' => 'insurance',
            'status' => DocumentStatus::Pending,
        ]);

        // One required document is not enough on its own.
        $this->actingAs($admin)
            ->postJson("/api/v1/admin/documents/{$abn->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.carrier_verification_status', 'pending')
            ->assertJsonPath('data.still_missing', ['insurance']);

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/documents/{$insurance->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.carrier_verification_status', 'verified')
            ->assertJsonPath('data.still_missing', []);

        $this->assertNotNull($carrier->fresh()->profile->verified_at);
    }

    public function test_an_expired_document_does_not_count_towards_verification(): void
    {
        $carrier = $this->carrier();

        VerificationDocument::factory()->create([
            'user_id' => $carrier->id,
            'document_type' => 'abn',
            'status' => DocumentStatus::Approved,
        ]);
        // Approved once, but the certificate has since lapsed.
        VerificationDocument::factory()->create([
            'user_id' => $carrier->id,
            'document_type' => 'insurance',
            'status' => DocumentStatus::Approved,
            'expires_at' => now()->subMonth(),
        ]);

        $status = app(\App\Services\VerificationService::class)->refresh($carrier);

        $this->assertNotSame(VerificationStatus::Verified, $status);
        $this->assertSame(['insurance'], app(\App\Services\VerificationService::class)->missingTypes($carrier));
    }

    /**
     * An admin may verify a carrier on evidence that never passed through this
     * table — a call to their insurer, a licence checked in person. Recomputing
     * must not quietly overturn that.
     */
    public function test_recomputing_does_not_strip_a_manually_granted_badge(): void
    {
        $carrier = $this->carrier([
            'verification_status' => VerificationStatus::Verified,
            'verified_at' => now(),
        ]);

        // No documents at all, so nothing derived says "verified".
        $status = app(\App\Services\VerificationService::class)->refresh($carrier);

        $this->assertSame(VerificationStatus::Verified, $status);
    }

    public function test_a_rejection_does_withdraw_verification(): void
    {
        $carrier = $this->carrier([
            'verification_status' => VerificationStatus::Verified,
            'verified_at' => now(),
        ]);

        VerificationDocument::factory()->create([
            'user_id' => $carrier->id,
            'document_type' => 'insurance',
            'status' => DocumentStatus::Rejected,
        ]);

        $status = app(\App\Services\VerificationService::class)->refresh($carrier);

        $this->assertNotSame(VerificationStatus::Verified, $status);
        $this->assertNull($carrier->fresh()->profile->verified_at);
    }

    public function test_a_lapsed_document_also_withdraws_verification(): void
    {
        $carrier = $this->carrier([
            'verification_status' => VerificationStatus::Verified,
            'verified_at' => now(),
        ]);

        VerificationDocument::factory()->create([
            'user_id' => $carrier->id,
            'document_type' => 'insurance',
            'status' => DocumentStatus::Approved,
            'expires_at' => now()->subDay(),
        ]);

        $this->assertNotSame(
            VerificationStatus::Verified,
            app(\App\Services\VerificationService::class)->refresh($carrier),
        );
    }

    public function test_rejecting_requires_a_reason(): void
    {
        $carrier = $this->carrier();
        $document = VerificationDocument::factory()->create([
            'user_id' => $carrier->id,
            'status' => DocumentStatus::Pending,
        ]);

        $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/documents/{$document->id}/reject", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('note');
    }

    public function test_a_rejection_reaches_the_carrier(): void
    {
        $carrier = $this->carrier();
        $document = VerificationDocument::factory()->create([
            'user_id' => $carrier->id,
            'document_type' => 'abn',
            'status' => DocumentStatus::Pending,
        ]);

        $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/documents/{$document->id}/reject", [
                'note' => 'The ABN on this extract does not match the one on your profile.',
            ])
            ->assertOk();

        $this->actingAs($carrier)
            ->getJson('/api/v1/carrier/documents')
            ->assertOk()
            ->assertJsonPath('data.items.0.status', 'rejected')
            ->assertJsonPath(
                'data.items.0.review_note',
                'The ABN on this extract does not match the one on your profile.',
            );
    }

    public function test_a_carrier_cannot_review_documents(): void
    {
        $carrier = $this->carrier();
        $document = VerificationDocument::factory()->create([
            'user_id' => $carrier->id,
            'status' => DocumentStatus::Pending,
        ]);

        $this->actingAs($carrier)
            ->postJson("/api/v1/admin/documents/{$document->id}/approve")
            ->assertForbidden();

        $this->assertSame(DocumentStatus::Pending, $document->fresh()->status);
    }

    /**
     * Seeded demo data has to agree with itself.
     *
     * The document factory used to invent its own type names
     * (`abn_certificate`, `drivers_licence`) which were never matched against
     * the configured vocabulary, so demo carriers appeared verified *and*
     * missing every requirement at once. Same drift the freight taxonomy hit.
     */
    public function test_seeded_verified_carriers_have_the_documents_to_match(): void
    {
        $this->seed(\Database\Seeders\UserSeeder::class);

        $service = app(\App\Services\VerificationService::class);

        $verified = User::where('role', UserRole::Carrier)
            ->whereHas('profile', fn ($q) => $q->where('verification_status', VerificationStatus::Verified))
            ->get();

        $this->assertNotEmpty($verified, 'The seeder should produce some verified carriers.');

        foreach ($verified as $carrier) {
            $this->assertSame(
                [],
                $service->missingTypes($carrier),
                "Seeded carrier {$carrier->id} is verified but missing requirements.",
            );
        }
    }

    // -- What verification gates ---------------------------------------------

    public function test_verification_does_not_gate_quoting_by_default(): void
    {
        $carrier = $this->carrier(['verification_status' => VerificationStatus::Unverified]);
        $job = FreightJob::factory()->create(['status' => \App\Enums\JobStatus::Published]);

        // The default is off, because none of the 291 migrated carriers is
        // verified — turning it on today would empty the marketplace.
        $this->actingAs($carrier)
            ->postJson("/api/v1/carrier/board/{$job->id}/quotes", ['amount' => 1200])
            ->assertCreated();
    }

    public function test_verification_gates_quoting_when_switched_on(): void
    {
        config(['freightmove.verification.require_to_quote' => true]);

        $carrier = $this->carrier(['verification_status' => VerificationStatus::Unverified]);
        $job = FreightJob::factory()->create(['status' => \App\Enums\JobStatus::Published]);

        $this->actingAs($carrier)
            ->postJson("/api/v1/carrier/board/{$job->id}/quotes", ['amount' => 1200])
            ->assertForbidden();

        $carrier->profile->forceFill(['verification_status' => VerificationStatus::Verified])->save();

        $this->actingAs($carrier->refresh())
            ->postJson("/api/v1/carrier/board/{$job->id}/quotes", ['amount' => 1200])
            ->assertCreated();
    }
}
