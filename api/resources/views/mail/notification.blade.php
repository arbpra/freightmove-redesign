{{-- An in-app notification, delivered by email. Same words in both places. --}}
@component('mail.layout', ['subjectLine' => $notification->title, 'preview' => $notification->body])

    <h1 style="margin:0 0 14px;font-size:21px;line-height:1.3;font-weight:700;color:#0f172a;letter-spacing:-0.02em;">
        {{ $notification->title }}
    </h1>

    @if ($notification->body)
        <p style="margin:0 0 4px;font-size:15px;line-height:1.65;color:#475569;">
            {{ $notification->body }}
        </p>
    @endif

    @if ($url)
        @component('mail.components.button', ['url' => $url])
            {{ $action }}
        @endcomponent

        {{-- Some clients strip the button; the plain URL is the fallback. --}}
        <p style="margin:14px 0 0;font-size:12px;line-height:1.6;color:#94a3b8;word-break:break-all;">
            Or paste this into your browser:<br>{{ $url }}
        </p>
    @endif

@endcomponent
