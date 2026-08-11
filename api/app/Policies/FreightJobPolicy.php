<?php

namespace App\Policies;

use App\Enums\JobStatus;
use App\Models\FreightJob;
use App\Models\User;

/**
 * Ownership and lifecycle rules for freight jobs.
 *
 * Laravel resolves this by naming convention (Model -> ModelPolicy), so no
 * registration is needed in a provider.
 */
class FreightJobPolicy
{
    /** Admins can see and moderate anything. */
    public function before(User $user, string $ability): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function view(User $user, FreightJob $job): bool
    {
        return $job->shipper_id === $user->id;
    }

    public function update(User $user, FreightJob $job): bool
    {
        // Once a quote has been accepted the carrier is planning around these
        // details, so the shipper can no longer edit them unilaterally.
        return $job->shipper_id === $user->id && ! $this->isLocked($job);
    }

    public function delete(User $user, FreightJob $job): bool
    {
        return $job->shipper_id === $user->id && ! $this->isLocked($job);
    }

    /** Publishing a draft, or re-opening one that was cancelled. */
    public function publish(User $user, FreightJob $job): bool
    {
        return $job->shipper_id === $user->id
            && in_array($job->status, [JobStatus::Draft, JobStatus::Cancelled], true);
    }

    public function cancel(User $user, FreightJob $job): bool
    {
        return $job->shipper_id === $user->id && ! $job->status->isTerminal();
    }

    /**
     * Closing a booked load out.
     *
     * The **shipper** confirms it, not the carrier. The carrier has an obvious
     * interest in a job being marked done — it ends the window in which a
     * problem can be raised — so the party who received the freight is the one
     * who says it arrived. A carrier waiting on a quiet shipper can chase them
     * through the conversation on the load; admins can close it out through the
     * `before()` bypass if it comes to that.
     */
    public function complete(User $user, FreightJob $job): bool
    {
        return $job->shipper_id === $user->id && $job->status === JobStatus::Accepted;
    }

    /**
     * Bumping a load back to the top of the carrier board.
     *
     * Only meaningful while carriers can still act on it: a draft is not on the
     * board to be bumped, and a booked or cancelled load must not reappear.
     * The rate limit is a separate concern, handled in the controller so the
     * response can say when the next bump is due.
     */
    public function relist(User $user, FreightJob $job): bool
    {
        return $job->shipper_id === $user->id
            && in_array($job->status, JobStatus::openForQuotes(), true);
    }

    private function isLocked(FreightJob $job): bool
    {
        return in_array(
            $job->status,
            [JobStatus::Accepted, JobStatus::Completed, JobStatus::Disputed],
            true,
        );
    }
}
