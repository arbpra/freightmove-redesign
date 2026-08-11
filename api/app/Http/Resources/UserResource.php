<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role->value,
            'status' => $this->status->value,
            'avatar_url' => $this->avatar_url,
            'timezone' => $this->timezone,
            'locale' => $this->locale,
            'email_verified' => $this->email_verified_at !== null,
            // Drives the post-login invitation for accounts brought over
            // from the pre-launch site. Never blocks sign-in.
            'should_update_password' => $this->shouldUpdatePassword(),
            'profile' => $this->whenLoaded('profile', fn () => [
                'company_name' => $this->profile->company_name,
                'abn_acn' => $this->profile->abn_acn,
                'city' => $this->profile->city,
                'state' => $this->profile->state,
                'verification_status' => $this->profile->verification_status->value,
                'rating' => $this->profile->rating,
                'completed_jobs_count' => $this->profile->completed_jobs_count,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
