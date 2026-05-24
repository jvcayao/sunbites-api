<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="font-family:sans-serif;color:#1a1a1a;padding:24px;max-width:600px;margin:0 auto;">
    <h2 style="color:#f97316;">Reply to your feedback</h2>
    <p>Hi {{ $feedback->parent->first_name }},</p>
    <p>The canteen team has replied to your feedback:</p>
    <blockquote style="border-left:4px solid #f97316;margin:16px 0;padding:12px 16px;background:#fff7ed;border-radius:0 6px 6px 0;">
        {{ $feedback->admin_reply }}
    </blockquote>
    <hr style="border:none;border-top:1px solid #e5e7eb;margin:24px 0;">
    <p style="color:#9ca3af;font-size:12px;">Sunbites School Canteen Management System</p>
</body>
</html>
