<?php

namespace App\Providers;

use App\Contracts\PaymentGateway;
use App\Services\Payments\ManualGateway;
use App\Services\Payments\PayPalGateway;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The gateway is chosen by configuration, so nothing that takes money
        // has to know which one is in use. An unrecognised value falls back to
        // `manual` rather than throwing: a typo in .env should mean payments
        // wait for a human, not that the whole application fails to boot.
        $this->app->bind(PaymentGateway::class, function () {
            return match (config('freightmove.subscriptions.gateway')) {
                'paypal' => new PayPalGateway(),
                default => new ManualGateway(),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->definePasswordPolicy();
        $this->defineRateLimiters();
        $this->definePasswordResetUrl();
    }

    /**
     * Points the reset email at the Angular app.
     *
     * Laravel's default builds the link from the `password.reset` **web** route.
     * This application serves an API only and has no such route, so the default
     * throws RouteNotFoundException the moment a *registered* address asks for a
     * link — the endpoint would answer 500 for exactly the people it is for.
     * (The original test only used an unregistered address, which never reaches
     * the notification, so it passed.)
     *
     * The token goes in the path and the address in the query string, matching
     * the Angular route at /reset-password/:token.
     */
    private function definePasswordResetUrl(): void
    {
        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            $base = rtrim((string) config('freightmove.frontend_url'), '/');

            return $base.'/reset-password/'.$token.'?'.http_build_query([
                'email' => $notifiable->getEmailForPasswordReset(),
            ]);
        });
    }

    /**
     * Laravel's unconfigured default is `min:8` and nothing else, which accepts
     * "password". Every rule below applies wherever `Password::defaults()` is
     * used — registration and password reset.
     *
     * `uncompromised()` checks the k-anonymity Have I Been Pwned range API: only
     * the first five characters of the hash leave the server, never the
     * password. It is skipped outside production so tests and local work do not
     * depend on a third-party service being reachable.
     */
    private function definePasswordPolicy(): void
    {
        Password::defaults(function () {
            $rule = Password::min(10)->letters()->numbers();

            return $this->app->isProduction()
                ? $rule->mixedCase()->uncompromised()
                : $rule;
        });
    }

    /**
     * Named limiters used by routes/api.php.
     *
     * Keyed by user id when signed in and by IP otherwise, so one abusive
     * client cannot exhaust the quota for everyone behind a shared address.
     */
    private function defineRateLimiters(): void
    {
        // General authenticated API traffic.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->id ?: $request->ip()));

        // Credential endpoints: brute-force targets, so much tighter, and keyed
        // by email as well as IP to slow attacks spread across addresses.
        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(6)->by($request->ip()),
            Limit::perMinute(6)->by((string) $request->input('email')),
        ]);

        // Writes are costlier than reads and are what an abusive account would
        // use to flood the marketplace with junk loads.
        RateLimiter::for('writes', fn (Request $request) => Limit::perMinute(30)
            ->by($request->user()?->id ?: $request->ip()));

        // File uploads cost disk and CPU rather than a database row, so they
        // are limited well below ordinary writes.
        RateLimiter::for('uploads', fn (Request $request) => Limit::perHour(20)
            ->by($request->user()?->id ?: $request->ip()));

        // The contact form is unauthenticated and sends email, so it is the
        // obvious relay to abuse. An hourly limit rather than a per-minute one:
        // nobody has five genuine enquiries in an hour, and a per-minute cap
        // would still permit hundreds a day.
        RateLimiter::for('contact', fn (Request $request) => [
            Limit::perHour(5)->by($request->ip()),
            Limit::perHour(3)->by((string) $request->input('email')),
        ]);
    }
}
