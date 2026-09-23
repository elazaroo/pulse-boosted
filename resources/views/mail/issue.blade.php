@php
    $rows = [
        'Application' => $app,
        'Environment' => $environment,
        'First seen' => date('Y-m-d H:i:s', (int) $issue->first_seen_at),
        'Last seen' => date('Y-m-d H:i:s', (int) $issue->last_seen_at),
        'Occurrences' => number_format((int) $issue->occurrences),
        'Location' => $location,
        'Laravel' => $issue->laravel_version,
        'PHP' => $issue->php_version,
    ];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $issue->class }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Inter,Helvetica,Arial,sans-serif;color:#18181b;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border:1px solid #e4e4e7;border-radius:8px;">
                    <tr>
                        <td style="padding:24px 28px 8px;">
                            <p style="margin:0 0 12px;font-size:12px;color:#71717a;">
                                Pulse Boosted &middot; {{ $regressed ? 'An issue marked resolved has happened again' : 'A new issue' }}
                            </p>
                            <p style="margin:0 0 12px;">
                                <span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;{{ $issue->handled ? 'background:#f4f4f5;color:#52525b;' : 'background:#fee2e2;color:#b91c1c;' }}">
                                    {{ $issue->handled ? 'Handled' : 'Unhandled' }}
                                </span>
                                @if ($regressed)
                                    <span style="display:inline-block;margin-left:4px;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;background:#fef3c7;color:#b45309;">Regressed</span>
                                @endif
                            </p>
                            <h1 style="margin:0;font-size:18px;line-height:1.35;font-weight:600;font-family:'JetBrains Mono',Menlo,Consolas,monospace;word-break:break-all;">{{ $issue->class }}</h1>
                            @if ($issue->message)
                                <p style="margin:8px 0 0;font-size:14px;line-height:1.5;color:#3f3f46;">{{ $issue->message }}</p>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 28px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;">
                                @foreach ($rows as $label => $value)
                                    @if ($value !== null && $value !== '')
                                        <tr>
                                            <td style="padding:6px 0;color:#71717a;width:120px;vertical-align:top;border-bottom:1px solid #f4f4f5;">{{ $label }}</td>
                                            <td style="padding:6px 0;color:#18181b;border-bottom:1px solid #f4f4f5;{{ $label === 'Location' ? "font-family:'JetBrains Mono',Menlo,Consolas,monospace;font-size:12px;word-break:break-all;" : '' }}">{{ $value }}</td>
                                        </tr>
                                    @endif
                                @endforeach
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:8px 28px 28px;">
                            <a href="{{ $url }}" style="display:inline-block;padding:9px 16px;border-radius:6px;background:#4f46e5;color:#ffffff;font-size:13px;font-weight:600;text-decoration:none;">Open the issue</a>
                        </td>
                    </tr>
                </table>
                <p style="margin:16px 0 0;font-size:11px;color:#a1a1aa;">
                    You get this because your address is in <code>pulse-boosted.issues.notify.mail</code>.
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
