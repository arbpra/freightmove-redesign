<?php

namespace App\Http\Controllers\Api\V1\Carrier;

use App\Enums\VerificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Carrier\UpdateCarrierProfileRequest;
use App\Http\Resources\CarrierProfileResource;
use App\Models\Carrier;
use App\Models\UserProfile;
use App\Services\VerificationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A carrier's own profile.
 *
 * Everything is scoped to the authenticated user — there is no id in any route
 * here, so one carrier cannot address another's profile at all.
 */
class ProfileController extends Controller
{
    public function __construct(private readonly VerificationService $verification) {}

    /**
     * GET /api/v1/carrier/profile
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load(['profile', 'carrier', 'verificationDocuments']);

        return ApiResponse::success([
            'profile' => new CarrierProfileResource($user),
            'requirements' => $this->requirements($user),
        ]);
    }

    /**
     * PATCH /api/v1/carrier/profile
     */
    public function update(UpdateCarrierProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        DB::transaction(function () use ($request, $user) {
            if ($request->userData() !== []) {
                $user->fill($request->userData())->save();
            }

            // firstOrNew rather than assuming the rows exist: a carrier who
            // registered before profiles were created has neither.
            $profile = $user->profile ?? new UserProfile(['user_id' => $user->id]);
            $carrier = $user->carrier ?? new Carrier(['user_id' => $user->id]);

            $wasVerified = $profile->verification_status === VerificationStatus::Verified;

            $profile->fill($request->profileData());
            $carrier->fill($request->carrierData());

            // Verification was granted against a specific ABN and insurer.
            // Changing either means the approval no longer describes this
            // business, so it goes back in the queue rather than carrying over.
            if ($wasVerified && $request->touchesVerifiedFacts() && $profile->isDirty()) {
                $profile->forceFill([
                    'verification_status' => VerificationStatus::Pending,
                    'verified_at' => null,
                    'verification_note' => 'Your business or insurance details changed, so your '
                        .'verification is being checked again. You can keep quoting in the meantime.',
                ]);
            }

            $profile->user_id = $user->id;
            $carrier->user_id = $user->id;
            $profile->save();
            $carrier->save();
        });

        $user->refresh()->load(['profile', 'carrier', 'verificationDocuments']);

        return ApiResponse::success([
            'profile' => new CarrierProfileResource($user),
            'requirements' => $this->requirements($user),
        ], 'Profile updated.');
    }

    /**
     * What the carrier still has to do, so the client never has to hardcode
     * the document list.
     *
     * @return array<string, mixed>
     */
    private function requirements($user): array
    {
        return [
            'document_types' => array_map(
                fn (string $key, array $type) => [
                    'key' => $key,
                    'label' => $type['label'],
                    'required' => $type['required'] ?? false,
                ],
                array_keys($this->verification->documentTypes()),
                array_values($this->verification->documentTypes()),
            ),
            'missing' => $this->verification->missingTypes($user),
            'max_upload_kb' => (int) config('freightmove.verification.max_upload_kb'),
            'accepted_types' => config('freightmove.verification.allowed_mime_types'),
            // Whether verification currently gates quoting. False today; see
            // config/freightmove.php for why.
            'required_to_quote' => (bool) config('freightmove.verification.require_to_quote'),
        ];
    }
}
