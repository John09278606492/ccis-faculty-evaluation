@php
    $logoPath = public_path('images/bisu/bisu-seal.png');
    $logo = isset($message) && is_file($logoPath) ? $message->embed($logoPath) : asset('images/bisu/bisu-seal.png');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $headline }}</title>
</head>
<body style="margin:0;padding:0;background:#eef2f9;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1f2937;">
<span style="display:none;max-height:0;overflow:hidden;opacity:0;">{{ $senderLine }} {{ $remaining }} {{ \Illuminate\Support\Str::plural('evaluation', $remaining) }} left.</span>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef2f9;padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 18px rgba(18,48,107,.12);">
                <tr>
                    <td style="background:#12306b;background-image:linear-gradient(135deg,#12306b 0%,#1d4ed8 100%);padding:28px 24px 22px;text-align:center;">
                        <img src="{{ $logo }}" width="76" height="76" alt="BISU seal" style="display:block;margin:0 auto 12px;width:76px;height:76px;border-radius:50%;background:#ffffff;">
                        <div style="color:#ffffff;font-size:15px;font-weight:700;letter-spacing:.4px;">BOHOL ISLAND STATE UNIVERSITY</div>
                        <div style="color:#c7d6ff;font-size:13px;margin-top:2px;">{{ $campusName ?: 'Balilihan Campus' }} · Faculty Evaluation</div>
                    </td>
                </tr>
                <tr>
                    <td style="height:5px;background:#f2b705;font-size:0;line-height:0;">&nbsp;</td>
                </tr>
                <tr>
                    <td style="padding:28px 28px 8px;">
                        <h1 style="margin:0 0 14px;font-size:22px;line-height:1.3;color:#12306b;">{{ $headline }}</h1>
                        <p style="margin:0 0 12px;font-size:15px;line-height:1.6;">Hi {{ $firstName }},</p>
                        <p style="margin:0 0 12px;font-size:15px;line-height:1.6;"><strong>{{ $senderLine }}</strong></p>
                        @if(filled($body))
                            <p style="margin:0 0 12px;font-size:15px;line-height:1.6;">{!! nl2br(e($body)) !!}</p>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td style="padding:6px 28px 4px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fff8e1;border:1px solid #f6d77a;border-radius:12px;">
                            <tr>
                                <td style="padding:16px 18px;text-align:center;">
                                    <div style="font-size:34px;font-weight:800;color:#b45309;line-height:1;">{{ $remaining }}</div>
                                    <div style="font-size:14px;color:#92400e;margin-top:4px;">{{ \Illuminate\Support\Str::plural('evaluation', $remaining) }} still waiting for you</div>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:22px 28px 6px;">
                        <a href="{{ $link }}" style="display:inline-block;background:#1d4ed8;color:#ffffff;text-decoration:none;font-size:16px;font-weight:700;padding:14px 30px;border-radius:999px;">Complete my evaluation</a>
                    </td>
                </tr>
                <tr>
                    <td style="padding:10px 28px 24px;text-align:center;font-size:13px;line-height:1.6;color:#6b7280;">
                        @if($studentId)
                            Sign in with your student ID <strong style="color:#374151;">{{ $studentId }}</strong>.<br>
                        @endif
                        Button not working? Open <a href="{{ $link }}" style="color:#1d4ed8;">{{ $link }}</a>
                    </td>
                </tr>
                <tr>
                    <td style="background:#f8fafc;border-top:1px solid #e5e7eb;padding:16px 28px;text-align:center;font-size:12px;line-height:1.6;color:#6b7280;">
                        🔒 Your ratings and comments are anonymous. Your instructors never see who answered.<br>
                        This message was sent by the Faculty Evaluation system of Bohol Island State University.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
