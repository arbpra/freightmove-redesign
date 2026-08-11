<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Enums\QuoteStatus;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\JobQuote
 */
class JobQuoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'job_id' => $this->job_id,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'estimated_delivery_date' => $this->estimated_delivery_date?->toDateString(),
            'notes' => $this->notes,
            'status' => $this->status->value,
            // What a shipper needs to compare quotes on more than price.
            // Contact details are withheld until the quote is accepted: before
            // that, a shipper could otherwise harvest every carrier's number by
            // posting a load and never booking.
            'carrier' => $this->whenLoaded('carrier', fn () => array_filter([
                'id' => $this->carrier->id,
                'name' => $this->carrier->name,
                'company_name' => $this->carrier->profile?->company_name,
                'rating' => $this->carrier->profile?->rating,
                'completed_jobs_count' => $this->carrier->profile?->completed_jobs_count,
                'verification_status' => $this->carrier->profile?->verification_status?->value,
                'email' => $this->status === QuoteStatus::Accepted ? $this->carrier->email : null,
                'phone' => $this->status === QuoteStatus::Accepted ? $this->carrier->phone : null,
            ], fn ($value) => $value !== null)),
            'job' => $this->whenLoaded('job', fn () => [
                'id' => $this->job->id,
                'title' => $this->job->title,
                'pickup_location' => $this->job->pickup_location,
                'delivery_location' => $this->job->delivery_location,
                'status' => $this->job->status->value,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
