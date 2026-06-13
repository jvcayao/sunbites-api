<x-emails.layout title="Pre-Registration Received">
    <h2 style="margin:0 0 16px;font-size:22px;font-weight:700;color:#1a1a1a;">
        Pre-Registration Received!
    </h2>

    <p style="margin:0 0 16px;">Hi {{ $preRegistration->signatory_name }},</p>

    <p style="margin:0 0 20px;">
        Thank you for submitting a pre-registration for <strong>{{ $preRegistration->full_name }}</strong>!
        We have received your request and our canteen staff will review the details shortly.
    </p>

    <div style="background-color:#fef2f2;border-left:4px solid #dc2626;border-radius:0 6px 6px 0;padding:16px;margin:0 0 20px;">
        <div style="padding:6px 0;">
            <span style="color:#6b7280;display:block;font-size:13px;">Student Name</span>
            <span style="color:#1a1a1a;font-weight:600;">{{ $preRegistration->full_name }}</span>
        </div>
        <div style="padding:6px 0;border-top:1px solid #fecaca;margin-top:6px;">
            <span style="color:#6b7280;display:block;font-size:13px;">Grade Level</span>
            <span style="color:#1a1a1a;font-weight:600;">{{ $preRegistration->grade_level }}</span>
        </div>
        <div style="padding:6px 0;border-top:1px solid #fecaca;margin-top:6px;">
            <span style="color:#6b7280;display:block;font-size:13px;">Enrollment Type</span>
            <span style="color:#1a1a1a;font-weight:600;">{{ ucfirst(str_replace('_', '-', $preRegistration->enrollment_type)) }}</span>
        </div>
    </div>

    <p style="margin:0;color:#6b7280;">
        No further action is needed at this time. We will contact you once the review is complete.
    </p>
</x-emails.layout>
