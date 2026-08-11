<?php

namespace App\Policies;

use App\Enums\JobStatus;
use App\Models\FreightJob;
use App\Models\Review;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Who may review whom.
 *
 * A review is only worth reading if it could only have been written by someone
 * who actually transacted, so three things must hold: the job is **completed**,
 * the reviewer was one of the two parties to it, and they have not already
 * reviewed it.
 *
 * There is deliberately no admin bypass. An admin writing a review would be the
 * platform anonymously grading its own suppliers, which is the one thing a
 * reputation system cannot survive.
 */
class ReviewPolicy
{
    public function create(User $user, FreightJob $job): Response
    {
        if ($job->status !== JobStatus::Completed) {
            return Response::deny('You can review a load once it has been completed.');
        }

        $carrierId = $job->acceptance?->carrier_id;

        if ($user->id !== $job->shipper_id && $user->id !== $carrierId) {
            return Response::deny('Only the shipper and the carrier on a load can review it.');
        }

        // Also a unique index on (job_id, reviewer_id), which is what actually
        // enforces it against a double submit.
        if (Review::where('job_id', $job->id)->where('reviewer_id', $user->id)->exists()) {
            return Response::deny('You have already reviewed this load.');
        }

        return Response::allow();
    }
}
