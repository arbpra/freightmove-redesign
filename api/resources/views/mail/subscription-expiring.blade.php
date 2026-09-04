@component('mail.layout', [
    'subjectLine' => 'Your subscription ends ' . $countdown,
    'preview' => 'Renew before ' . ($subscription->ends_on?->format('j F') ?? 'it ends') . ' to keep quoting without a break.',
])

    <h1 style="margin:0 0 14px;font-size:21px;line-height:1.3;font-weight:700;color:#0f172a;letter-spacing:-0.02em;">
        Your subscription ends {{ $countdown }}
    </h1>

    <p style="margin:0 0 18px;font-size:15px;line-height:1.65;color:#475569;">
        Renewing before then keeps your access unbroken — a new period starts the
        day after this one finishes, so you never lose a day you have paid for.
    </p>

    {{-- The countdown strip. Amber rather than red: this is a deadline, not a
         failure, and red here would read the same as "your payment declined". --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#fffbeb;border:1px solid #fde68a;border-radius:10px;">
        <tr>
            <td style="padding:16px 18px;">
                <p style="margin:0 0 4px;font-size:12px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#b45309;">
                    @if ($daysLeft <= 0) Last day @else {{ $daysLeft }} {{ $daysLeft === 1 ? 'day' : 'days' }} left @endif
                </p>
                <p style="margin:0;font-size:15px;line-height:1.6;color:#78350f;">
                    <strong>{{ $plan?->name ?? 'Your subscription' }}</strong> runs until
                    <strong>{{ $subscription->ends_on?->format('l j F Y') }}</strong>.
                </p>
            </td>
        </tr>
    </table>

    @component('mail.components.button', ['url' => $url])
        Renew now
    @endcomponent

    <p style="margin:16px 0 0;font-size:13px;line-height:1.6;color:#94a3b8;">
        Already renewed? Then you can ignore this — we only send it while the
        current period is still the latest one on your account.
    </p>

@endcomponent
