<?php

use App\Support\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // No login page exists to redirect to, and Authenticate resolves this
        // target eagerly, so returning null keeps it from calling route('login').
        $middleware->redirectGuestsTo(fn () => null);

        // Prepended to the global stack so it is the outermost layer: responses
        // rendered from an exception deeper in (401, 403, 429, 404) still pass
        // back out through it and get the headers.
        $middleware->prepend(\App\Http\Middleware\SecurityHeaders::class);

        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
            'active' => \App\Http\Middleware\EnsureUserIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // This is an API-only backend consumed by the Angular app, so there is no
        // login route to redirect guests to. Always answer /api/* in JSON.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e) => $request->is('api/*') || $request->expectsJson()
        );

        // Failures use the same envelope as successes, per docs/06-api-spec.md.
        $exceptions->render(function (ValidationException $e, Request $request) {
            return $request->is('api/*')
                ? ApiResponse::error('Validation failed.', $e->errors(), 422)
                : null;
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return $request->is('api/*')
                ? ApiResponse::error('Unauthenticated.', status: 401)
                : null;
        });

        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            // Keep the policy's own wording. Several policies deny with a
            // reason on purpose — "an active subscription is needed to quote",
            // "quote on this load first" — because "you cannot do that" leaves
            // the user with no idea which of several situations they are in,
            // and nothing to do about it. Laravel puts that reason on the
            // exception; overwriting it here threw all of them away.
            //
            // Falls back to the generic line, which is also what Laravel uses
            // when a policy simply returns false and has said nothing.
            $reason = trim($e->getMessage());

            return ApiResponse::error(
                $reason !== '' ? $reason : 'This action is unauthorized.',
                status: 403,
            );
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            return $request->is('api/*')
                ? ApiResponse::error('Too many attempts. Please try again shortly.', status: 429)
                : null;
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            return $request->is('api/*')
                ? ApiResponse::error('Resource not found.', status: 404)
                : null;
        });
    })->create();
