# Email Template Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace all six plain email Blade templates with a branded Sunbites design using a shared anonymous Blade layout component.

**Architecture:** One new Blade component (`resources/views/components/emails/layout.blade.php`) provides the full HTML envelope — red header with logo, white card body, grey footer. All six existing templates are rewritten to use `<x-emails.layout>` for their slot content. The three pre-registration Mail classes must also switch from `markdown:` to `view:` in their `Content()` call so Blade's component system resolves properly.

**Tech Stack:** Laravel 13 Blade anonymous components, inline CSS (required for email clients), PHPUnit 12 with `Mailable::assertSeeInHtml()`

---

## File Map

| Action | File |
|---|---|
| **Create** | `resources/views/components/emails/layout.blade.php` |
| **Create** | `tests/Feature/EmailTemplateTest.php` |
| **Modify** | `resources/views/emails/parent-welcome.blade.php` |
| **Modify** | `resources/views/emails/wallet-alert.blade.php` |
| **Modify** | `resources/views/emails/feedback-reply.blade.php` |
| **Modify** | `resources/views/emails/pre-registration/received.blade.php` |
| **Modify** | `resources/views/emails/pre-registration/approved.blade.php` |
| **Modify** | `resources/views/emails/pre-registration/rejected.blade.php` |
| **Modify** | `app/Mail/PreRegistrationReceivedMail.php` (markdown → view) |
| **Modify** | `app/Mail/PreRegistrationApprovedMail.php` (markdown → view) |
| **Modify** | `app/Mail/PreRegistrationRejectedMail.php` (markdown → view) |

---

## Task 1: Create the Shared Email Layout Component

**Files:**
- Create: `resources/views/components/emails/layout.blade.php`

- [ ] **Step 1: Create the directory and layout component**

Create `resources/views/components/emails/layout.blade.php` with this exact content:

```blade
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Sunbites' }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f3f4f6;font-family:Arial,Helvetica,sans-serif;">
    <div style="background-color:#f3f4f6;padding:32px 16px;">
        <div style="max-width:600px;margin:0 auto;">

            {{-- Header --}}
            <div style="background-color:#dc2626;padding:28px 32px;text-align:center;border-radius:8px 8px 0 0;">
                <img src="{{ asset('images/sunbites.png') }}"
                     alt="Sunbites"
                     height="48"
                     style="height:48px;width:auto;display:block;margin:0 auto;">
            </div>

            {{-- Card body --}}
            <div style="background-color:#ffffff;padding:32px;color:#1a1a1a;font-size:15px;line-height:1.6;">
                {{ $slot }}
            </div>

            {{-- Footer --}}
            <div style="background-color:#f9fafb;padding:20px 32px;text-align:center;border-top:1px solid #e5e7eb;border-radius:0 0 8px 8px;">
                <p style="margin:0;color:#9ca3af;font-size:12px;">Sunbites School Canteen Management System</p>
                <p style="margin:4px 0 0;color:#9ca3af;font-size:12px;">&copy; {{ date('Y') }} Sunbites. All rights reserved.</p>
            </div>

        </div>
    </div>
</body>
</html>
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/components/emails/layout.blade.php
git commit -m "feat: add shared email layout Blade component"
```

---

## Task 2: Write Failing Render Tests (TDD)

These tests assert on the final branded output. They will **fail** against the current templates — that is expected. Each subsequent task will make the relevant test pass.

**Files:**
- Create: `tests/Feature/EmailTemplateTest.php`

- [ ] **Step 1: Create the test file**

Run:
```bash
vendor/bin/sail artisan make:test --phpunit Feature/EmailTemplateTest
```

- [ ] **Step 2: Replace the generated file with the full test class**

Replace the contents of `tests/Feature/EmailTemplateTest.php` with:

```php
<?php

namespace Tests\Feature;

use App\Enums\FeedbackCategory;
use App\Mail\FeedbackReplyMail;
use App\Mail\ParentWelcomeMail;
use App\Mail\PreRegistrationApprovedMail;
use App\Mail\PreRegistrationReceivedMail;
use App\Mail\PreRegistrationRejectedMail;
use App\Mail\WalletAlertMail;
use App\Models\Branch;
use App\Models\Feedback;
use App\Models\ParentUser;
use App\Models\PreRegistration;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_welcome_mail_renders_with_branding(): void
    {
        $parent = ParentUser::create([
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'email' => 'maria@example.com',
            'password' => bcrypt('password'),
        ]);

        $mailable = new ParentWelcomeMail($parent, 'test-activation-token');

        $mailable
            ->assertSeeInHtml('sunbites.png')
            ->assertSeeInHtml('Welcome to Sunbites, Maria!')
            ->assertSeeInHtml('Activate My Account')
            ->assertSeeInHtml('60 minutes')
            ->assertSeeInHtml('Sunbites School Canteen Management System');
    }

    public function test_wallet_alert_mail_renders_with_branding(): void
    {
        $branch = Branch::factory()->create();
        $parent = ParentUser::create([
            'first_name' => 'Jose',
            'last_name' => 'Reyes',
            'email' => 'jose@example.com',
            'password' => bcrypt('password'),
        ]);
        $student = Student::factory()->create(['branch_id' => $branch->id]);

        $mailable = new WalletAlertMail($parent, $student, 45.00, 100.00);

        $mailable
            ->assertSeeInHtml('sunbites.png')
            ->assertSeeInHtml('Low Wallet Balance Alert')
            ->assertSeeInHtml('45.00')
            ->assertSeeInHtml('100.00')
            ->assertSeeInHtml('View Parent Portal')
            ->assertSeeInHtml('Sunbites School Canteen Management System');
    }

    public function test_feedback_reply_mail_renders_with_branding(): void
    {
        $branch = Branch::factory()->create();
        $parent = ParentUser::create([
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
            'email' => 'ana@example.com',
            'password' => bcrypt('password'),
        ]);
        $student = Student::factory()->create(['branch_id' => $branch->id]);
        $feedback = Feedback::create([
            'parent_id' => $parent->id,
            'student_id' => $student->id,
            'branch_id' => $branch->id,
            'category' => FeedbackCategory::General->value,
            'rating' => 4,
            'message' => 'Great service today!',
            'admin_reply' => 'Thank you for your kind feedback!',
            'is_read' => true,
        ]);

        $mailable = new FeedbackReplyMail($feedback);

        $mailable
            ->assertSeeInHtml('sunbites.png')
            ->assertSeeInHtml('A Reply to Your Feedback')
            ->assertSeeInHtml('Ana')
            ->assertSeeInHtml('Thank you for your kind feedback!')
            ->assertSeeInHtml('Sunbites School Canteen Management System');
    }

    public function test_pre_registration_received_mail_renders_with_branding(): void
    {
        $preRegistration = PreRegistration::factory()->create([
            'first_name' => 'Carlos',
            'last_name' => 'Bautista',
            'signatory_name' => 'Mario Bautista',
            'grade_level' => 'Grade 3',
            'enrollment_type' => 'non_subscription',
        ]);

        $mailable = new PreRegistrationReceivedMail($preRegistration);

        $mailable
            ->assertSeeInHtml('sunbites.png')
            ->assertSeeInHtml('Pre-Registration Received!')
            ->assertSeeInHtml('Mario Bautista')
            ->assertSeeInHtml('Carlos Bautista')
            ->assertSeeInHtml('Grade 3')
            ->assertSeeInHtml('Sunbites School Canteen Management System');
    }

    public function test_pre_registration_approved_mail_renders_with_branding(): void
    {
        $branch = Branch::factory()->create();
        $preRegistration = PreRegistration::factory()->create([
            'first_name' => 'Luisa',
            'last_name' => 'Dela Cruz',
            'signatory_name' => 'Pedro Dela Cruz',
            'grade_level' => 'Grade 5',
            'enrollment_type' => 'subscription',
        ]);
        $student = Student::factory()->create(['branch_id' => $branch->id]);

        $mailable = new PreRegistrationApprovedMail($preRegistration, $student);

        $mailable
            ->assertSeeInHtml('sunbites.png')
            ->assertSeeInHtml('Enrollment Approved!')
            ->assertSeeInHtml('Pedro Dela Cruz')
            ->assertSeeInHtml('Luisa Dela Cruz')
            ->assertSeeInHtml('Grade 5')
            ->assertSeeInHtml('Sunbites School Canteen Management System');
    }

    public function test_pre_registration_rejected_mail_renders_with_branding(): void
    {
        $preRegistration = PreRegistration::factory()->create([
            'first_name' => 'Marco',
            'last_name' => 'Santiago',
            'signatory_name' => 'Ella Santiago',
            'rejection_reason' => 'Incomplete supporting documents provided.',
        ]);

        $mailable = new PreRegistrationRejectedMail($preRegistration);

        $mailable
            ->assertSeeInHtml('sunbites.png')
            ->assertSeeInHtml('Update on Your Pre-Registration')
            ->assertSeeInHtml('Ella Santiago')
            ->assertSeeInHtml('Incomplete supporting documents provided.')
            ->assertSeeInHtml('Sunbites School Canteen Management System');
    }
}
```

- [ ] **Step 3: Run all six tests to confirm they fail**

```bash
vendor/bin/sail artisan test --compact tests/Feature/EmailTemplateTest.php
```

Expected: **6 FAILED** — the current templates do not contain `sunbites.png` or the branded headings.

- [ ] **Step 4: Commit the failing tests**

```bash
git add tests/Feature/EmailTemplateTest.php
git commit -m "test: add failing render tests for branded email templates"
```

---

## Task 3: Update `parent-welcome` Template

**Files:**
- Modify: `resources/views/emails/parent-welcome.blade.php`

- [ ] **Step 1: Replace the template**

Replace the entire contents of `resources/views/emails/parent-welcome.blade.php` with:

```blade
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
```

- [ ] **Step 2: Run the parent-welcome test**

```bash
vendor/bin/sail artisan test --compact --filter=test_parent_welcome_mail_renders_with_branding
```

Expected: **1 PASSED**

- [ ] **Step 3: Format and commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add resources/views/emails/parent-welcome.blade.php
git commit -m "feat: apply branded layout to parent-welcome email"
```

---

## Task 4: Update `wallet-alert` Template

**Files:**
- Modify: `resources/views/emails/wallet-alert.blade.php`

- [ ] **Step 1: Replace the template**

Replace the entire contents of `resources/views/emails/wallet-alert.blade.php` with:

```blade
<x-emails.layout title="Low Wallet Balance Alert">
    <h2 style="margin:0 0 16px;font-size:22px;font-weight:700;color:#1a1a1a;">
        Low Wallet Balance Alert
    </h2>

    <p style="margin:0 0 16px;">Hi {{ $parent->first_name }},</p>

    <p style="margin:0 0 20px;">
        <strong>{{ $student->full_name }}</strong>'s canteen wallet balance has dropped below your alert threshold.
    </p>

    <div style="background-color:#fef2f2;border-left:4px solid #dc2626;border-radius:0 6px 6px 0;padding:16px;margin:0 0 20px;">
        <div style="padding:6px 0;">
            <span style="color:#6b7280;font-size:13px;">Current Balance</span>
            <span style="display:block;font-weight:700;font-size:18px;color:#dc2626;">
                &#8369;{{ number_format($currentBalance, 2) }}
            </span>
        </div>
        <div style="padding:6px 0;border-top:1px solid #fecaca;margin-top:6px;">
            <span style="color:#6b7280;font-size:13px;">Alert Threshold</span>
            <span style="display:block;font-weight:600;color:#1a1a1a;">
                &#8369;{{ number_format($threshold, 2) }}
            </span>
        </div>
    </div>

    <p style="margin:0 0 24px;">
        Please arrange a wallet top-up at the canteen or contact the school.
    </p>

    <div style="text-align:center;margin:28px 0;">
        <a href="{{ $portalUrl }}"
           style="background-color:#dc2626;color:#ffffff;padding:12px 28px;border-radius:6px;text-decoration:none;display:inline-block;font-weight:600;font-size:15px;">
            View Parent Portal
        </a>
    </div>
</x-emails.layout>
```

> Note: `&#8369;` is the HTML entity for the Philippine Peso sign (₱), used instead of the raw UTF-8 character for maximum email client compatibility.

- [ ] **Step 2: Run the wallet-alert test**

```bash
vendor/bin/sail artisan test --compact --filter=test_wallet_alert_mail_renders_with_branding
```

Expected: **1 PASSED**

- [ ] **Step 3: Format and commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add resources/views/emails/wallet-alert.blade.php
git commit -m "feat: apply branded layout to wallet-alert email"
```

---

## Task 5: Update `feedback-reply` Template

**Files:**
- Modify: `resources/views/emails/feedback-reply.blade.php`

- [ ] **Step 1: Replace the template**

Replace the entire contents of `resources/views/emails/feedback-reply.blade.php` with:

```blade
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
```

- [ ] **Step 2: Run the feedback-reply test**

```bash
vendor/bin/sail artisan test --compact --filter=test_feedback_reply_mail_renders_with_branding
```

Expected: **1 PASSED**

- [ ] **Step 3: Format and commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add resources/views/emails/feedback-reply.blade.php
git commit -m "feat: apply branded layout to feedback-reply email"
```

---

## Task 6: Update Pre-Registration Mail Classes + `received` Template

The three pre-registration Mail classes currently use `markdown:` in their `Content()` call, which routes through Laravel's markdown mail pipeline and ignores Blade components. Switch them to `view:` so the new layout component resolves correctly.

**Files:**
- Modify: `app/Mail/PreRegistrationReceivedMail.php`
- Modify: `app/Mail/PreRegistrationApprovedMail.php`
- Modify: `app/Mail/PreRegistrationRejectedMail.php`
- Modify: `resources/views/emails/pre-registration/received.blade.php`

- [ ] **Step 1: Update `PreRegistrationReceivedMail` — change `markdown:` to `view:`**

In `app/Mail/PreRegistrationReceivedMail.php`, change the `content()` method from:

```php
return new Content(
    markdown: 'emails.pre-registration.received',
    with: ['preRegistration' => $this->preRegistration],
);
```

to:

```php
return new Content(
    view: 'emails.pre-registration.received',
    with: ['preRegistration' => $this->preRegistration],
);
```

- [ ] **Step 2: Update `PreRegistrationApprovedMail` — change `markdown:` to `view:`**

In `app/Mail/PreRegistrationApprovedMail.php`, change the `content()` method from:

```php
return new Content(
    markdown: 'emails.pre-registration.approved',
    with: [
        'preRegistration' => $this->preRegistration,
        'student' => $this->student,
    ],
);
```

to:

```php
return new Content(
    view: 'emails.pre-registration.approved',
    with: [
        'preRegistration' => $this->preRegistration,
        'student' => $this->student,
    ],
);
```

- [ ] **Step 3: Update `PreRegistrationRejectedMail` — change `markdown:` to `view:`**

In `app/Mail/PreRegistrationRejectedMail.php`, change the `content()` method from:

```php
return new Content(
    markdown: 'emails.pre-registration.rejected',
    with: ['preRegistration' => $this->preRegistration],
);
```

to:

```php
return new Content(
    view: 'emails.pre-registration.rejected',
    with: ['preRegistration' => $this->preRegistration],
);
```

- [ ] **Step 4: Replace the `received` template**

Replace the entire contents of `resources/views/emails/pre-registration/received.blade.php` with:

```blade
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
```

- [ ] **Step 5: Run the pre-registration received test**

```bash
vendor/bin/sail artisan test --compact --filter=test_pre_registration_received_mail_renders_with_branding
```

Expected: **1 PASSED**

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Mail/PreRegistrationReceivedMail.php \
        app/Mail/PreRegistrationApprovedMail.php \
        app/Mail/PreRegistrationRejectedMail.php \
        resources/views/emails/pre-registration/received.blade.php
git commit -m "feat: switch pre-registration mails from markdown to view, redesign received template"
```

---

## Task 7: Update `approved` Template

**Files:**
- Modify: `resources/views/emails/pre-registration/approved.blade.php`

- [ ] **Step 1: Replace the template**

Replace the entire contents of `resources/views/emails/pre-registration/approved.blade.php` with:

```blade
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
```

> Note: `&#127881;` is the HTML entity for the 🎉 emoji — safer than raw UTF-8 in some email clients.

- [ ] **Step 2: Run the approved test**

```bash
vendor/bin/sail artisan test --compact --filter=test_pre_registration_approved_mail_renders_with_branding
```

Expected: **1 PASSED**

- [ ] **Step 3: Format and commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add resources/views/emails/pre-registration/approved.blade.php
git commit -m "feat: apply branded layout to pre-registration approved email"
```

---

## Task 8: Update `rejected` Template

**Files:**
- Modify: `resources/views/emails/pre-registration/rejected.blade.php`

- [ ] **Step 1: Replace the template**

Replace the entire contents of `resources/views/emails/pre-registration/rejected.blade.php` with:

```blade
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
```

- [ ] **Step 2: Run the rejected test**

```bash
vendor/bin/sail artisan test --compact --filter=test_pre_registration_rejected_mail_renders_with_branding
```

Expected: **1 PASSED**

- [ ] **Step 3: Format and commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add resources/views/emails/pre-registration/rejected.blade.php
git commit -m "feat: apply branded layout to pre-registration rejected email"
```

---

## Task 9: Full Test Suite Verification

- [ ] **Step 1: Run all email template tests together**

```bash
vendor/bin/sail artisan test --compact tests/Feature/EmailTemplateTest.php
```

Expected: **6 PASSED**

- [ ] **Step 2: Run the full suite**

```bash
vendor/bin/sail artisan test --compact
```

Expected: all tests pass. If any pre-registration functional tests fail, they may be asserting on markdown-rendered content (e.g. checking for `# Enrollment Approved!` Markdown heading). Find those assertions and update them to match the new plain HTML output (`Enrollment Approved!` without `#`).

- [ ] **Step 3: Format any remaining dirty files**

```bash
vendor/bin/sail bin pint --dirty --format agent
```

- [ ] **Step 4: Final commit if any formatting changes were made**

```bash
git add -p
git commit -m "style: apply pint formatting to email redesign files"
```
