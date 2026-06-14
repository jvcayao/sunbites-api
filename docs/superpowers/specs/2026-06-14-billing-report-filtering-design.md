# Billing Report Filtering — Design Spec

**Date:** 2026-06-14
**Scope:** `BillingReportController` (`/api/v1/reports/billing`)

---

## Goal

Bring the billing report's filtering up to the same standard as the sales report: add `search`, `recorded_by`, and `recorded_from`/`recorded_to` filters, default the view to the current school month/year, and eliminate the existing query duplication so future filters cost one change instead of three.

---

## Current State

`BillingReportController` has two methods — `index()` and `export()` — that each build the same base query by hand. `index()` builds it twice (once for data, once for summary stats). Adding a filter today means touching three places. The existing filters are: `year`, `school_month`, `status`, `grade_level`, `per_page`.

---

## New Filters

| Param | Type | Validation | Default | Notes |
|---|---|---|---|---|
| `search` | `string` | `nullable`, `max:100` | — | Case-insensitive match on student `first_name`, `last_name`, or `student_number` |
| `school_month` | `string` | `nullable`, enum value | Current calendar month (e.g. `june`) | Applied to `StudentMonthlyPayment.school_month` |
| `year` | `integer` | `nullable`, `2020–2100` | Current school year start¹ | Applied to `StudentMonthlyPayment.year` |
| `status` | `string` | `nullable`, `paid`/`unpaid` | — | Already exists |
| `grade_level` | `string` | `nullable` | — | Already exists |
| `recorded_by` | `integer` | `nullable`, `exists:users,id` | — | Filters on `recorded_by` column; equivalent of `cashier_id` in sales |
| `recorded_from` | `date` | `nullable` | — | Lower bound on `recorded_at` |
| `recorded_to` | `date` | `nullable` | — | Upper bound on `recorded_at` |
| `per_page` | `integer` | `nullable`, `1–100` | `50` | Already exists |

¹ School year start: `now()->month >= 6 ? now()->year : now()->year - 1`. This matches the logic already used in `ReminderController` for the school year calendar.

`recorded_from`/`recorded_to` filter on `recorded_at`, which is only set on paid payments. Combining them with `status=unpaid` will silently return empty results — no special handling needed.

---

## Structural Change — `buildQuery()`

Extract a private method:

```php
private function buildQuery(array $validated): Builder
```

This method applies all filters (student scoping by branch, `school_month`, `year`, `status`, `grade_level`, `search`, `recorded_by`, `recorded_from`/`recorded_to`) and returns a `Builder` for `StudentMonthlyPayment`. No eager loading or ordering inside — callers add those.

`index()` calls `buildQuery()` twice: once with eager loads + ordering + `paginate()` for the response data, and once with `selectRaw()` for the summary stats.

`export()` calls `buildQuery()` once with eager loads + ordering + `get()`.

---

## Default Behaviour (Current School Month)

When `school_month` and `year` are not provided, both default to the current school period — matching the sales report's pattern of defaulting to today. The frontend can load the billing report with no query params and immediately see data for the current school month.

```
school_month default = SchoolMonth::from(strtolower(now()->format('F')))->value
year default         = now()->month >= 6 ? now()->year : now()->year - 1
```

---

## Export Parity

`export()` uses the same `buildQuery()` as `index()`, so the Excel download always reflects exactly what is on screen — same as the sales export.

---

## Tests

New test cases to add to `BillingReportTest`:

- `search` by first name returns only matching students
- `search` by student number returns only that student
- `search` with no match returns empty data
- `recorded_by` returns only payments recorded by that staff member
- `recorded_from` excludes payments recorded before that date
- `recorded_to` excludes payments recorded after that date
- `recorded_from` + `recorded_to` together return only payments within the range
- No `school_month`/`year` params defaults to the current school month and year
- All existing tests continue to pass

---

## Files Affected

| File | Change |
|---|---|
| `app/Http/Controllers/Kitchen/BillingReportController.php` | Add `buildQuery()`, add new filter params, apply defaults |
| `tests/Feature/Reports/BillingReportTest.php` | Add new test cases |
