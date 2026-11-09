<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $reminder->subject }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f3f6f1;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f3f6f1;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background-color:#ffffff;border:1px solid #d7e2d0;border-radius:12px;overflow:hidden;">
                    <tr>
                        <td style="background:linear-gradient(135deg,#1f5f2b 0%,#2f7d32 55%,#c9a227 100%);padding:24px 28px;color:#ffffff;">
                            <p style="margin:0 0 6px;font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#f6f0d2;">{{ config('app.name', 'SNNHS Sports Activity Hub') }}</p>
                            <h1 style="margin:0;font-size:22px;line-height:1.3;font-weight:700;">{{ $reminder->subject }}</h1>
                            <p style="margin:8px 0 0;font-size:13px;color:#eef7e8;">{{ $reminder->category }}</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px;">
                            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;">Dear {{ $athleteName }},</p>
                            <div style="margin:0 0 20px;font-size:15px;line-height:1.7;color:#374151;white-space:pre-line;">{{ $reminder->message }}</div>

                            @if ($reminder->relevant_date || $reminder->relevant_time)
                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 20px;background-color:#f7faf4;border:1px solid #d7e2d0;border-radius:8px;">
                                    <tr>
                                        <td style="padding:14px 16px;font-size:14px;line-height:1.6;color:#1f2937;">
                                            <strong style="color:#1f5f2b;">Schedule / Date</strong><br>
                                            @if ($reminder->relevant_date)
                                                {{ $reminder->relevant_date->format('F j, Y') }}
                                            @endif
                                            @if ($reminder->relevant_time)
                                                @if ($reminder->relevant_date) &middot; @endif
                                                {{ \Illuminate\Support\Carbon::parse($reminder->relevant_time)->format('g:i A') }}
                                            @endif
                                        </td>
                                    </tr>
                                </table>
                            @endif

                            <p style="margin:0 0 6px;font-size:14px;line-height:1.6;color:#374151;">Sincerely,</p>
                            <p style="margin:0;font-size:14px;line-height:1.6;color:#1f2937;"><strong>School Administration</strong><br>{{ config('app.name', 'SNNHS Sports Activity Hub') }}</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 28px;background-color:#fafcf8;border-top:1px solid #e5ece0;font-size:12px;line-height:1.5;color:#6b7280;">
                            This reminder was sent through {{ config('app.name', 'SNNHS Sports Activity Hub') }}. Please contact the school sports office if you have questions.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
