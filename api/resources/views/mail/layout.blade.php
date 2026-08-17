{{--
    The shared shell for every transactional email.

    Styles are inline and the layout is a table. That is not carelessness —
    Outlook still renders with Word's HTML engine, Gmail strips <style> blocks
    on forwarded mail, and neither supports flexbox or grid. Tables with inline
    CSS are what actually arrives looking the way it left.

    Colours come from the brand rather than CSS variables, because custom
    properties are unsupported across most mail clients.
--}}
<!DOCTYPE html>
<html lang="en-AU">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $subjectLine ?? 'FreightMove' }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">

    {{-- Preheader: the grey line inboxes show after the subject. Hidden in the
         body itself, so it does not appear twice. --}}
    @isset($preview)
        <div style="display:none;max-height:0;overflow:hidden;opacity:0;">{{ $preview }}</div>
    @endisset

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f1f5f9;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background-color:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 1px 3px rgba(10,28,56,0.08);">

                    {{-- Header --}}
                    <tr>
                        <td style="background-color:#0a1c38;padding:22px 28px;">
                            <span style="font-size:20px;font-weight:800;letter-spacing:-0.02em;color:#ffffff;">FREIGHT<span style="color:#f5323b;">MOVE</span></span>
                        </td>
                    </tr>

                    {{-- A thin brand rule, the same device the site header uses. --}}
                    <tr><td style="height:3px;background-color:#e11d26;font-size:0;line-height:0;">&nbsp;</td></tr>

                    {{-- Body --}}
                    <tr>
                        <td style="padding:30px 28px 26px;">
                            {{ $slot }}
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="padding:20px 28px 26px;border-top:1px solid #e2e8f0;">
                            <p style="margin:0 0 6px;font-size:13px;line-height:1.6;color:#64748b;">
                                FreightMove — Australia's freight marketplace
                            </p>
                            <p style="margin:0;font-size:12px;line-height:1.6;color:#94a3b8;">
                                You are receiving this because you have a FreightMove account.
                                Manage what we send you from your dashboard.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
