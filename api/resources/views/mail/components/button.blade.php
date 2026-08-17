{{--
    A call-to-action button.

    Built as a table rather than a styled <a>, because Outlook ignores padding
    on inline elements and would render a bare link. `mso-padding-alt` and the
    inner spacing keep it a button there too.
--}}
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:22px 0 6px;">
    <tr>
        <td style="border-radius:10px;background-color:#e11d26;mso-padding-alt:14px 26px;">
            <a href="{{ $url }}"
               style="display:inline-block;padding:13px 26px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:10px;">
                {{ $slot }}
            </a>
        </td>
    </tr>
</table>
