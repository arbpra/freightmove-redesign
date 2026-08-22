<?php

use App\Http\Controllers\Api\V1\Admin;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Carrier;
use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PayPalWebhookController;
use App\Http\Controllers\Api\V1\ReviewController;
// `Public` is a reserved word, so the namespace is aliased.
use App\Http\Controllers\Api\V1\Public as Publics;
use App\Http\Controllers\Api\V1\Shipper;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 routes
|--------------------------------------------------------------------------
|
| Mounted under /api/v1 (see bootstrap/app.php). Structure follows
| docs/06-api-spec.md.
|
*/

// Public reference data. Unauthenticated: the marketing quote form needs the
// freight vocabulary before anyone signs in.
Route::middleware('throttle:api')->prefix('public')->group(function () {
    Route::get('taxonomy', Publics\TaxonomyController::class);
    Route::get('suburbs', Publics\SuburbController::class);
    Route::get('routes/{pickup}/{dropoff}', Publics\RouteDistanceController::class);
    // The carrier pricing table. Public — the page exists to be read before
    // anyone signs up.
    Route::get('subscription-plans', Publics\SubscriptionPlanController::class);

    // A teaser of the live board for the home page. Deliberately narrow — see
    // PublicLoadResource for what a guest may and may not see of a load.
    Route::get('loads/recent', Publics\RecentLoadController::class);

    // The full board, open to anyone. A carrier deciding whether to subscribe
    // should be able to see the freight first; quoting is what needs an account.
    Route::get('loads', Publics\PublicLoadBoardController::class);
});

// Unauthenticated and it sends email, so it carries its own hourly limiter
// rather than the general read allowance.
Route::post('contact', Publics\ContactController::class)->middleware('throttle:contact');

// PayPal's server-to-server notifications. Unauthenticated by necessity —
// PayPal has no session — so the controller verifies every request against
// PayPal itself before acting on it. Not throttled by IP: PayPal legitimately
// bursts retries, and dropping a payment event to save a few requests would
// leave someone paid-up with no subscription.
Route::post('webhooks/paypal', PayPalWebhookController::class);

Route::prefix('auth')->group(function () {
    // Throttled by IP *and* by the submitted email, so spreading an attack
    // across addresses does not buy extra attempts against one account.
    Route::middleware('throttle:auth')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
        Route::post('forgot-password', [PasswordResetController::class, 'forgot']);
        Route::post('reset-password', [PasswordResetController::class, 'reset']);
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);

        // Throttled like the other credential endpoints: it accepts the current
        // password, so it is a guessing surface too.
        Route::put('password', [AuthController::class, 'changePassword'])
            ->middleware('throttle:auth');
    });
});

// Every authenticated route is throttled. Without this a valid token is a
// licence to hammer the API as fast as the network allows.
Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function () {
    // Every role has a feed, so this sits outside the role-prefixed groups.
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('unread-count', [NotificationController::class, 'unreadCount']);
        Route::patch('{notification}/read', [NotificationController::class, 'markRead']);
        Route::post('read-all', [NotificationController::class, 'markAllRead']);
    });

    // Reviews. Both sides of a completed load review each other, so this is
    // outside the role groups too; ReviewPolicy owns the rules.
    Route::get('jobs/{job}/reviews', [ReviewController::class, 'index']);
    Route::post('jobs/{job}/reviews', [ReviewController::class, 'store'])
        ->middleware('throttle:writes');

    // Messaging. Both roles use it, so it also sits outside the role groups;
    // ConversationPolicy owns who may see and say what.
    Route::prefix('conversations')->group(function () {
        Route::get('/', [ConversationController::class, 'index']);
        Route::get('{conversation}', [ConversationController::class, 'show']);

        Route::middleware('throttle:writes')->group(function () {
            Route::post('/', [ConversationController::class, 'store']);
            Route::post('{conversation}/messages', [ConversationController::class, 'send']);
        });
    });

    Route::prefix('shipper')->middleware('role:shipper')->group(function () {
        Route::get('overview', Shipper\OverviewController::class);

        Route::apiResource('jobs', Shipper\FreightJobController::class)->only(['index', 'show']);

        // Quotes received on a load, and the decision on them.
        Route::get('jobs/{job}/quotes', [Shipper\QuoteDecisionController::class, 'index']);

        // Writes carry a tighter limit than reads: this is what an abusive
        // account would use to flood the marketplace. Lifecycle transitions are
        // their own verbs rather than a status field on PATCH, so each can
        // carry its own authorisation rule.
        Route::middleware('throttle:writes')->group(function () {
            Route::post('jobs', [Shipper\FreightJobController::class, 'store']);
            Route::match(['put', 'patch'], 'jobs/{job}', [Shipper\FreightJobController::class, 'update']);
            Route::delete('jobs/{job}', [Shipper\FreightJobController::class, 'destroy']);
            Route::post('jobs/{job}/publish', [Shipper\FreightJobController::class, 'publish']);
            Route::post('jobs/{job}/cancel', [Shipper\FreightJobController::class, 'cancel']);
            Route::post('jobs/{job}/relist', [Shipper\FreightJobController::class, 'relist']);

            // Photos. Under the `uploads` limiter rather than `writes`: an
            // upload costs far more than a form post, so it gets its own floor.
            Route::post('jobs/{job}/images', [Shipper\LoadImageController::class, 'store'])
                ->withoutMiddleware('throttle:writes')
                ->middleware('throttle:uploads');
            Route::delete('jobs/{job}/images', [Shipper\LoadImageController::class, 'destroy']);
            // The shipper closes the load out — see FreightJobPolicy::complete
            // for why it is not the carrier.
            Route::post('jobs/{job}/complete', [Shipper\FreightJobController::class, 'complete']);

            Route::post('quotes/{quote}/accept', [Shipper\QuoteDecisionController::class, 'accept']);
            Route::post('quotes/{quote}/decline', [Shipper\QuoteDecisionController::class, 'decline']);
        });
    });

    Route::prefix('carrier')->middleware('role:carrier')->group(function () {
        Route::get('overview', Carrier\OverviewController::class);

        // The open load board.
        Route::get('board', [Carrier\LoadBoardController::class, 'index']);
        Route::get('board/{job}', [Carrier\LoadBoardController::class, 'show']);

        Route::get('quotes', [Carrier\QuoteController::class, 'index']);

        // Own profile. No id in any of these paths — a carrier can only ever
        // address their own record.
        Route::get('profile', [Carrier\ProfileController::class, 'show']);
        Route::get('subscription', [Carrier\SubscriptionController::class, 'show']);
        Route::get('documents', [Carrier\VerificationDocumentController::class, 'index']);
        Route::get('documents/{document}/download', [Carrier\VerificationDocumentController::class, 'download']);

        Route::middleware('throttle:writes')->group(function () {
            Route::post('board/{job}/quotes', [Carrier\QuoteController::class, 'store']);
            Route::delete('quotes/{quote}', [Carrier\QuoteController::class, 'destroy']);

            Route::match(['put', 'patch'], 'profile', [Carrier\ProfileController::class, 'update']);
            Route::delete('documents/{document}', [Carrier\VerificationDocumentController::class, 'destroy']);

            Route::post('subscription/trial', [Carrier\SubscriptionController::class, 'startTrial']);
            Route::post('subscription/checkout', [Carrier\SubscriptionController::class, 'checkout']);
            // The return leg from PayPal. Scoped to the caller's own orders.
            Route::post('subscription/capture', [Carrier\SubscriptionController::class, 'capture']);
            Route::post('subscription/{subscription}/cancel', [Carrier\SubscriptionController::class, 'cancel']);
        });

        // Uploads are heavier than an ordinary write, so they get their own
        // tighter limit rather than sharing the general write allowance.
        Route::post('documents', [Carrier\VerificationDocumentController::class, 'store'])
            ->middleware('throttle:uploads');
    });

    Route::prefix('admin')->middleware('role:admin')->group(function () {
        Route::get('overview', Admin\OverviewController::class);

        // Oversight. Read-only: editing someone's freight or account behind
        // their back produces a record neither party recognises.
        Route::get('users', [Admin\UserController::class, 'index']);
        Route::get('jobs', [Admin\JobOversightController::class, 'index']);

        // Subscriptions waiting on payment. Under the manual gateway this is
        // how money gets recognised.
        Route::get('subscriptions', [Admin\SubscriptionController::class, 'index']);

        // Verification queue.
        Route::get('verifications', [Admin\VerificationController::class, 'index']);
        Route::get('documents/{document}/download', [Carrier\VerificationDocumentController::class, 'download']);

        Route::middleware('throttle:writes')->group(function () {
            Route::post('documents/{document}/approve', [Admin\VerificationController::class, 'approve']);
            Route::post('documents/{document}/reject', [Admin\VerificationController::class, 'reject']);

            // Suspending is the one write here. Role changes are deliberately
            // absent — see UserController.
            Route::post('users/{user}/status', [Admin\UserController::class, 'setStatus']);
            Route::post('subscriptions/{subscription}/confirm', [Admin\SubscriptionController::class, 'confirm']);
        });
    });
});
