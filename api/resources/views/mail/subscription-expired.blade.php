@component('mail.layout', [
    'subjectLine' => $longLapsed ? 'Come back when you need a load' : 'Your subscription has ended',
    'preview' => $longLapsed
        ? 'Your account is still here whenever you want to quote again.'
        : ($gated
            ? 'Renew to start quoting on loads again.'
            : 'Renew whenever you are ready — your account is still open.'),
])

    <h1 style="margin:0 0 14px;font-size:21px;line-height:1.3;font-weight:700;color:#0f172a;letter-spacing:-0.02em;">
        {{ $longLapsed ? 'Still here when you need us' : 'Your subscription has ended' }}
    </h1>

    @if ($longLapsed)
        {{-- A monthly follow-up. Someone this far past the end date has made a
             decision, and writing as though they simply forgot reads as
             nagging — which is what gets a sender marked as spam. --}}
        <p style="margin:0 0 18px;font-size:15px;line-height:1.65;color:#475569;">
            Your <strong>{{ $plan?->name ?? 'subscription' }}</strong> ended
            {{ $elapsed }}, on {{ $subscription->ends_on?->format('j F Y') }}.
            Nothing has been deleted — your profile, history and ratings are
            exactly as you left them, and picking back up takes one click.
        </p>
    @else
        <p style="margin:0 0 18px;font-size:15px;line-height:1.65;color:#475569;">
            <strong>{{ $plan?->name ?? 'Your subscription' }}</strong> ended
            {{ $elapsed }}, on
            <strong>{{ $subscription->ends_on?->format('l j F Y') }}</strong>.
        </p>

        @if ($gated)
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#fef2f2;border:1px solid #fecaca;border-radius:10px;">
                <tr>
                    <td style="padding:16px 18px;font-size:15px;line-height:1.6;color:#7f1d1d;">
                        Quoting is paused until you renew. Your account, profile
                        and history are untouched and come straight back with it.
                    </td>
                </tr>
            </table>
        @else
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
                <tr>
                    <td style="padding:16px 18px;font-size:15px;line-height:1.6;color:#475569;">
                        You can still quote on loads for now — nothing has been
                        taken away. Renewing keeps it that way and supports the
                        board you are quoting on.
                    </td>
                </tr>
            </table>
        @endif
    @endif

    @component('mail.components.button', ['url' => $url])
        {{ $longLapsed ? 'Start quoting again' : ($gated ? 'Renew and start quoting' : 'Renew your subscription') }}
    @endcomponent

    <p style="margin:16px 0 0;font-size:13px;line-height:1.6;color:#94a3b8;">
        @if ($longLapsed)
            We send this once a month and nothing else. Reply with "stop" and we will leave you alone.
        @else
            You will hear from us a couple more times about this, then once a month at most.
        @endif
    </p>

@endcomponent
