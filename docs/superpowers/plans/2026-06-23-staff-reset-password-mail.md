# Staff Reset Password Mail — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace both staff password-reset flows (public self-service + admin-initiated) from a `Notification`-based dispatch to an explicit queued `Mailable`, mirroring the working parent portal pattern.

**Architecture:** Create `StaffResetPasswordMail` (analogous to `ParentResetPasswordMail`), update both controllers to look up the user, create a token, and queue the mailable. Delete `StaffResetPasswordNotification` and the now-dead `User::sendPasswordResetNotification()` override and `AppServiceProvider::ResetPassword::createUrlUsing()` hook.

**Tech Stack:** Laravel 13, PHPUnit 12, Laravel Sail, Spatie Activity Log, bavix wallet (not touched), Sanctum (not touched).

## Global Constraints

- All commands run via `vendor/bin/sail` prefix.
- PHP 8.5 — use constructor property promotion, explicit return types.
- Run `vendor/bin/sail bin pint --dirty --format agent` after any PHP edit.
- Every feature change ships with tests. No deleting existing tests — only update them.
- Never mock Eloquent or the DB. Use `RefreshDatabase` / `LazilyRefreshDatabase`.
- `Mail::fake()` for asserting queued mailables; `Notification::fake()` is being retired here.

---

### Task 1: Create `StaffResetPasswordMail`

**Files:**
- Create: `app/Mail/StaffResetPasswordMail.php`

**Interfaces:**
- Consumes: `App\Models\User`, `string $token`, `config('app.pos_url')`
- Produces: `StaffResetPasswordMail` — used by Tasks 2 and 3

- [ ] **Step 1: Write the failing test (in `AuthTest.php` — add at the bottom of the class)**

Open `tests/Feature/Kitchen/AuthTest.php`. Add the following import at the top (after existing imports):

```php
use App\Mail\StaffResetPasswordMail;
use Illuminate\Support\Facades\Mail;
```

Add this test method at the bottom of the class body:

```php
public function test_forgot_password_queues_staff_reset_mail_for_active_user(): void
{
    Mail::fake();

    $user = User::factory()->create();

    $response = $this->postJson('/api/v1/auth/password/email', [
        'email' => $user->email,
    ]);

    $response->assertOk()
        ->assertJson(['message' => 'If an account with this email exists, you will receive an email shortly.']);

    Mail::assertQueued(StaffResetPasswordMail::class, function (StaffResetPasswordMail $mail) use ($user) {
        return $mail->hasTo($user->email);
    });
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
vendor/bin/sail artisan test --compact --filter=test_forgot_password_queues_staff_reset_mail_for_active_user
```

Expected: FAIL — `StaffResetPasswordMail` class not found.

- [ ] **Step 3: Create the mailable**

Create `app/Mail/StaffResetPasswordMail.php`:

```php
<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StaffResetPasswordMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your password — Sunbites');
    }

    public function content(): Content
    {
        $resetUrl = rtrim(config('app.pos_url'), '/').'/reset-password?'.http_build_query([
            'token' => $this->token,
            'email' => $this->user->email,
        ]);

        return new Content(
            view: 'emails.staff-reset-password',
            with: [
                'name' => $this->user->first_name,
                'resetUrl' => $resetUrl,
            ],
        );
    }
}
```

- [ ] **Step 4: Run pint**

```bash
vendor/bin/sail bin pint --dirty --format agent
```

- [ ] **Step 5: Run test to verify it passes**

```bash
vendor/bin/sail artisan test --compact --filter=test_forgot_password_queues_staff_reset_mail_for_active_user
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Mail/StaffResetPasswordMail.php tests/Feature/Kitchen/AuthTest.php
git commit -m "feat: add StaffResetPasswordMail queued mailable"
```

---

### Task 2: Update `AuthController::sendResetEmail()` — public self-service flow

**Files:**
- Modify: `app/Http/Controllers/Kitchen/AuthController.php`
- Test: `tests/Feature/Kitchen/AuthTest.php`

**Interfaces:**
- Consumes: `StaffResetPasswordMail` from Task 1
- Produces: `POST /api/v1/auth/password/email` — returns `{"message": "If an account with this email exists, you will receive an email shortly."}` always

- [ ] **Step 1: Write additional tests**

Add the following three tests to `tests/Feature/Kitchen/AuthTest.php`:

```php
public function test_forgot_password_does_not_queue_mail_for_inactive_user(): void
{
    Mail::fake();

    $user = User::factory()->inactive()->create();

    $this->postJson('/api/v1/auth/password/email', [
        'email' => $user->email,
    ])->assertOk();

    Mail::assertNothingQueued();
}

public function test_forgot_password_does_not_queue_mail_for_unknown_email(): void
{
    Mail::fake();

    $this->postJson('/api/v1/auth/password/email', [
        'email' => 'nobody@example.com',
    ])->assertOk();

    Mail::assertNothingQueued();
}

public function test_forgot_password_reset_url_points_to_pos_app(): void
{
    Mail::fake();

    $user = User::factory()->create();

    $this->postJson('/api/v1/auth/password/email', [
        'email' => $user->email,
    ])->assertOk();

    Mail::assertQueued(StaffResetPasswordMail::class, function (StaffResetPasswordMail $mail) {
        $posUrl = config('app.pos_url');
        $url = $mail->content()->with['resetUrl'];

        return str_starts_with($url, $posUrl) && str_contains($url, 'reset-password');
    });
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
vendor/bin/sail artisan test --compact --filter=test_forgot_password
```

Expected: The URL test will fail because the controller still uses `Password::sendResetLink()`.

- [ ] **Step 3: Update `AuthController::sendResetEmail()`**

Open `app/Http/Controllers/Kitchen/AuthController.php`.

Add these imports (replace or supplement existing ones at the top):

```php
use App\Mail\StaffResetPasswordMail;
use Illuminate\Support\Facades\Mail;
```

Replace the `sendResetEmail` method body:

```php
public function sendResetEmail(Request $request): JsonResponse
{
    $request->validate(['email' => ['required', 'email']]);

    $user = User::where('email', $request->email)->first();

    if ($user && $user->is_active) {
        $token = Password::createToken($user);
        Mail::to($user->email)->queue(new StaffResetPasswordMail($user, $token));
    }

    activity('auth')
        ->withProperties(['ip' => $request->ip()])
        ->log('auth.password_reset_requested');

    return response()->json([
        'message' => 'If an account with this email exists, you will receive an email shortly.',
    ]);
}
```

- [ ] **Step 4: Run pint**

```bash
vendor/bin/sail bin pint --dirty --format agent
```

- [ ] **Step 5: Run all `test_forgot_password` tests**

```bash
vendor/bin/sail artisan test --compact --filter=test_forgot_password
```

Expected: All 4 PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Kitchen/AuthController.php tests/Feature/Kitchen/AuthTest.php
git commit -m "feat: replace sendResetLink with queued StaffResetPasswordMail in public auth flow"
```

---

### Task 3: Update `UserManagementController::sendResetEmail()` — admin-initiated flow

**Files:**
- Modify: `app/Http/Controllers/Kitchen/UserManagementController.php`
- Modify: `tests/Feature/Kitchen/UserManagementTest.php`

**Interfaces:**
- Consumes: `StaffResetPasswordMail` from Task 1
- Produces: `POST /api/v1/users/{user}/reset-password` — returns `{"message": "Password reset email sent."}`

- [ ] **Step 1: Update existing tests in `UserManagementTest.php`**

Open `tests/Feature/Kitchen/UserManagementTest.php`.

Replace this import:
```php
use App\Notifications\StaffResetPasswordNotification;
```
with:
```php
use App\Mail\StaffResetPasswordMail;
use Illuminate\Support\Facades\Mail;
```

Remove the `Notification` import if it is no longer used elsewhere in the file (check with `grep Notification tests/Feature/Kitchen/UserManagementTest.php`).

Replace `test_admin_can_send_password_reset_email`:

```php
public function test_admin_can_send_password_reset_email(): void
{
    Mail::fake();

    $staff = User::factory()->create();
    $staff->assignRole('cashier');

    $response = $this->withToken($this->adminToken())
        ->postJson("/api/v1/users/{$staff->id}/reset-password");

    $response->assertOk();
    Mail::assertQueued(StaffResetPasswordMail::class, function (StaffResetPasswordMail $mail) use ($staff) {
        return $mail->hasTo($staff->email);
    });
}
```

Replace `test_password_reset_notification_url_points_to_pos_app`:

```php
public function test_password_reset_mail_url_points_to_pos_app(): void
{
    Mail::fake();

    $staff = User::factory()->create(['email' => 'staff@example.com']);
    $staff->assignRole('cashier');

    $this->withToken($this->adminToken())
        ->postJson("/api/v1/users/{$staff->id}/reset-password")
        ->assertOk();

    Mail::assertQueued(StaffResetPasswordMail::class, function (StaffResetPasswordMail $mail) {
        $url = $mail->content()->with['resetUrl'];
        $posUrl = config('app.pos_url');

        return str_starts_with($url, $posUrl) && str_contains($url, 'reset-password');
    });
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
vendor/bin/sail artisan test --compact --filter=test_admin_can_send_password_reset_email
```

Expected: FAIL — still dispatches a Notification.

- [ ] **Step 3: Update `UserManagementController::sendResetEmail()`**

Open `app/Http/Controllers/Kitchen/UserManagementController.php`.

Add these imports at the top:

```php
use App\Mail\StaffResetPasswordMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
```

Replace the `sendResetEmail` method body:

```php
public function sendResetEmail(Request $request, User $user): JsonResponse
{
    $this->authorize('update', $user);

    $token = Password::createToken($user);
    Mail::to($user->email)->queue(new StaffResetPasswordMail($user, $token));

    activity('users')
        ->causedBy($request->user())
        ->performedOn($user)
        ->log('auth.password_reset');

    return response()->json(['message' => 'Password reset email sent.']);
}
```

- [ ] **Step 4: Run pint**

```bash
vendor/bin/sail bin pint --dirty --format agent
```

- [ ] **Step 5: Run updated tests**

```bash
vendor/bin/sail artisan test --compact --filter=test_admin_can_send_password_reset_email
vendor/bin/sail artisan test --compact --filter=test_password_reset_mail_url_points_to_pos_app
```

Expected: Both PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Kitchen/UserManagementController.php tests/Feature/Kitchen/UserManagementTest.php
git commit -m "feat: replace sendResetLink with queued StaffResetPasswordMail in admin-initiated flow"
```

---

### Task 4: Remove dead code

**Files:**
- Modify: `app/Models/User.php` — remove `sendPasswordResetNotification()` override
- Modify: `app/Providers/AppServiceProvider.php` — remove `ResetPassword::createUrlUsing()` closure
- Delete: `app/Notifications/StaffResetPasswordNotification.php`

**Interfaces:**
- Consumes: nothing new
- Produces: cleaner codebase; no behavioral change (these paths are no longer called)

- [ ] **Step 1: Remove `sendPasswordResetNotification()` from `User`**

Open `app/Models/User.php`.

Delete these lines entirely:

```php
public function sendPasswordResetNotification($token)
{
    $this->notify(new StaffResetPasswordNotification($token));
}
```

Also remove the import at the top:

```php
use App\Notifications\StaffResetPasswordNotification;
```

- [ ] **Step 2: Remove `ResetPassword::createUrlUsing()` from `AppServiceProvider`**

Open `app/Providers/AppServiceProvider.php`.

Remove this block from `configureDefaults()`:

```php
ResetPassword::createUrlUsing(function (mixed $notifiable, string $token): string {
    return rtrim(config('app.pos_url'), '/').'/reset-password?'.http_build_query([
        'token' => $token,
        'email' => $notifiable->getEmailForPasswordReset(),
    ]);
});
```

Also remove the now-unused import if `ResetPassword` is no longer referenced elsewhere in the file:

```php
use Illuminate\Auth\Notifications\ResetPassword;
```

- [ ] **Step 3: Delete `StaffResetPasswordNotification`**

```bash
rm app/Notifications/StaffResetPasswordNotification.php
```

- [ ] **Step 4: Run pint**

```bash
vendor/bin/sail bin pint --dirty --format agent
```

- [ ] **Step 5: Run full Auth and UserManagement test suites to confirm nothing broke**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Kitchen/AuthTest.php
vendor/bin/sail artisan test --compact tests/Feature/Kitchen/UserManagementTest.php
```

Expected: All PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Models/User.php app/Providers/AppServiceProvider.php
git rm app/Notifications/StaffResetPasswordNotification.php
git commit -m "chore: remove StaffResetPasswordNotification and dead URL hook"
```

---

### Task 5: Full suite verification

- [ ] **Step 1: Run entire test suite**

```bash
vendor/bin/sail artisan test --compact
```

Expected: All tests PASS. No red.

- [ ] **Step 2: If any unrelated tests fail, investigate before proceeding** — do not skip or delete failing tests.
