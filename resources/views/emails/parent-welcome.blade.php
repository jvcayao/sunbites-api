<x-emails.layout title="Welcome to Sunbites!">
    <h2 style="margin:0 0 16px;font-size:22px;font-weight:700;color:#1a1a1a;">
        Welcome to Sunbites, {{ $parent->first_name }}!
    </h2>

    <p style="margin:0 0 16px;">
        An account has been created for you on the <strong>Sunbites Parent Portal</strong>.
        Click the button below to activate your account and set your password.
    </p>

    <div style="text-align:center;margin:28px 0;">
        <a href="{{ $activationUrl }}"
           style="background-color:#dc2626;color:#ffffff;padding:12px 28px;border-radius:6px;text-decoration:none;display:inline-block;font-weight:600;font-size:15px;">
            Activate My Account
        </a>
    </div>

    <p style="margin:0;color:#6b7280;font-size:13px;">
        This activation link will expire in <strong>60 minutes</strong>.
        If you did not expect this email, you can safely ignore it.
    </p>
</x-emails.layout>
