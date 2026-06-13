<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Sunbites' }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f3f4f6;font-family:Arial,Helvetica,sans-serif;">
    <div style="background-color:#f3f4f6;padding:32px 16px;">
        <div style="max-width:600px;margin:0 auto;">

            {{-- Header --}}
            <div style="background-color:#dc2626;padding:28px 32px;text-align:center;border-radius:8px 8px 0 0;">
                <img src="{{ asset('images/sunbites.png') }}"
                     alt="Sunbites"
                     height="48"
                     style="height:48px;width:auto;display:block;margin:0 auto;">
            </div>

            {{-- Card body --}}
            <div style="background-color:#ffffff;padding:32px;color:#1a1a1a;font-size:15px;line-height:1.6;">
                {{ $slot }}
            </div>

            {{-- Footer --}}
            <div style="background-color:#f9fafb;padding:20px 32px;text-align:center;border-top:1px solid #e5e7eb;border-radius:0 0 8px 8px;">
                <p style="margin:0;color:#9ca3af;font-size:12px;">Sunbites School Canteen Management System</p>
                <p style="margin:4px 0 0;color:#9ca3af;font-size:12px;">&copy; {{ date('Y') }} Sunbites. All rights reserved.</p>
            </div>

        </div>
    </div>
</body>
</html>
