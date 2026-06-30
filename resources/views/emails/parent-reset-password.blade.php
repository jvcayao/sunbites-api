<x-emails.layout title="Reset your password">
    <h2 style="margin:0 0 16px;font-size:22px;font-weight:700;color:#1a1a1a;">
        Reset your password
    </h2>

    <p style="margin:0 0 16px;">Hi {{ $firstName }},</p>

    <p style="margin:0 0 20px;">
        We received a request to reset the password for your Sunbites Parent Portal account.
        Click the button below to set a new password.
    </p>

    <div style="text-align:center;margin:28px 0;">
        <a href="{!! $resetUrl !!}"
           style="background-color:#dc2626;color:#ffffff;padding:12px 28px;border-radius:6px;text-decoration:none;display:inline-block;font-weight:600;font-size:15px;">
            Reset Password
        </a>
    </div>

    <p style="margin:0 0 16px;color:#6b7280;font-size:13px;">
        This reset link will expire in <strong>60 minutes</strong>.
    </p>

    <p style="margin:0;color:#6b7280;font-size:13px;">
        If you did not request a password reset, no further action is required.
    </p>
</x-emails.layout>
