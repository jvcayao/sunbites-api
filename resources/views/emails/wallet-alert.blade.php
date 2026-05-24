<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="font-family:sans-serif;color:#1a1a1a;padding:24px;max-width:600px;margin:0 auto;">
    <h2 style="color:#f97316;">Low Wallet Balance Alert</h2>
    <p>Hi {{ $parent->first_name }},</p>
    <p><strong>{{ $student->full_name }}</strong>'s canteen wallet balance has dropped below your alert threshold.</p>
    <table style="border-collapse:collapse;margin:16px 0;background:#fff7ed;border-radius:8px;padding:16px;width:100%;">
        <tr>
            <td style="padding:6px 16px 6px 16px;color:#6b7280;">Current Balance</td>
            <td style="padding:6px 16px;font-weight:700;color:#dc2626;">₱{{ number_format($currentBalance, 2) }}</td>
        </tr>
        <tr>
            <td style="padding:6px 16px;color:#6b7280;">Alert Threshold</td>
            <td style="padding:6px 16px;">₱{{ number_format($threshold, 2) }}</td>
        </tr>
    </table>
    <p>Please arrange a wallet top-up at the canteen or contact the school.</p>
    <p style="margin:24px 0;">
        <a href="{{ $portalUrl }}" style="background:#f97316;color:#fff;padding:12px 28px;border-radius:6px;text-decoration:none;display:inline-block;font-weight:600;">View Parent Portal</a>
    </p>
    <hr style="border:none;border-top:1px solid #e5e7eb;margin:24px 0;">
    <p style="color:#9ca3af;font-size:12px;">Sunbites School Canteen Management System</p>
</body>
</html>
