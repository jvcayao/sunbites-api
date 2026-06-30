# Fix Orphaned Subscription Payments — Design Spec

## Goal

Before the proper subscription downgrade flow existed, staff used the naive `updateType` endpoint to switch students from `subscription` to `non_subscription`. That endpoint only updated `student_type` — it never cleaned up `student_monthly_payments` records. This left orphaned payment rows on non-subscription students.

This spec covers two fixes:
1. An Artisan command to clean up existing dirty data (idempotent, safe to run on any environment including Laravel Cloud)
2. A backend guard on `updateType` to prevent the same dirty data from being created again

---

## Background: Business Rules (from the downgrade feature)

These rules govern what the command does — consistent with the live downgrade flow:

- **Unpaid monthly payments** on a non-subscription student → **hard-delete** (they will never be paid; the student is no longer on subscription)
- **Paid monthly payments** on a non-subscription student → **retain permanently** (represent real payment history; staff must manually void via the existing void endpoint if a refund is needed)
- **Voided payments** → **leave untouched** (already resolved)

---

## Component 1: Artisan Command

### Name

```bash
php artisan subscriptions:fix-orphaned-payments
```

### Flags

| Flag | Behaviour |
|---|---|
| _(none)_ | **Dry-run** — reports what would change, makes no database mutations. Default for safety. |
| `--execute` | Applies changes: hard-deletes orphaned unpaid payments, logs activity. |
| `--branch=ID` | Restricts the command to a single branch. Optional; default processes all branches. |

### Command Structure

Use PHP 8 attribute-based signature (matches existing commands like `ExpirePreRegistrations`):

```php
#[Signature('subscriptions:fix-orphaned-payments {--execute} {--branch=}')]
#[Description('Clean up orphaned monthly payments on non-subscription students.')]
class FixOrphanedSubscriptionPaymentsCommand extends Command
```

### Logic

1. If `--branch` is provided, validate the branch ID exists (`Branch::find($branchId)`) and abort with an error message if not found.
2. Query `Student::withoutBranch()` where `student_type = 'non_subscription'` and `whereHas('monthlyPayments')`. Apply `->where('branch_id', $branchId)` when `--branch` is provided. Use `chunk(100)` to avoid memory/timeout issues on large datasets.
3. For each affected student, partition their monthly payments:
   - `status = 'unpaid'` → **delete** (in `--execute` mode)
   - `status = 'paid'` → **retain**, flag in output as needing manual staff review
   - `status = 'voided'` → **skip entirely**
4. In `--execute` mode, wrap each student's deletions in a `DB::transaction()` and log one activity entry per student:
   - Log name: `students`
   - Description: `students.orphaned_payments_cleaned`
   - Properties: `deleted_count`, `deleted_months` (array of `school_month/year`), `retained_paid_count`, `retained_paid_months`
   - **No `causedBy()`** — the command has no authenticated user; omitting it lets activitylog record this as system-initiated (causer = null), which is correct for maintenance commands.
5. In dry-run mode, output the same report but make zero database changes.

### Output Format

```
[DRY RUN] Scanning all branches for non-subscription students with orphaned payments...

  Juan dela Cruz (Branch: Main)
    → 4 unpaid months would be deleted: Jun 2025, Jul 2025, Aug 2025, Sep 2025 (₱2,400)
    ✓ No paid months retained

  Erik Baumbach (Branch: Main)
    → 2 unpaid months would be deleted: Jun 2025, Jul 2025 (₱1,200)
    ⚠  1 paid month retained: Aug 2025 ₱600 — review and void manually if refund is needed

Summary: 3 students affected, 6 unpaid months would be deleted.
         1 student has retained paid months requiring manual staff review.

Run with --execute to apply changes.
```

In `--execute` mode, replace "would be deleted" with "deleted" and remove the trailing instruction line.

If no students are affected:

```
No non-subscription students with orphaned payments found. Nothing to do.
```

### Idempotency

The command is safe to run multiple times. After the first `--execute` run, subsequent runs find zero affected students and exit cleanly. Safe for scheduled jobs or CI pipelines.

### Laravel Cloud Compatibility

- No filesystem writes
- No queue dispatches
- No external HTTP calls
- No wallet mutations
- `chunk(100)` prevents memory/timeout issues on large datasets
- Run via Cloud dashboard → Artisan commands panel, or `php artisan` in a Cloud shell

---

## Component 2: Backend Guard on `updateType`

### Location

`app/Http/Controllers/Kitchen/StudentController.php` — `updateType()` method

### Guard Logic

Before updating the student type, check if the request is a subscription → non-subscription downgrade:

```php
abort_if(
    $student->student_type === StudentType::Subscription
        && StudentType::from($validated['student_type']) === StudentType::NonSubscription,
    422,
    'Subscription students must be downgraded via the dedicated downgrade endpoint.'
);
```

### Affected Paths

| Transition | Result after guard |
|---|---|
| `non_subscription` → `subscription` | ✅ Still works via `updateType` (upgrade path) |
| `subscription` → `non_subscription` | ❌ 422 — must use `POST /students/{student}/downgrade-subscription` |
| Same type → same type | Passes through (no-op update, existing behaviour) |

---

## Testing

### Command Tests (`tests/Feature/Kitchen/FixOrphanedPaymentsCommandTest.php`)

| Test | Assertion |
|---|---|
| Dry-run makes no database changes | Payments unchanged after command without `--execute` |
| `--execute` hard-deletes unpaid payments | `assertDatabaseMissing` for deleted payment rows |
| `--execute` retains paid payments | `assertDatabaseHas` for paid payment rows |
| `--execute` skips voided payments | Voided rows untouched |
| `--execute` logs activity with no causer | `Activity` record with correct description, properties, and `causer_id = null` |
| Already-clean student not affected | Student with no monthly payments produces no output |
| `--branch=` with valid ID restricts scope | Only students in the specified branch are processed |
| `--branch=` with invalid ID aborts with error | Command exits non-zero, no DB changes made |
| Idempotent | Second `--execute` run finds zero students, exits cleanly |

### Guard Tests (`tests/Feature/StudentDetailTest.php`)

**Important:** The existing test `test_manager_can_downgrade_subscription_student_to_wallet` calls `PATCH /students/{student}/type` with `non_subscription` on a subscription student and currently asserts `assertOk()` (200). Adding the guard **will break this test**. It must be updated:

- Rename to `test_updateType_rejects_subscription_to_non_subscription_downgrade`
- Change assertion from `assertOk()` to `assertUnprocessable()` (422)
- Remove the `assertDatabaseHas` for `non_subscription` (the type will not change)

The existing test `test_manager_can_upgrade_wallet_student_to_subscription` (non-subscription → subscription) is unaffected and continues to assert `assertOk()`.

---

## Files

### New
| File | Purpose |
|---|---|
| `app/Console/Commands/FixOrphanedSubscriptionPaymentsCommand.php` | The Artisan command |
| `tests/Feature/Kitchen/FixOrphanedPaymentsCommandTest.php` | Command tests |

### Modified
| File | Change |
|---|---|
| `app/Http/Controllers/Kitchen/StudentController.php` | Add guard in `updateType()` |
| `tests/Feature/StudentDetailTest.php` | Update `test_manager_can_downgrade_subscription_student_to_wallet` to expect 422 |
