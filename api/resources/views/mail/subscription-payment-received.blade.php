@component('mail.layout', [
    'subjectLine' => 'Payment received',
    'preview' => ($company ?: $carrier?->name) . ' paid for ' . ($plan?->name ?? 'a subscription') . '.',
])

    <h1 style="margin:0 0 14px;font-size:21px;line-height:1.3;font-weight:700;color:#0f172a;letter-spacing:-0.02em;">
        Payment received
    </h1>

    <p style="margin:0 0 18px;font-size:15px;line-height:1.65;color:#475569;">
        The subscription is already active — nothing needs doing. This is the
        record.
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;">
        <tr>
            <td style="padding:16px 18px;">
                <p style="margin:0 0 4px;font-size:26px;font-weight:800;letter-spacing:-0.02em;color:#065f46;">
                    ${{ number_format((float) ($plan->price ?? 0), 2) }}
                    <span style="font-size:14px;font-weight:600;color:#047857;">{{ $plan->currency ?? 'AUD' }}</span>
                </p>
                <p style="margin:0;font-size:14px;line-height:1.6;color:#047857;">
                    {{ $plan?->name ?? 'Subscription' }} &middot; paid by {{ $gateway === 'paypal' ? 'PayPal' : 'manual confirmation' }}
                </p>
            </td>
        </tr>
    </table>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:14px;background-color:#f8fafc;border-radius:10px;padding:16px 18px;">
        <tr>
            <td style="font-size:14px;line-height:1.9;color:#475569;">
                <strong style="color:#0f172a;">{{ $company ?: $carrier?->name }}</strong><br>
                @if ($company && $carrier?->name)
                    {{ $carrier->name }}<br>
                @endif
                {{ $carrier?->email }}<br>
                Covers: {{ $subscription->starts_on?->format('j M Y') }} &rarr; {{ $subscription->ends_on?->format('j M Y') }}
                @if ($reference)
                    {{-- The handle to quote at PayPal if this is ever queried or refunded. --}}
                    <br>Reference: <span style="font-family:monospace;font-size:13px;">{{ $reference }}</span>
                @endif
            </td>
        </tr>
    </table>

    @component('mail.components.button', ['url' => $url])
        Open the payment record
    @endcomponent

    <p style="margin:16px 0 0;font-size:13px;line-height:1.6;color:#94a3b8;">
        Sent to the address in <span style="font-family:monospace;">FM_PAYMENT_RECIPIENT</span>.
        Refunds are done in PayPal, not here — one made there arrives on the webhook.
    </p>

@endcomponent
