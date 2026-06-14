<x-emails.layout title="Enrollment Approved!">
    <h2 style="margin:0 0 16px;font-size:22px;font-weight:700;color:#1a1a1a;">
        Enrollment Approved! &#127881;
    </h2>

    <p style="margin:0 0 16px;">Hi {{ $preRegistration->signatory_name }},</p>

    <p style="margin:0 0 20px;">
        Great news! We are pleased to inform you that the pre-registration for
        <strong>{{ $preRegistration->full_name }}</strong> has been approved and
        they are now enrolled in our canteen program.
    </p>

    <div style="background-color:#fef2f2;border-left:4px solid #dc2626;border-radius:0 6px 6px 0;padding:16px;margin:0 0 20px;">
        <div style="padding:6px 0;">
            <span style="color:#6b7280;display:block;font-size:13px;">Student Name</span>
            <span style="color:#1a1a1a;font-weight:600;">{{ $preRegistration->full_name }}</span>
        </div>
        <div style="padding:6px 0;border-top:1px solid #fecaca;margin-top:6px;">
            <span style="color:#6b7280;display:block;font-size:13px;">Student Number</span>
            <span style="color:#1a1a1a;font-weight:600;">{{ $student->student_number ?? 'To be assigned' }}</span>
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

    <p style="margin:0 0 16px;">
        If a parent portal account was set up, you will receive a separate email with instructions to activate it.
    </p>

    <p style="margin:0;color:#6b7280;">
        We look forward to serving <strong>{{ $preRegistration->first_name }}</strong>. Welcome to Sunbites Kitchen!
    </p>
</x-emails.layout>
