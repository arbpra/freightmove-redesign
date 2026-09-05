@component('mail.layout', [
    'subjectLine' => 'New load posted',
    'preview' => ($shipper?->profile?->company_name ?: $shipper?->name) . ' posted ' . $job->title . '.',
])

    <h1 style="margin:0 0 14px;font-size:21px;line-height:1.3;font-weight:700;color:#0f172a;letter-spacing:-0.02em;">
        New load posted
    </h1>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc;border-radius:10px;">
        <tr>
            <td style="padding:16px 18px;font-size:14px;line-height:1.9;color:#475569;">
                <strong style="color:#0f172a;">{{ $job->title }}</strong><br>
                {{ $job->pickup_location }} &rarr; {{ $job->delivery_location }}<br>
                Posted by: {{ $shipper?->profile?->company_name ?: $shipper?->name }}
                ({{ $shipper?->email }})<br>
                Load #{{ $job->id }}
            </td>
        </tr>
    </table>

    {{-- The fan-out is the part worth seeing: this one post generates that
         many messages, and the number is how you notice it changing. --}}
    <p style="margin:16px 0 0;font-size:15px;line-height:1.65;color:#475569;">
        @if ($carriersNotified > 0)
            Alerted <strong>{{ $carriersNotified }}</strong>
            {{ $carriersNotified === 1 ? 'carrier' : 'carriers' }}.
        @else
            No carrier alerts were sent — load alerts are switched off.
        @endif
    </p>

    @component('mail.components.button', ['url' => $url])
        Open the load
    @endcomponent

@endcomponent
