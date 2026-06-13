<x-emails.layout title="A Reply to Your Feedback">
    <h2 style="margin:0 0 16px;font-size:22px;font-weight:700;color:#1a1a1a;">
        A Reply to Your Feedback
    </h2>

    <p style="margin:0 0 16px;">Hi {{ $feedback->parent->first_name }},</p>

    <p style="margin:0 0 16px;">The canteen team has replied to your feedback:</p>

    <div style="background-color:#fef2f2;border-left:4px solid #dc2626;border-radius:0 6px 6px 0;padding:16px;margin:0 0 16px;color:#1a1a1a;font-style:italic;line-height:1.7;">
        {{ $feedback->admin_reply }}
    </div>
</x-emails.layout>
