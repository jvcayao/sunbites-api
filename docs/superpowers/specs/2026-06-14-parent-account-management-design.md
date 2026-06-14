# Parent Account Management Design

**Date:** 2026-06-14
**Project:** Sunbites API (Laravel) + Sunbites POS (Next.js)
**Scope:** Enable/disable parent portal access, soft-delete parent accounts, restore with forced password reset.

---

## Overview

Staff (admin/manager) can disable a parent's portal access, soft-delete a parent account, and restore it. Restoring always forces the parent to reset their password via an activation email. All state transitions are managed manually from the POS parent management page.

---

## Decisions Made

| Question | Decision |
|---|---|
| Disabled parent login behaviour | Reject at login — `401` with `error: 'account_disabled'` |
| How access is tracked | New `disabled_at` timestamp column on `parents` table |
| Soft-delete | `deleted_at` column via `SoftDeletes` trait |
| Token revocation on disable/delete | Yes — all existing Sanctum tokens revoked immediately |
| Auto re-enable on student restore | No — manual only |
| Restore behaviour | Always forces password reset: clears `email_verified_at`, queues activation mail |

---

## Section 1: Database

### Migration 1 — `add_disabled_at_to_parents_table`

```php
$table->timestamp('disabled_at')->nullable()->after('remember_token');
```

`null` = enabled. Timestamp = disabled (stores when it was disabled for audit purposes).

### Migration 2 — `add_soft_deletes_to_parents_table`

```php
$table->softDeletes();
```

Standard `deleted_at` column. Soft-deleted parents are excluded from all normal queries automatically.

---

## Section 2: ParentUser Model (`app/Models/ParentUser.php`)

**Add:**
- `SoftDeletes` trait
- `disabled_at` to `$fillable`
- `disabled_at` cast as `datetime` in `casts()`

**New helper methods:**

```php
public function isDisabled(): bool
{
    return $this->disabled_at !== null;
}

public function isAccessible(): bool
{
    return ! $this->isDisabled() && $this->isActivated();
}
```

`isAccessible()` is the single source of truth for whether a parent may log in.

---

## Section 3: Action Classes (`app/Actions/Parents/`)

### `DisableParentAction`

- Sets `disabled_at = now()`
- Revokes all Sanctum tokens (`$parent->tokens()->delete()`)
- Does **not** touch `email_verified_at`

### `EnableParentAction`

- Clears `disabled_at = null`
- Clears `email_verified_at = null` (forces password reset)
- Revokes all existing tokens
- Creates password reset token via `Password::broker('parents')`
- Queues `ParentWelcomeMail` with that token

### `SoftDeleteParentAction`

- Revokes all tokens (`$parent->tokens()->delete()`)
- Calls `$parent->delete()` (sets `deleted_at`)

### `RestoreParentAction`

- Calls `$parent->restore()` (clears `deleted_at`)
- Clears `disabled_at = null` if set
- Delegates to `EnableParentAction` for the password reset + mail flow

---

## Section 4: Controller & Routes

### `ParentController` — new methods

| Method | Action Class | Route |
|---|---|---|
| `disable($parent)` | `DisableParentAction` | `POST /api/v1/references/parents/{parent}/disable` |
| `enable($parent)` | `EnableParentAction` | `POST /api/v1/references/parents/{parent}/enable` |
| `destroy($parent)` | `SoftDeleteParentAction` | `DELETE /api/v1/references/parents/{parent}` |
| `restore($id)` | `RestoreParentAction` | `POST /api/v1/references/parents/{id}/restore` |

**Route model binding for restore:** `{parent}` won't resolve soft-deleted records by default. The `restore` route uses a plain `{id}` parameter and resolves via `ParentUser::withTrashed()->findOrFail($id)` in the controller.

All four routes sit under the existing `admin|manager` role middleware group.

### `index` response — additional fields

```json
{
  "id": 1,
  "full_name": "...",
  "is_activated": true,
  "is_disabled": false,
  "deleted_at": null
}
```

### `Portal\AuthController::login` — updated check order

1. Parent not found → `422` (invalid credentials)
2. Account not activated (`email_verified_at` is null) → `401` with `account_not_activated`
3. **Account disabled (`disabled_at` is set) → `401` with `account_disabled`** ← new
4. Password mismatch → `422` (invalid credentials)

---

## Section 5: Backend Tests (`tests/Feature/Kitchen/ParentAccountManagementTest.php`)

### Happy paths
- Admin disables an active parent → `disabled_at` set, tokens revoked, `200`
- Admin enables a disabled parent → `disabled_at` null, `email_verified_at` null, activation mail queued, `200`
- Admin soft-deletes a parent → `deleted_at` set, tokens revoked, `200`
- Admin restores a soft-deleted parent → `deleted_at` null, `disabled_at` null, `email_verified_at` null, activation mail queued, `200`
- Disabled parent login → `401` with `error: account_disabled`
- Soft-deleted parent login → `422` (record not found; login query does not include `withTrashed`)

### Authorization paths
- Supervisor cannot disable/enable/delete/restore → `403`
- Unauthenticated → `401`

### Edge cases
- Enabling an already-enabled parent is idempotent — no error, mail still queued, `email_verified_at` cleared
- Restoring a non-deleted parent → `404`
- Disabling an already-disabled parent is idempotent — no error, `disabled_at` updated to now

---

## Section 6: Frontend (POS) — `~/sunbites-pos`

### API service layer (`lib/api/parents.ts`)

Four new typed calls:

```typescript
disable: (id: number) => apiClient.post(`/references/parents/${id}/disable`)
enable: (id: number) => apiClient.post(`/references/parents/${id}/enable`)
destroy: (id: number) => apiClient.delete(`/references/parents/${id}`)
restore: (id: number) => apiClient.post(`/references/parents/${id}/restore`)
```

### UI changes

- **Parent list row:** render `is_disabled` badge ("Disabled") and a "Deleted" state indicator
- **Show deleted toggle:** filter control to show/hide soft-deleted parents in the list
- **Action menu per parent:**
  - Show "Disable" when `!is_disabled && !deleted_at`
  - Show "Enable" when `is_disabled && !deleted_at`
  - Show "Delete" when `!deleted_at`
  - Show "Restore" when `deleted_at !== null`
- After any mutation: invalidate the parents query cache to refresh the list

### Frontend tests (`__tests__/parents/parent-account-management.test.tsx`)

Using Jest 30 + React Testing Library + MSW 2:

- Disabled badge renders when `is_disabled: true`
- "Enable" button visible (not "Disable") for a disabled parent
- "Restore" button visible for a soft-deleted parent
- Clicking "Disable" fires the correct API call (MSW intercept) and triggers query invalidation
- Clicking "Enable" fires the correct API call
- Clicking "Delete" fires the correct API call, tokens revoked reflected in UI feedback
- Clicking "Restore" fires the correct API call
- Validation: `npm run quality:validate` (type-check + lint) passes with no errors

### Quality gates

Before marking frontend work complete:
```bash
npm run type-check
npm run lint
npm run format:check
npm run test:coverage
```

---

## What Is NOT in Scope

- Automatic re-enable of parents when a linked student is restored (manual only)
- Any changes to the portal app (`~/sunbites-portal`) — it only consumes auth errors; no UI changes needed there
- Email template changes — existing `ParentWelcomeMail` is reused as-is
