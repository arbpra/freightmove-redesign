<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Generates the secret that guards the cron URLs.
 *
 * A command rather than a note in the docs saying "pick something random",
 * because the thing it protects is the ability to email every carrier on the
 * platform. People asked to invent a secret invent a memorable one.
 */
class MakeCronToken extends Command
{
    protected $signature = 'cron:token';

    protected $description = 'Generate a token for the cron URLs';

    public function handle(): int
    {
        // 64 hex characters from a CSPRNG. Long enough that guessing is not a
        // strategy, and hex so it survives a query string untouched.
        $token = bin2hex(random_bytes(32));

        $base = rtrim((string) config('app.url'), '/');
        $current = (string) config('freightmove.cron.token');

        $this->line('');
        $this->line('  FM_CRON_TOKEN='.$token);
        $this->line('');
        $this->line('  Put that in .env, then run:  php artisan config:cache');
        $this->line('');
        $this->line('  Cron URLs once it is set:');
        $this->line("    {$base}/api/v1/cron/subscription-reminders?token={$token}");
        $this->line("    {$base}/api/v1/cron/load-alerts?token={$token}");
        $this->line('');

        if ($current !== '') {
            $this->warn('  A token is already configured. Replacing it breaks any cron using the old one.');
            $this->line('');
        }

        return self::SUCCESS;
    }
}
