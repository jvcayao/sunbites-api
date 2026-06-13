<x-mail::message>
# Enrollment Approved! 🎉

Hi {{ $preRegistration->signatory_name }},

Great news! We are pleased to inform you that the pre-registration for **{{ $preRegistration->full_name }}** has been approved and they are now enrolled in our canteen program.

**Enrollment Details:**
- **Student Name:** {{ $preRegistration->full_name }}
- **Student Number:** {{ $student->student_number ?? 'To be assigned' }}
- **Grade Level:** {{ $preRegistration->grade_level }}
- **Enrollment Type:** {{ ucfirst(str_replace('_', '-', $preRegistration->enrollment_type)) }}

If a parent portal account was set up, you will receive a separate email with instructions to activate it.

Welcome to Sunbites Kitchen! We look forward to serving {{ $preRegistration->first_name }}.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
