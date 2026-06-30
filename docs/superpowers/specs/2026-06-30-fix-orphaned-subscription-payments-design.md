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

### Logic

1. Query `Student::withoutBranch()` (bypasses global branch scope) where `student_type = 'non_subscription'` and has at least one monthly payment record. Use `chunk(100)` to avoid memory/timeout issues on large datasets.
2. For each affected student, partition their monthly payments:
   - `status = 'unpaid'` → **delete** (in `--execute` mode)
   - `status = 'paid'` → **retain**, flag in output as needing manual staff review
   - `status = 'voided'` → **skip entirely**
3. In `--execute` mode, wrap each student's deletions in a `DB::transaction()` and log one activity entry:
   - Log name: `students`
   - Description: `students.orphaned_payments_cleaned`
   - Properties: `deleted_count`, `deleted_months` (array of `school_month/year`), `retained_paid_count`, `retained_paid_months`
4. In dry-run mode, output the same report but make zero database changes.

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
| `--execute` logs activity | `Activity` record with correct description and properties |
| Already-clean student not affected | Student with no monthly payments produces no output |
| `--branch` flag restricts scope | Only students in the specified branch are processed |
| Idempotent | Second `--execute` run finds zero students, exits cleanly |

### Guard Tests (add to `tests/Feature/Kitchen/StudentControllerTest.php` or equivalent)

| Test | Assertion |
|---|---|
| Subscription → non-subscription via `updateType` returns 422 | Response status 422 |
| Non-subscription → subscription via `updateType` succeeds | Response status 200, student type updated |

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
| `tests/Feature/Kitchen/StudentControllerTest.php` | Add guard tests (or nearest equivalent test file) |
