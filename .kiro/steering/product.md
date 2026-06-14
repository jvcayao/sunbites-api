---
inclusion: always
---

# Product Steering

## Purpose

Sunbites is a school canteen management system for Philippine private schools. It handles daily food orders, student wallet top-ups, monthly subscription payments, inventory, and parent visibility into their children's canteen spending.

## Users

| User type | App | Description |
|---|---|---|
| Admin | POS app | Full access to all settings, users, reports, configuration |
| Manager | POS app | Day-to-day ops: enrollment, payments, reports — no user management or system config |
| Supervisor | POS app | Read access to reports + reminders; no mutations |
| Cashier | POS app | POS checkout only; no enrollment or reports |
| Parent | Parent portal | Read-only view of linked students' spending; receives payment reminders |

## Value

- **For staff**: Streamlines daily canteen operations — QR-scan checkout, wallet top-ups, subscription payment tracking, and inventory management in one system.
- **For parents**: Transparency into their children's daily canteen spending and subscription payment status without needing to visit the school.

## Scope Boundaries

**In scope:**
- Student enrollment (subscription and non-subscription)
- POS checkout via QR scan or manual student search
- Student digital wallet (deposits, deductions, credit)
- Monthly subscription payment tracking
- Inventory and menu management
- Branch-level sales and student reports with Excel export
- Parent authentication and linked-student dashboard
- Payment reminders sent from staff to parents
- System configuration (business rules as DB values, editable by admin)

**Out of scope (as of specs 01–10):**
- Online payment collection (wallet top-ups are cash-only, recorded by staff)
- Student-facing application
- SMS notifications (database notifications only; Reverb covers real-time)
- Multi-school / cross-tenant operations (each deployment is one school)

## Key Constraints

- School year runs **June through March** (10 months). April and May are off-season. School months are defined in `config/sunbites.php`.
- Students are either **subscription** (fixed monthly fee, `StudentMonthlyPayment` records seeded at enrollment) or **non-subscription** (pay per meal).
- Credit limit is ₱300 by default, configurable via system settings (`credit_limit`).
- All operations are **branch-scoped** — a staff member logged into Branch A cannot see Branch B data.
- Non-subscription students must NEVER receive payment reminders.
- Currency is Philippine Peso (₱). Amounts stored as integers in wallet (centavos) via bavix/laravel-wallet.

## Glossary

| Term | Meaning |
|---|---|
| Branch | A physical canteen location (e.g. Iloilo Main, Bacolod). Each school may have multiple branches. |
| Subscription student | Enrolled for the full school year at a fixed monthly rate; pays in advance. |
| Non-subscription student | Buys individual meals; no monthly commitment. |
| School month | One of the 10 months in the school year (June–March), each with a defined day-count for computing the monthly rate. |
| Monthly payment | A `StudentMonthlyPayment` record representing one month's subscription fee for a student. |
| Wallet | Student's prepaid balance; deducted at checkout; topped up by staff. |
| QR code | Per-student unique code used for fast checkout identification. |
| POS | Point-of-sale interface used by cashiers and managers for daily checkout. |
| Bell count | The number of subscription parents not yet sent a reminder for the upcoming school month. |
