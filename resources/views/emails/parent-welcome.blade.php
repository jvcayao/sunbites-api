<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="font-family:sans-serif;color:#1a1a1a;padding:24px;max-width:600px;margin:0 auto;">
    <h2 style="color:#f97316;">Welcome to Sunbites, {{ $parent->first_name }}!</h2>
    <p>An account has been created for you on the <strong>Sunbites Parent Portal</strong>. Please click the button below to activate your account and set your password.</p>
    <p style="margin:24px 0;">
        <a href="{{ $activationUrl }}" style="background:#f97316;color:#fff;padding:12px 28px;border-radius:6px;text-decoration:none;display:inline-block;font-weight:600;">Activate My Account</a>
    </p>
    <p style="color:#6b7280;font-size:13px;">This activation link will expire in <strong>60 minutes</strong>. If you did not expect this email, you can safely ignore it.</p>
    <hr style="border:none;border-top:1px solid #e5e7eb;margin:24px 0;">
    <p style="color:#9ca3af;font-size:12px;">Sunbites School Canteen Management System</p>
</body>
</html>
