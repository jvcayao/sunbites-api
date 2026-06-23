# Staff Reset Password Mail — Design Spec

**Date:** 2026-06-23  
**Branch:** fix/staff-reset-email

## Problem

The staff forgot-password flow uses `Password::sendResetLink()`, which dispatches a `Notification` via Laravel's built-in pipeline. That notification (`StaffResetPasswordNotification`) does not implement `ShouldQueue`, so it is not queued. The parent portal flow works because it manually creates a token and queues a dedicated `Mailable`.

## Goal

Mirror the parent portal pattern exactly: look up the user, create a password reset token, build a `Mailable`, and queue it.

## Architecture

### New file: `app/Mail/StaffResetPasswordMail.php`

- Accepts `User $user` and `string $token`
- Implements `ShouldQueue`
- Builds reset URL: `config('app.pos_url') . '/reset-password?' . http_build_query([...])`
- Uses the existing blade template: `emails.staff-reset-password`

### Modified: `app/Http/Controllers/Kitchen/AuthController::sendResetEmail()`

Replace `Password::sendResetLink()` with the explicit pattern:

```
1. Look up User by email
2. If found AND is_active: create token, queue StaffResetPasswordMail
3. Always return the same generic JSON response (no enumeration)
```

Inactive staff are skipped silently — they cannot log in so a reset link is pointless.

### Removed: `app/Notifications/StaffResetPasswordNotification.php`

No longer used. Delete the file.

### Modified: `app/Models/User.php`

Remove `sendPasswordResetNotification()` override — no longer triggered.

### Modified: `app/Providers/AppServiceProvider.php`

Remove `ResetPassword::createUrlUsing()` closure — URL is now built inside the mailable, matching the parent approach.

## Files Changed

| Action | File |
|--------|------|
| Create | `app/Mail/StaffResetPasswordMail.php` |
| Modify | `app/Http/Controllers/Kitchen/AuthController.php` |
| Modify | `app/Models/User.php` |
| Modify | `app/Providers/AppServiceProvider.php` |
| Delete | `app/Notifications/StaffResetPasswordNotification.php` |

## Testing

- Update or create a feature test in `tests/Feature/Kitchen/` covering:
  - Active staff email → token created, mail queued
  - Inactive staff email → no mail queued
  - Unknown email → no mail queued, same generic response
  - Response always returns 200 with generic message (no enumeration)
