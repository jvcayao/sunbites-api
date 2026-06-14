<x-emails.layout title="Low Wallet Balance Alert">
    <h2 style="margin:0 0 16px;font-size:22px;font-weight:700;color:#1a1a1a;">
        Low Wallet Balance Alert
    </h2>

    <p style="margin:0 0 16px;">Hi {{ $parent->first_name }},</p>

    <p style="margin:0 0 20px;">
        <strong>{{ $student->full_name }}</strong>'s canteen wallet balance has dropped below your alert threshold.
    </p>

    <div style="background-color:#fef2f2;border-left:4px solid #dc2626;border-radius:0 6px 6px 0;padding:16px;margin:0 0 20px;">
        <div style="padding:6px 0;">
            <span style="color:#6b7280;font-size:13px;">Current Balance</span>
            <span style="display:block;font-weight:700;font-size:18px;color:#dc2626;">
                &#8369;{{ number_format($currentBalance, 2) }}
            </span>
        </div>
        <div style="padding:6px 0;border-top:1px solid #fecaca;margin-top:6px;">
            <span style="color:#6b7280;font-size:13px;">Alert Threshold</span>
            <span style="display:block;font-weight:600;color:#1a1a1a;">
                &#8369;{{ number_format($threshold, 2) }}
            </span>
        </div>
    </div>

    <p style="margin:0 0 24px;">
        Please arrange a wallet top-up at the canteen or contact the school.
    </p>

    <div style="text-align:center;margin:28px 0;">
        <a href="{{ $portalUrl }}"
           style="background-color:#dc2626;color:#ffffff;padding:12px 28px;border-radius:6px;text-decoration:none;display:inline-block;font-weight:600;font-size:15px;">
            View Parent Portal
        </a>
    </div>
</x-emails.layout>
