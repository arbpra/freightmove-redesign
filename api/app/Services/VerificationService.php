<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\VerificationStatus;
use App\Models\User;
use App\Models\VerificationDocument;
use Illuminate\Support\Collection;

/**
 * Decides what a carrier's verification status should be.
 *
 * Kept in one place because the answer is derived, not stored: a profile is
 * verified because every required document is approved and current, and it
 * stops being verified the moment that is no longer true. Scattering that rule
 * across the upload endpoint, the review endpoint and the admin queue is how
 * profiles end up stuck on "verified" with an expired insurance certificate.
 */
class VerificationService
{
    /**
     * Document types the carrier is asked for.
     *
     * @return array<string, array{label: string, required: bool}>
     */
    public function documentTypes(): array
    {
        return config('freightmove.verification.document_types', []);
    }

    /** @return list<string> */
    public function requiredTypes(): array
    {
        return array_keys(array_filter(
            $this->documentTypes(),
            fn (array $type) => $type['required'] ?? false,
        ));
    }

    /**
     * Required types with no approved, current document.
     *
     * @return list<string>
     */
    public function missingTypes(User $user): array
    {
        $satisfied = $this->usableDocuments($user)->pluck('document_type')->all();

        return array_values(array_diff($this->requiredTypes(), $satisfied));
    }

    /**
     * Recomputes and stores the profile's verification status.
     *
     * Never promotes to Verified on its own — a human approves each document,
     * and this only reflects the consequence. It does demote: an approved
     * document that has since lapsed, or one an admin has rejected, drops the
     * profile back out of Verified straight away.
     */
    public function refresh(User $user): VerificationStatus
    {
        $profile = $user->profile;

        if (! $profile) {
            return VerificationStatus::Unverified;
        }

        $documents = $user->verificationDocuments()->get();
        $status = $this->deriveStatus($documents);

        // An admin may verify a carrier on evidence that never passed through
        // this table — a phone call to their insurer, a licence checked in
        // person. Recomputing must not quietly overturn that. Verification is
        // only withdrawn when something has actively gone wrong: a document
        // rejected, or one that has lapsed. Merely lacking an upload is not a
        // reason to strip a badge a human deliberately granted.
        if ($profile->verification_status === VerificationStatus::Verified
            && $status !== VerificationStatus::Verified
            && ! $this->hasFailingEvidence($documents)) {
            return VerificationStatus::Verified;
        }

        $profile->forceFill([
            'verification_status' => $status,
            'verified_at' => $status === VerificationStatus::Verified
                ? ($profile->verified_at ?? now())
                : null,
        ])->save();

        return $status;
    }

    /**
     * Positive evidence that a verified carrier should no longer be verified:
     * a document a reviewer refused, or one that has since expired.
     *
     * @param  Collection<int, VerificationDocument>  $documents
     */
    private function hasFailingEvidence(Collection $documents): bool
    {
        return $documents->contains(
            fn (VerificationDocument $d) => $d->status === DocumentStatus::Rejected || $d->hasLapsed(),
        );
    }

    /**
     * @param  Collection<int, VerificationDocument>  $documents
     */
    private function deriveStatus(Collection $documents): VerificationStatus
    {
        if ($documents->isEmpty()) {
            return VerificationStatus::Unverified;
        }

        $required = $this->requiredTypes();
        $usable = $documents->filter(fn (VerificationDocument $d) => $d->isUsable())
            ->pluck('document_type')
            ->all();

        if (array_diff($required, $usable) === []) {
            return VerificationStatus::Verified;
        }

        // Something is still with a reviewer, so this is in progress rather
        // than refused.
        if ($documents->contains(fn (VerificationDocument $d) => $d->status === DocumentStatus::Pending)) {
            return VerificationStatus::Pending;
        }

        // Everything has been looked at and the requirements are still not met.
        if ($documents->contains(fn (VerificationDocument $d) => $d->status === DocumentStatus::Rejected)) {
            return VerificationStatus::Rejected;
        }

        // Approved documents, but not the ones that are required.
        return VerificationStatus::Unverified;
    }

    /**
     * Approved documents that have not lapsed.
     *
     * @return Collection<int, VerificationDocument>
     */
    private function usableDocuments(User $user): Collection
    {
        return $user->verificationDocuments()
            ->where('status', DocumentStatus::Approved)
            ->get()
            ->filter(fn (VerificationDocument $d) => ! $d->hasLapsed());
    }
}
