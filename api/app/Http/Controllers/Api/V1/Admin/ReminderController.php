<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionReminder;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The record of which carrier was sent which reminder, and when.
 *
 * Read-only, and deliberately so. Nothing here should be editable: this is the
 * answer to "did we contact them, and when" — a question that stops meaning
 * anything the moment someone can adjust it after the fact. Resending is not
 * offered for the same reason; the sweep decides what goes out, so a button
 * here would create a send with no milestone behind it.
 *
 * It lists suppressed rows alongside delivered ones. A carrier asking "why did
 * I never hear from you" is answered by a row either way, and "we reached that
 * milestone and chose not to email" is a different answer from "we never got
 * that far".
 */
class ReminderController extends Controller
{
    /**
     * GET /api/v1/admin/reminders
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kind' => ['nullable', 'string', 'in:expiring,expired'],
            'status' => ['nullable', 'string', 'in:sent,suppressed'],
            'q' => ['nullable', 'string', 'max:120'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $reminders = SubscriptionReminder::query()
            ->with([
                'user:id,name,email',
                'user.profile:id,user_id,company_name',
                'subscription:id,subscription_plan_id,starts_on,ends_on,status',
                'subscription.plan:id,name',
            ])
            ->when($validated['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))
            ->when(
                ($validated['status'] ?? null) === 'sent',
                fn ($q) => $q->whereNotNull('sent_at'),
            )
            ->when(
                ($validated['status'] ?? null) === 'suppressed',
                fn ($q) => $q->whereNull('sent_at'),
            )
            // Searching the carrier, not the reminder: "who did we email" is
            // the question this page gets asked.
            ->when($validated['q'] ?? null, fn ($q, $term) => $q->whereHas(
                'user',
                fn ($u) => $u->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
            ))
            ->when($validated['from'] ?? null, fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($validated['to'] ?? null, fn ($q, $to) => $q->whereDate('created_at', '<=', $to))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return ApiResponse::success([
            'items' => array_map(fn (SubscriptionReminder $r) => [
                'id' => $r->id,
                'kind' => $r->kind,
                'milestone' => $r->milestone,
                'label' => $r->label(),
                'sent_at' => $r->sent_at?->toIso8601String(),
                'skip_reason' => $r->skip_reason,
                'recorded_at' => $r->created_at?->toIso8601String(),
                'carrier' => [
                    'id' => $r->user?->id,
                    'name' => $r->user?->profile?->company_name ?: $r->user?->name,
                    'email' => $r->user?->email,
                ],
                'subscription' => [
                    'id' => $r->subscription?->id,
                    'plan' => $r->subscription?->plan?->name,
                    'ends_on' => $r->subscription?->ends_on?->toDateString(),
                    'status' => $r->subscription?->status,
                ],
            ], $reminders->items()),
            'summary' => $this->summary(),
            'meta' => [
                'current_page' => $reminders->currentPage(),
                'last_page' => $reminders->lastPage(),
                'per_page' => $reminders->perPage(),
                'total' => $reminders->total(),
            ],
        ]);
    }

    /**
     * Totals across the whole ledger, not the current page.
     *
     * Unfiltered on purpose: the point of the strip is to say what the system
     * has done overall, and a figure that moved with the filters would be read
     * as the overall one anyway.
     *
     * @return array<string, int|string|null>
     */
    private function summary(): array
    {
        return [
            'sent_total' => SubscriptionReminder::delivered()->count(),
            'sent_expiring' => SubscriptionReminder::delivered()
                ->where('kind', SubscriptionReminder::KIND_EXPIRING)->count(),
            'sent_expired' => SubscriptionReminder::delivered()
                ->where('kind', SubscriptionReminder::KIND_EXPIRED)->count(),
            'suppressed' => SubscriptionReminder::whereNull('sent_at')->count(),
            'carriers_reached' => SubscriptionReminder::delivered()->distinct('user_id')->count('user_id'),
            'last_sent_at' => SubscriptionReminder::delivered()->max('sent_at'),
        ];
    }
}
