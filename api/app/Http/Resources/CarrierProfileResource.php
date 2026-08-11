<?php

namespace App\Http\Resources;

use App\Models\VerificationDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A carrier's own profile, as they see it.
 *
 * This is the private view — it carries the ABN, insurance details and the
 * verification queue. Nothing here is served to another user; the public view
 * of a carrier is the small block inside JobQuoteResource.
 *
 * @mixin \App\Models\User
 */
class CarrierProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $profile = $this->profile;
        $carrier = $this->carrier;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar_url' => $this->avatar_url,

            'company_name' => $profile?->company_name,
            'abn_acn' => $profile?->abn_acn,
            'business_type' => $profile?->business_type,
            'address_line_1' => $profile?->address_line_1,
            'address_line_2' => $profile?->address_line_2,
            'city' => $profile?->city,
            'state' => $profile?->state,
            'postal_code' => $profile?->postal_code,
            'bio' => $profile?->bio,

            'fleet_size' => $carrier?->fleet_size,
            'service_radius_km' => $carrier?->service_radius_km,
            'preferred_regions' => $carrier?->preferred_regions ?? [],
            'insurance_provider' => $carrier?->insurance_provider,
            'insurance_policy_number' => $carrier?->insurance_policy_number,
            'operating_since' => $carrier?->operating_since,

            // Earned, not entered. Read-only here so the client has no reason
            // to try sending them back.
            'rating' => $profile?->rating !== null ? (float) $profile->rating : null,
            'completed_jobs_count' => $profile?->completed_jobs_count ?? 0,

            'verification' => [
                'status' => $profile?->verification_status->value,
                'verified_at' => $profile?->verified_at?->toIso8601String(),
                'note' => $profile?->verification_note,
                'documents' => $this->whenLoaded(
                    'verificationDocuments',
                    fn () => $this->verificationDocuments
                        ->map(fn (VerificationDocument $doc) => [
                            'id' => $doc->id,
                            'document_type' => $doc->document_type,
                            // The stored filename is randomised, so this is the
                            // only label a person recognises.
                            'original_name' => $doc->original_name,
                            'size_bytes' => $doc->size_bytes,
                            'status' => $doc->status->value,
                            'review_note' => $doc->review_note,
                            'expires_at' => $doc->expires_at?->toDateString(),
                            'has_lapsed' => $doc->hasLapsed(),
                            'uploaded_at' => $doc->created_at?->toIso8601String(),
                        ])->values()->all(),
                ),
            ],
        ];
    }
}
