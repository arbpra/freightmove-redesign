@component('mail.layout', [
    'subjectLine' => 'Your load is live',
    'preview' => 'Carriers on this lane can see it now.',
])

    <h1 style="margin:0 0 14px;font-size:21px;line-height:1.3;font-weight:700;color:#0f172a;letter-spacing:-0.02em;">
        Your load is live
    </h1>

    <p style="margin:0 0 18px;font-size:15px;line-height:1.65;color:#475569;">
        Carriers running this lane can see it now. Most loads attract their first quote within the hour.
    </p>

    {{-- The load itself, so the email is a usable record on its own. --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc;border-radius:10px;padding:16px 18px;">
        <tr>
            <td>
                <p style="margin:0 0 6px;font-size:16px;font-weight:650;color:#0f172a;">{{ $job->title }}</p>
                <p style="margin:0;font-size:14px;line-height:1.6;color:#475569;">
                    {{ $job->pickup_location }} &rarr; {{ $job->delivery_location }}
                </p>
                @if ($job->load_category || $job->weight_tons)
                    <p style="margin:6px 0 0;font-size:13px;color:#94a3b8;">
                        {{ $job->load_category }}@if ($job->load_category && $job->weight_tons) &middot; @endif
                        @if ($job->weight_tons){{ (float) $job->weight_tons }} t@endif
                    </p>
                @endif
            </td>
        </tr>
    </table>

    @component('mail.components.button', ['url' => $url])
        View quotes
    @endcomponent

    <p style="margin:16px 0 0;font-size:13px;line-height:1.6;color:#94a3b8;">
        We will email you when the first quote arrives.
    </p>

@endcomponent
