@component('mail.layout', [
    'subjectLine' => 'New load: ' . $lane,
    'preview' => 'Quote before someone else does — the board rewards the first credible price.',
])

    <h1 style="margin:0 0 14px;font-size:21px;line-height:1.3;font-weight:700;color:#0f172a;letter-spacing:-0.02em;">
        {{ $lane }}
    </h1>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc;border-radius:10px;">
        <tr>
            <td style="padding:16px 18px;font-size:14px;line-height:1.9;color:#475569;">
                <strong style="color:#0f172a;">{{ $job->title }}</strong><br>
                @if ($job->load_category)
                    {{ $job->load_category }}@if ($job->vehicle_type_required) &middot; {{ $job->vehicle_type_required }}@endif<br>
                @endif
                @if ($job->weightTons())
                    Weight: {{ number_format($job->weightTons(), 2) }} t<br>
                @endif
                @if ($job->dimensionsLabel())
                    Size: {{ $job->dimensionsLabel() }}<br>
                @endif
                @if ($job->pickup_date)
                    Ready: {{ $job->pickup_date->format('j M Y') }}
                @elseif ($job->availability)
                    Availability: {{ $job->availability }}
                @endif
            </td>
        </tr>
    </table>

    @component('mail.components.button', ['url' => $url])
        Quote on this load
    @endcomponent

    {{-- Required, and it has to work without a login: someone who wants these
         to stop will not sign in to stop them, and a link that asks them to is
         a spam complaint instead. --}}
    <p style="margin:20px 0 0;padding-top:14px;border-top:1px solid #e2e8f0;font-size:12px;line-height:1.6;color:#94a3b8;">
        You get these because you carry freight with FreightMove.
        <a href="{{ $unsubscribeUrl }}" style="color:#64748b;text-decoration:underline;">Stop load alerts</a>
        — your account and quotes are unaffected.
    </p>

@endcomponent
