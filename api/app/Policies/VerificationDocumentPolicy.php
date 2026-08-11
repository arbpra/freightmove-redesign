<?php

namespace App\Policies;

use App\Enums\DocumentStatus;
use App\Models\User;
use App\Models\VerificationDocument;

/**
 * Who may see and act on a verification document.
 *
 * These are identity documents — an ABN extract, an insurance certificate,
 * sometimes a driver licence. Exactly two parties have any business with one:
 * the carrier who uploaded it, and an admin reviewing it.
 */
class VerificationDocumentPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function view(User $user, VerificationDocument $document): bool
    {
        return $document->user_id === $user->id;
    }

    /**
     * A document can be withdrawn while it is still waiting.
     *
     * Not once it has been reviewed: an approved document is the evidence
     * behind a verification, and a rejected one is the record of why. Deleting
     * either would let a carrier erase an unfavourable decision and resubmit as
     * though it never happened.
     */
    public function delete(User $user, VerificationDocument $document): bool
    {
        return $document->user_id === $user->id
            && $document->status === DocumentStatus::Pending;
    }

    /** Reviewing is admin-only, handled entirely by `before()`. */
    public function review(User $user, VerificationDocument $document): bool
    {
        return false;
    }
}
