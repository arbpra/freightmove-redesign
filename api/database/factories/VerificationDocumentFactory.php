<?php

namespace Database\Factories;

use App\Enums\DocumentStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\VerificationDocument>
 */
class VerificationDocumentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Drawn from the configured vocabulary rather than a list of its own.
        // These strings are matched against `verification.document_types` to
        // decide whether a carrier's requirements are met, so an invented value
        // here produces demo carriers who are "verified" and simultaneously
        // missing everything.
        $type = fake()->randomElement(
            array_keys(config('freightmove.verification.document_types', ['abn' => []])),
        );

        return [
            'user_id' => User::factory()->carrier(),
            'document_type' => $type,
            'file_path' => 'verification/'.fake()->uuid().'/'.$type.'.pdf',
            'status' => DocumentStatus::Pending,
            'reviewed_by' => null,
            'review_note' => null,
        ];
    }

    public function approved(?int $reviewerId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DocumentStatus::Approved,
            'reviewed_by' => $reviewerId,
            'review_note' => 'Document verified against ABR records.',
        ]);
    }

    public function rejected(?int $reviewerId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DocumentStatus::Rejected,
            'reviewed_by' => $reviewerId,
            'review_note' => 'Certificate has expired. Please upload a current version.',
        ]);
    }
}
