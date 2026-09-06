<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\LoadAlertService;
use App\Services\SubscriptionReminderService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled work, triggered by a URL.
 *
 * The scheduler is the better mechanism and `routes/console.php` still holds
 * the real definitions — this exists because `crontab` is not available over
 * SiteGround's SSH, so a cron that fetches a URL is the only kind that can be
 * created from Site Tools without a shell.
 *
 * **The token is not optional and it is not decoration.** The previous site had
 * exactly this idea and shipped it unauthenticated: `/reminder-one-email` and
 * `/bulk-email` were plain `Route::view(...)` entries that anyone could hit,
 * repeatedly, to fire mail at the entire user base. That is a denial-of-service
 * against your own sending reputation, available to anyone who guessed the URL.
 *
 * So:
 *
 *   - a 32-character minimum secret, compared in constant time;
 *   - no token configured means the route refuses everything, rather than
 *     defaulting open;
 *   - refusals are logged with the caller's IP, because a stranger probing
 *     these paths is worth seeing;
 *   - 404 rather than 401 on a bad token — a wrong secret should not confirm
 *     that the endpoint exists.
 *
 * The work itself is idempotent regardless: both sweeps keep ledgers, so a
 * misconfigured cron running every minute sends nothing extra.
 */
class CronController extends Controller
{
    /** Shorter than this is not a secret, it is a speed bump. */
    private const MIN_TOKEN_LENGTH = 32;

    /**
     * GET|POST /api/v1/cron/subscription-reminders
     *
     * The expiry emails: 5, 3 and 1 days before the end date, then 3, 7 and 15
     * days after it, then monthly. Safe to call as often as you like — the
     * `subscription_reminders` ledger means a second call the same day sends
     * nothing.
     */
    public function subscriptionReminders(Request $request, SubscriptionReminderService $reminders): JsonResponse
    {
        if ($refusal = $this->refuse($request, 'subscription-reminders')) {
            return $refusal;
        }

        $result = $reminders->run(null, $request->boolean('dry_run'));

        Log::info('Cron: subscription reminders.', $result);

        return ApiResponse::success($result, 'Subscription reminders swept.');
    }

    /**
     * GET|POST /api/v1/cron/load-alerts
     *
     * Retries carrier alerts whose send previously failed. New loads are
     * alerted when they are posted, not here — this is the safety net for a
     * transport outage, so a failed batch is not simply lost.
     */
    public function loadAlerts(Request $request, LoadAlertService $alerts): JsonResponse
    {
        if ($refusal = $this->refuse($request, 'load-alerts')) {
            return $refusal;
        }

        $exit = \Illuminate\Support\Facades\Artisan::call('loads:alert', ['--retry' => true]);

        Log::info('Cron: load alert retry.', ['exit' => $exit]);

        return ApiResponse::success(['exit_code' => $exit], 'Failed load alerts retried.');
    }

    /**
     * Null when the caller may proceed; a 404 response when they may not.
     */
    private function refuse(Request $request, string $task): ?JsonResponse
    {
        $expected = (string) config('freightmove.cron.token');

        if (strlen($expected) < self::MIN_TOKEN_LENGTH) {
            Log::warning('A cron URL was called but FM_CRON_TOKEN is unset or too short.', [
                'task' => $task,
                'ip' => $request->ip(),
            ]);

            return ApiResponse::error('Not found.', status: 404);
        }

        // Header first — a token in a query string ends up in access logs,
        // browser history and referrer headers. The query parameter is
        // supported because some cron UIs cannot set a header.
        $given = (string) ($request->header('X-Cron-Token') ?? $request->query('token', ''));

        if (! hash_equals($expected, $given)) {
            Log::warning('A cron URL was called with a bad token.', [
                'task' => $task,
                'ip' => $request->ip(),
            ]);

            // 404, not 401: a wrong secret should not confirm the route exists.
            return ApiResponse::error('Not found.', status: 404);
        }

        return null;
    }
}
