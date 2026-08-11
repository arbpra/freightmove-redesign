<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Subscriptions waiting on payment.
 *
 * Under the manual gateway this is how money gets recognised: a carrier
 * reserves a plan, pays by whatever means was agreed, and an admin confirms it
 * here. When a real gateway is wired in it calls the same service method from
 * its webhook, and this queue becomes the exception path rather than the
 * normal one.
 */
class SubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /**
     * GET /api/v1/admin/subscriptions
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:pending,active,expired,cancelled'],
        ]);

        $status = $validated['status'] ?? 'pending';

        $subscriptions = Subscription::with(['user:id,name,email', 'user.profile:id,user_id,company_name', 'plan'])
            ->where('status', $status)
            ->oldest()
            ->paginate(25)
            ->withQueryString();

        return ApiResponse::success([
            'items' => array_map(fn (Subscription $s) => [
                'id' => $s->id,
                'status' => $s->status,
                'plan' => $s->plan?->name,
                'amount' => (float) ($s->plan?->price ?? 0),
                'starts_on' => $s->starts_on?->toDateString(),
                'ends_on' => $s->ends_on?->toDateString(),
                'carrier' => [
                    'id' => $s->user?->id,
                    'name' => $s->user?->profile?->company_name ?: $s->user?->name,
                    'email' => $s->user?->email,
                ],
                'requested_at' => $s->created_at?->toIso8601String(),
            ], $subscriptions->items()),
            'meta' => [
                'current_page' => $subscriptions->currentPage(),
                'last_page' => $subscriptions->lastPage(),
                'per_page' => $subscriptions->perPage(),
                'total' => $subscriptions->total(),
            ],
        ]);
    }

    /**
     * POST /api/v1/admin/subscriptions/{subscription}/confirm
     */
    public function confirm(Request $request, Subscription $subscription): JsonResponse
    {
        $validated = $request->validate([
            // Whatever identifies the payment on the other side: a bank
            // reference, a PayPal transaction id, an invoice number.
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        if ($subscription->status !== 'pending') {
            return ApiResponse::error('That subscription is not waiting on payment.', status: 422);
        }

        $confirmed = $this->subscriptions->confirmPayment($subscription, $validated['reference'] ?? null);

        return ApiResponse::success([
            'status' => $confirmed->status,
            'starts_on' => $confirmed->starts_on?->toDateString(),
            'ends_on' => $confirmed->ends_on?->toDateString(),
        ], 'Payment confirmed and the subscription is running.');
    }
}
