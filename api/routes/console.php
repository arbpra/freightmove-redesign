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

// Subscription reminders: a warning before the end date, a notice after it.
// Once a day, early enough that a carrier reading it over breakfast still has
// the whole working day to renew before the period runs out.
//
// `withoutOverlapping` because the sweep sends email and a slow transport can
// still be working when the next run starts; the ledger would catch a
// duplicate anyway, but two sweeps competing for the same rows is pointless
// load. `onOneServer` is harmless on a single host and correct if there is
// ever a second.
Schedule::command('subscriptions:remind')
    ->dailyAt('07:30')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping()
    ->onOneServer();
