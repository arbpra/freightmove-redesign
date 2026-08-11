<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ReputationService;
use Illuminate\Console\Command;

/**
 * Rebuilds every rating and completed-job count from the underlying records.
 *
 * Needed because both columns predate the code that derives them: they were
 * written by the seeder alone, which invented ratings and job counts that no
 * review or completed load supports. Running this replaces the fiction with
 * whatever the data actually says — usually "not rated yet", which is the
 * honest answer for a platform that has not launched.
 *
 * Also worth running after any bulk import, or if reviews are ever restored
 * from a backup.
 */
class RecomputeReputations extends Command
{
    protected $signature = 'reputations:recompute {--dry-run : Report what would change without writing}';

    protected $description = 'Rebuild user ratings and completed-job counts from reviews and completed loads';

    public function handle(ReputationService $reputation): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $changed = 0;
        $cleared = 0;

        if ($dryRun) {
            $this->warn('Dry run — nothing will be written.');
        }

        User::with('profile')->whereHas('profile')->chunkById(200, function ($users) use (
            $reputation, $dryRun, &$changed, &$cleared
        ) {
            foreach ($users as $user) {
                // One derivation for both paths, so a dry run cannot report
                // something different from what a real run would do.
                $after = $reputation->derive($user);

                $ratingChanged = $this->ratingDiffers($user->profile->rating, $after['rating']);
                $countChanged = (int) $user->profile->completed_jobs_count !== $after['completed_jobs_count'];

                if ($ratingChanged || $countChanged) {
                    $changed++;

                    if ($user->profile->rating !== null && $after['rating'] === null) {
                        $cleared++;
                    }
                }

                if (! $dryRun) {
                    $reputation->refresh($user);
                }
            }
        });

        $this->info("{$changed} profiles updated.");

        if ($cleared > 0) {
            $this->warn(
                "{$cleared} of those had a rating with no reviews behind it and are now unrated."
            );
        }

        return self::SUCCESS;
    }

    /**
     * The stored rating is a decimal cast, so it arrives as a string like
     * "4.07"; comparing it to a float directly would report every profile as
     * changed on every run.
     */
    private function ratingDiffers(mixed $stored, ?float $derived): bool
    {
        if ($stored === null || $derived === null) {
            return ($stored === null) !== ($derived === null);
        }

        return abs((float) $stored - $derived) > 0.001;
    }
}
