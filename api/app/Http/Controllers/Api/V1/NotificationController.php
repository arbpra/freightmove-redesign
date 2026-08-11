<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in user's notification feed.
 *
 * Available to every role, so it sits outside the role-prefixed groups. Every
 * query is scoped to the caller — there is no id anywhere that could address
 * another user's feed, and the two write actions re-scope before they touch
 * anything.
 */
class NotificationController extends Controller
{
    private const MAX_PER_PAGE = 50;

    /**
     * GET /api/v1/notifications
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'unread' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ]);

        $feed = $request->user()->appNotifications()
            ->when($validated['unread'] ?? false, fn ($query) => $query->unread())
            ->latest()
            ->paginate($validated['per_page'] ?? 20)
            ->withQueryString();

        return ApiResponse::success([
            'items' => NotificationResource::collection($feed->items()),
            // Always the total unread, not the count on this page — it drives
            // the badge, which must not change as someone pages through.
            'unread_count' => $request->user()->appNotifications()->unread()->count(),
            'meta' => [
                'current_page' => $feed->currentPage(),
                'last_page' => $feed->lastPage(),
                'per_page' => $feed->perPage(),
                'total' => $feed->total(),
            ],
        ]);
    }

    /**
     * GET /api/v1/notifications/unread-count
     *
     * Its own endpoint because the shell polls this on a timer and has no use
     * for the rows — sending twenty notifications every minute to render one
     * number would be waste.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return ApiResponse::success([
            'unread_count' => $request->user()->appNotifications()->unread()->count(),
        ]);
    }

    /**
     * PATCH /api/v1/notifications/{notification}/read
     */
    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        // Scoped rather than policied: the only rule is ownership, and a 404
        // avoids confirming that someone else's notification id exists.
        abort_unless($notification->user_id === $request->user()->id, 404);

        $notification->forceFill(['is_read' => true])->save();

        return ApiResponse::success([
            'unread_count' => $request->user()->appNotifications()->unread()->count(),
        ], 'Marked as read.');
    }

    /**
     * POST /api/v1/notifications/read-all
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->appNotifications()->unread()->update(['is_read' => true]);

        return ApiResponse::success(['unread_count' => 0], 'All caught up.');
    }
}
