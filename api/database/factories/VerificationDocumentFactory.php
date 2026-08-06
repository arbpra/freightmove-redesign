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
        $type = fake()->randomElement([
            'abn_certificate', 'public_liability_insurance', 'goods_in_transit_insurance',
            'drivers_licence', 'heavy_vehicle_accreditation',
        ]);

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
