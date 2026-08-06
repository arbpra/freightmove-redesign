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
            return $request->is('api/*')
                ? ApiResponse::error('This action is unauthorized.', status: 403)
                : null;
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
