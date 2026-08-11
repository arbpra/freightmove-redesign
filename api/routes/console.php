<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled maintenance
|--------------------------------------------------------------------------
*/

// Access tokens expire after config('sanctum.expiration') minutes, but the rows
// remain until pruned. Clearing them keeps a stolen database dump from handing
// over a pile of long-dead credentials to brute force offline.
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Password reset tokens have no expiry sweep of their own.
Schedule::command('auth:clear-resets')->daily();
