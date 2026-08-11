<?php

namespace App\Services;

use App\Enums\JobStatus;
use App\Models\FreightJob;
use App\Models\Review;
use App\Models\User;

/**
 * Keeps a user's rating and completed-job count honest.
 *
 * Both live on `user_profiles` as stored columns, but neither is a fact anyone
 * gets to assert — they are **derived**, recomputed from reviews and completed
 * jobs whenever either changes. They are stored rather than computed on read
 * because the carrier board and every quote row display them, and a subquery
 * per row is exactly the N+1 those columns exist to avoid.
 *
 * Before this existed the columns were only ever written by the factory, so the
 * rating a shipper saw beside a carrier's quote was invented. On a marketplace
 * that sells trust, a fabricated reputation score is worse than none.
 */
class ReputationService
{
    /**
     * Recomputes both figures for one user.
     *
     * Null rating rather than zero when there are no reviews: "not rated yet"
     * and "rated zero" are opposite claims, and the client renders them
     * differently.
     */
    public function refresh(User $user): void
    {
        $profile = $user->profile;

        if (! $profile) {
            return;
        }

        $profile->forceFill($this->derive($user))->save();
    }

    /**
     * What the figures *should* be, without writing them.
     *
     * Public so `reputations:recompute --dry-run` reports using the same
     * derivation the real run applies. A second copy of this arithmetic in the
     * command would be free to drift from this one, and a dry run that lies is
     * worse than no dry run.
     *
     * @return array{rating: float|null, completed_jobs_count: int}
     */
    public function derive(User $user): array
    {
        $reviews = Review::where('reviewed_user_id', $user->id);
        $count = (clone $reviews)->count();

        return [
            'rating' => $count > 0 ? round((float) (clone $reviews)->avg('rating'), 2) : null,
            'completed_jobs_count' => $this->completedJobs($user),
        ];
    }

    /**
     * Jobs this user saw through to completion, on whichever side they were.
     *
     * Counted from the jobs themselves rather than from reviews: a job is
     * completed whether or not anyone got round to writing a review, and a
     * carrier's track record should not shrink because a shipper was quiet.
     */
    private function completedJobs(User $user): int
    {
        return FreightJob::query()
            ->where('status', JobStatus::Completed)
            ->where(function ($query) use ($user) {
                $query->where('shipper_id', $user->id)
                    ->orWhereHas('acceptance', fn ($q) => $q->where('carrier_id', $user->id));
            })
            ->count();
    }
}
