<x-mail::message>
# Pre-Registration Received

Hi {{ $preRegistration->signatory_name }},

Thank you for submitting a pre-registration for **{{ $preRegistration->full_name }}**!

We have received your request and our canteen staff will review the details shortly. Once reviewed, we will contact you to confirm enrollment or provide any additional instructions.

**Submission Summary:**
- **Student Name:** {{ $preRegistration->full_name }}
- **Grade Level:** {{ $preRegistration->grade_level }}
- **Enrollment Type:** {{ ucfirst(str_replace('_', '-', $preRegistration->enrollment_type)) }}

No further action is needed at this time.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
