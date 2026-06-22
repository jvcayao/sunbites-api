# Zero School Days Support

**Date:** 2026-06-22
**Status:** Approved

## Context

Some school years start in June on the academic calendar, but Sunbites business operations only begin in July. In those years, June has no school activity and must not generate a billing charge for parents. The system currently rejects `days = 0` with a `min:1` validation rule on both the backend and the POS frontend.

Parents are never exposed to school day counts — they only see the final payment amount. So a 0-day month is a purely internal staff configuration concern.

## Goal

Allow staff to configure a school month with `0` school days. A 0-day month means:
- No `StudentMonthlyPayment` record is seeded at enrollment for that month.
- Parents are not charged and see no record for that month.
- An amount override is explicitly prohibited when days is 0 — the month has no billing activity regardless.

## Approach

**Option B (strict):** Lift the minimum to 0, but close the loophole where staff could set `days = 0` with an amount override and still generate a charge. The seeding guard checks `days` directly, not the resolved amount.

## Changes

### 1. Backend — BranchMonthlyAmountController

**File:** `app/Http/Controllers/Kitchen/BranchMonthlyAmountController.php`

Both `store()` and `update()` validation rules:

- `days`: change `min:1` → `min:0`
- `amount`: add `Rule::prohibitedIf($request->integer('days') === 0)` — returns 422 with message *"Amount override is not allowed when school days is 0."* when violated

### 2. Backend — EnrollmentService and PaymentController::addRange()

**File:** `app/Services/EnrollmentService.php`

In `seedMonthlyPayments()`, before creating a `StudentMonthlyPayment` for a month, resolve the amount via `BranchMonthlyAmount::resolveAmount()`. If the resolved amount is `0` (equivalent to `days === 0`, since amount overrides are prohibited when days = 0), skip that month — no record is created.

**File:** `app/Http/Controllers/Kitchen/PaymentController.php` — `addRange()` method

The "Add Subscription Period" endpoint has **identical seeding logic** and must apply the same skip guard. Before creating a `StudentMonthlyPayment` in the loop, check the resolved amount; if `0`, skip that month.

Shared rules for both:
- `BranchMonthlyAmount::resolveAmount()` is unchanged — it already returns `0` correctly when `days = 0`.
- Only affects future enrollments and future period additions. Students already enrolled with existing payment records are unaffected.

### 3. POS Frontend — subscription-config page

**File:** `app/(kitchen)/references/subscription-config/page.tsx` (in `~/sunbites-pos`)

- Input: `min={0}` (was `min={1}`)
- Inline submit guard: `days < 0` (was `days < 1`), error message: *"Days must be between 0 and 31."*
- When `days` is set to 0: clear the amount override field and disable it; show helper text: *"No charge — month has no school activity."*

## Tests

### BranchMonthlyAmountTest (backend)

1. **New:** Admin can create a month config with `days = 0` — assert 201, record saved with `days = 0`.
2. **New:** Admin can update a month config to `days = 0` — assert 200, record updated.
3. **New:** `days = 0` with an `amount` override returns 422 with a validation error on `amount`.
4. **Update:** Existing min-days test updated to assert `days = 0` is now valid (was previously expecting 422).

### EnrollmentTest (backend)

1. **New:** Enrolling a subscription student when June has `days = 0` skips the June `StudentMonthlyPayment` record. Assert the count of seeded payments equals total school months minus the 0-day months.

### PaymentTest (backend)

1. **New:** `POST /api/v1/students/{student}/payments/range` when June has `days = 0` skips the June record and does not create a `StudentMonthlyPayment` for that month. Assert the `created` count and `skipped` count in the response exclude the 0-day month.

## Out of Scope

- No changes to `BranchMonthlyAmount::resolveAmount()` — it already returns `0` for a 0-day month correctly.
- No changes to portal-facing endpoints — days are never exposed to parents.
- No retroactive updates to already-enrolled students' existing payment records.
- No changes to `config/sunbites.php` defaults — the defaults remain at their current values; 0 is only set via branch overrides.
