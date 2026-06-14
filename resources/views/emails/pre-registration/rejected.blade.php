<x-emails.layout title="Update on Your Pre-Registration">
    <h2 style="margin:0 0 16px;font-size:22px;font-weight:700;color:#1a1a1a;">
        Update on Your Pre-Registration
    </h2>

    <p style="margin:0 0 16px;">Hi {{ $preRegistration->signatory_name }},</p>

    <p style="margin:0 0 20px;">
        Thank you for your interest in enrolling <strong>{{ $preRegistration->full_name }}</strong>
        at Sunbites Kitchen. After reviewing your pre-registration, we are unfortunately unable
        to process it at this time.
    </p>

    <div style="background-color:#fef2f2;border-left:4px solid #dc2626;border-radius:0 6px 6px 0;padding:16px;margin:0 0 20px;">
        <span style="color:#6b7280;display:block;font-size:13px;margin-bottom:6px;">Reason</span>
        <span style="color:#1a1a1a;">{{ $preRegistration->rejection_reason }}</span>
    </div>

    <p style="margin:0;">
        We encourage you to visit the canteen in person or contact us directly so we can assist
        you further. We would be happy to help get <strong>{{ $preRegistration->first_name }}</strong> enrolled.
    </p>
</x-emails.layout>
