---
inclusion: always
---

# Product Steering

## Purpose

Sunbites is a school canteen management system for Philippine private schools. It handles daily food orders, student wallet top-ups, credit (buy-now-pay-later) with settlement, monthly subscription payments, inventory, and parent visibility into their children's canteen spending.

## Users

Capabilities below are derived from the route middleware in `routes/kitchen-api.php` (the source of truth). Each role includes everything in the rows beneath it.

| User type | App | Can do (in addition to lower roles) |
|---|---|---|
| Admin | POS app | Branch management, system configuration, user update/deactivate/reactivate/password reset/branch assignment, **credit waive** (write-off), access to any branch (`access.any_branch`) |
| Manager | POS app | Menu item mutations, subscription config, meal-planner edits, inventory archive + ingredient mapping, list/create staff users, parent account management, monthly payment toggle/record/amount/void, subscription downgrade, send payment reminders, approve/reject pre-registrations, report exports, wallet/credit/daily-summary/activity/analytics reports |
| Supervisor | POS app | Void POS orders, inventory (view/adjust/create/edit/delete), **enrollment** and student management (edit, photo, status/type, QR regenerate, delete/restore), wallet top-up and **top-up void**, student contacts, branch monthly amounts + payment ranges, feedback inbox, dashboard, reminder lists, announcements, pre-registration review/edit/reactivate, view sales/students/inventory/billing/subscription reports (no export) |
| Cashier (any staff) | POS app | POS checkout, student lookup (QR/search), **inline wallet reload at POS**, transaction list, view student ledger, **settle credit** at the counter, view meal planner, reminder bell count, staff notification inbox |
| Parent | Parent portal | View linked students (wallet, ledger, activity, spending summary, payment history), set wallet-alert threshold, upload student photo, submit feedback, edit profile/photo/password, notifications; meal plan only if they have a subscription student |
| Public (no auth) | Portal / POS kiosk | Submit pre-registration (reCAPTCHA v3 + honeypot), list active branches, kiosk QR balance lookup |

Business rules on top of roles:
- Top-up void: a staff member cannot void a top-up they performed themselves; non-admins may only void same-day top-ups.
- Discounts at checkout: admin and manager only (enforced in `CheckoutController`).
- Only an admin may assign the admin role; at least one admin must remain; at least one branch must remain active.

## Value

- **For staff**: Streamlines daily canteen operations: QR-scan checkout, wallet top-ups, credit settlement and drawer reconciliation, subscription payment tracking, inventory, and reporting in one system.
- **For parents**: Transparency into their children's daily canteen spending, wallet/credit balance, and subscription payment status without visiting the school.

## Scope Boundaries

**In scope:**
- Student enrollment (subscription and non-subscription), including public pre-registration with staff approval
- POS checkout via QR scan or manual search; walk-in (no student) orders
- Student digital wallet (top-up, inline reload, deduction, top-up void)
- Student credit: charge at checkout when wallet is short, settle (cash/GCash/bank transfer/from wallet), admin waive
- Monthly subscription payment tracking, daily per-category subscription limits
- Inventory with menu-item ingredient mapping and automatic stock deduction on sale
- Weekly meal planner with per-week parent visibility
- Branch-level reports and analytics with Excel export
- Parent portal, feedback, announcements, payment reminders
- Real-time notifications (Reverb) plus transactional email
- Kiosk mode (student QR balance check)
- System configuration (business rules as DB values, editable by admin)

**Out of scope:**
- Online payment collection. Every top-up/settlement is recorded by staff; GCash and bank transfer are recorded by reference number, not processed.
- Student-facing application (the kiosk is a read-only lookup)
- SMS notifications
- Multi-school / cross-tenant operations (each deployment is one school; branches are the tenant boundary)

## Key Constraints

- School year runs **June through March** (10 months). April and May are off-season. School months, default day-counts, grade levels, and fallback business-rule defaults live in `config/sunbites.php`.
- `student_monthly_payments.year` stores the **calendar year** of the month (January 2027 → `2027`), not the school-year start.
- Students are either **subscription** (fixed monthly fee, `StudentMonthlyPayment` rows seeded at enrollment) or **non-subscription** (pay per purchase).
- Monthly amount = branch override in `branch_monthly_amounts`, else `days × daily_meal_rate`.
- Credit limit defaults to ₱300: checkout reads the `credit_limit` system configuration with a hard-coded fallback of 300. (The analytics report reads `config('sunbites.credit_limit')` instead; see Spec 16.)
- All staff operations are **branch-scoped**; a staff member without `access.any_branch` cannot access another branch's data.
- Non-subscription students must NEVER receive payment reminders.
- Currency is Philippine Peso (₱). Wallet amounts are stored as integers (centavos) by bavix/laravel-wallet; app tables store `decimal(…,2)`.

## Glossary

| Term | Meaning |
|---|---|
| Branch | A physical canteen location. The multi-tenancy boundary for staff data. |
| Subscription student | Enrolled for a period at a fixed monthly rate; pays per month in advance. |
| Non-subscription student | Buys individual items; no monthly commitment. |
| School month | One of the 10 months in the school year (June–March), each with a defined day-count. |
| Monthly payment | A `StudentMonthlyPayment` row: one month's subscription fee (`paid` / `unpaid` / `voided`). |
| Wallet | Student's prepaid balance; deducted at checkout; topped up by staff. |
| Credit | Amount a student owes when a wallet purchase exceeded the balance (`students.credit_balance`, ledger in `credit_transactions`). |
| Unified ledger | Wallet transactions + credit entries merged into one chronological stream (Spec 14). |
| Top-up void | Staff reversal of a specific wallet deposit (Spec 15); any spent shortfall becomes credit. |
| QR code | Per-student unique code (`SB-` prefix) used for checkout and kiosk lookup. |
| POS | Point-of-sale screen used for daily checkout. |
| Pre-registration | Public enrollment request awaiting staff approval; expires after `pre_registration_expiry_days`. |
| Bell count | Number of subscription parents with a started, unpaid month not yet reminded. |
