@component('mail.layout', [
    'subjectLine' => 'Your subscription is active',
    'preview' => 'You can quote on every load from now until ' . ($subscription->ends_on?->format('j F Y') ?? 'further notice') . '.',
])

    <h1 style="margin:0 0 14px;font-size:21px;line-height:1.3;font-weight:700;color:#0f172a;letter-spacing:-0.02em;">
        You're subscribed
    </h1>

    <p style="margin:0 0 18px;font-size:15px;line-height:1.65;color:#475569;">
        Thanks — your payment is confirmed and you can quote on every load on the board.
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc;border-radius:10px;padding:16px 18px;">
        <tr>
            <td style="font-size:14px;line-height:1.9;color:#475569;">
                <strong style="color:#0f172a;">{{ $plan?->name ?? 'Subscription' }}</strong><br>
                @if ($plan && (float) $plan->price > 0)
                    Paid: ${{ number_format((float) $plan->price, 2) }} {{ $plan->currency ?? 'AUD' }}<br>
                @endif
                Runs: {{ $subscription->starts_on?->format('j M Y') }} &rarr; {{ $subscription->ends_on?->format('j M Y') }}
                @if ($subscription->gateway_reference)
                    <br>Reference: {{ $subscription->gateway_reference }}
                @endif
            </td>
        </tr>
    </table>

    @component('mail.components.button', ['url' => $url])
        Find loads
    @endcomponent

    <p style="margin:16px 0 0;font-size:13px;line-height:1.6;color:#94a3b8;">
        Cancel any time from your subscription page — you keep access to the end of the period you have paid for.
    </p>

@endcomponent
