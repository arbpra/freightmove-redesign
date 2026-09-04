<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPayment;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Every payment taken for a subscription, whatever took it.
 *
 * The previous site had this as `myadmin/payment_transaction` and it is the one
 * admin surface with no equivalent here — which meant $5,775.23 of imported
 * PayPal history, 69 transactions going back to March 2024, existed in the
 * database with no screen that could show it.
 *
 * Read-only. A payment record describes something that happened at a payment
 * provider; editing it here would only make the two disagree, and the copy
 * that is wrong would be the one being read.
 *
 * Refunds are not offered either. They belong in PayPal, where the money is —
 * a refund button that marks a row without moving funds is worse than no
 * button, because it reports success. Refunds performed there arrive on the
 * webhook and land here on their own.
 */
class PaymentController extends Controller
{
    /**
     * GET /api/v1/admin/payments
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'max:32'],
            'gateway' => ['nullable', 'string', 'in:paypal,manual'],
            'q' => ['nullable', 'string', 'max:120'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $payments = SubscriptionPayment::query()
            ->with([
                'user:id,name,email',
                'user.profile:id,user_id,company_name',
                'plan:id,name,code',
            ])
            ->when($validated['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($validated['gateway'] ?? null, fn ($q, $g) => $q->where('gateway', $g))
            // The payer, not the payment: "who paid us" is what this gets
            // asked. `payer_email` is searched as well as the account address
            // because PayPal reports whatever address the payer used, which is
            // routinely not the one they signed up with.
            ->when($validated['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('payer_name', 'like', "%{$term}%")
                ->orWhere('payer_email', 'like', "%{$term}%")
                ->orWhere('gateway_reference', 'like', "%{$term}%")
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%"))))
            ->when($validated['from'] ?? null, fn ($q, $from) => $q->whereDate('paid_at', '>=', $from))
            ->when($validated['to'] ?? null, fn ($q, $to) => $q->whereDate('paid_at', '<=', $to))
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return ApiResponse::success([
            'items' => array_map(fn (SubscriptionPayment $p) => [
                'id' => $p->id,
                'gateway' => $p->gateway,
                'reference' => $p->gateway_reference,
                'amount' => (float) $p->amount,
                'currency' => $p->currency ?? 'AUD',
                'status' => $p->status,
                'paid_at' => $p->paid_at?->toIso8601String(),
                'plan' => $p->plan?->name,
                'payer' => [
                    'name' => $p->payer_name,
                    'email' => $p->payer_email,
                ],
                'carrier' => [
                    'id' => $p->user?->id,
                    'name' => $p->user?->profile?->company_name ?: $p->user?->name,
                    'email' => $p->user?->email,
                ],
                // Imported from the previous site rather than taken here. Worth
                // showing: it is the difference between "we processed this" and
                // "this is history we inherited".
                'is_legacy' => $p->legacy_id !== null,
            ], $payments->items()),
            'summary' => $this->summary(),
            'meta' => [
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
            ],
        ]);
    }

    /**
     * Totals across every payment, not the current page.
     *
     * Only `completed` counts toward money. A pending row is a plan someone
     * reserved and has not paid for, and adding it to a revenue figure would
     * report income that does not exist.
     *
     * @return array<string, mixed>
     */
    private function summary(): array
    {
        $completed = SubscriptionPayment::completed();

        return [
            'collected' => round((float) $completed->clone()->sum('amount'), 2),
            'collected_paypal' => round((float) $completed->clone()->where('gateway', 'paypal')->sum('amount'), 2),
            'collected_manual' => round((float) $completed->clone()->where('gateway', 'manual')->sum('amount'), 2),
            'payments' => $completed->clone()->count(),
            'awaiting' => SubscriptionPayment::where('status', 'pending')->count(),
            'payers' => $completed->clone()->distinct('user_id')->count('user_id'),
            'last_paid_at' => $completed->clone()->max('paid_at'),
        ];
    }
}
