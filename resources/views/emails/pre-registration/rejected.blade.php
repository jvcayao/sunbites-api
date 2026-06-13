<x-mail::message>
# Update on Your Pre-Registration

Hi {{ $preRegistration->signatory_name }},

Thank you for your interest in enrolling **{{ $preRegistration->full_name }}** at Sunbites Kitchen.

After reviewing your pre-registration, we are unfortunately unable to process it at this time.

**Reason:** {{ $preRegistration->rejection_reason }}

We encourage you to visit the canteen in person or contact us directly so we can assist you further. We would be happy to help resolve any issues and get {{ $preRegistration->first_name }} enrolled.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
