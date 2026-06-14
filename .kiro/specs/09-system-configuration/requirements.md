# Spec 09 — System Configuration

## Overview

System Configuration is a database-backed key-value store that replaces hard-coded `config/sunbites.php` values for business-critical settings that admins may need to change without a code deployment.

---

## Data Model

```
system_configurations
  id
  key          (string, unique)   — machine key e.g. "daily_meal_rate"
  value        (string)           — stored as string; cast to correct type on read
  type         (enum: integer, decimal, string) — governs cast and validation
  label        (string)           — human label e.g. "Daily Meal Rate (₱)"
  description  (text, nullable)   — optional help text shown in the UI
  created_at, updated_at
```

### Seeded Values

| Key | Value | Type | Label |
|---|---|---|---|
| `daily_meal_rate` | `135` | `decimal` | Daily Meal Rate (₱) |
| `credit_limit` | `300` | `decimal` | Credit Limit (₱) |
| `loyalty_point_threshold` | `1000` | `decimal` | Loyalty Point Threshold (₱) |
| `payment_reminder_days` | `14` | `integer` | Payment Reminder Days (Spec 10) |

---

## SystemConfiguration Model

A static helper `SystemConfiguration::getValue(string $key, mixed $default = null): mixed` reads from the database, casts to the correct type, and caches per-request via `Cache::rememberForever("system_config.{$key}", ...)`. Cache is busted when a record is updated.

All existing `config('sunbites.daily_meal_rate', 135)` calls in `EnrollmentController`, `PaymentController`, and `BranchMonthlyAmountController` are replaced with `SystemConfiguration::getValue('daily_meal_rate', 135)`.

---

## API Routes

All under `auth:sanctum` + `ability:staff`.

| Method | Route | Roles | Description |
|---|---|---|---|
| GET | `/api/v1/system-configurations` | admin | List all configuration entries |
| PUT | `/api/v1/system-configurations/{key}` | admin | Update a configuration value |

---

## Frontend

Accessible at: `pos.sunbites.com.ph/references/system-settings`
Roles: Admin only — non-admins are redirected to `/dashboard`

### System Settings Page

- Lists all configuration entries in a table (Label, Current Value, Description, Actions)
- Each row has an `[Edit]` button that opens an inline dialog
- Edit dialog: shows label, description, current value, and a single input (number for integer/decimal, text for string)
- On save: `PUT /api/v1/system-configurations/{key}` with `{ value }`
- On success: invalidates `["system-configurations"]` query
- Link in References nav (visible to admin only)

---

## Requirements

- [ ] Migration: `system_configurations` table with `key` (string, unique), `value` (string), `type` (enum: integer/decimal/string), `label` (string), `description` (text, nullable)
- [ ] Seeder: seed `daily_meal_rate`, `credit_limit`, `loyalty_point_threshold` with default values and labels
- [ ] `SystemConfiguration` model with `getValue(key, default)` static helper backed by `Cache::rememberForever`; cache busted on `updated` model event
- [ ] `SystemConfigurationController::index()` — returns all configs; admin only
- [ ] `SystemConfigurationController::update(key)` — validates `value` against type; updates record; busts cache; admin only
- [ ] Replace all `config('sunbites.daily_meal_rate', 135)` calls in app code with `SystemConfiguration::getValue('daily_meal_rate', 135)`
- [ ] `config/sunbites.php` retains `daily_meal_rate`, `credit_limit`, `loyalty_point_threshold` as hardcoded fallback defaults only (remove the `env()` wrappers — the DB is the source of truth; config is last-resort fallback)
- [ ] Feature tests: `SystemConfigurationTest` — list (admin only), update value, invalid type rejected, non-admin 403
- [ ] Frontend: `types/system-configuration.ts` — `SystemConfiguration` type
- [ ] Frontend: `lib/api/system-configurations.ts` — `systemConfigApi.list()`, `systemConfigApi.update(key, value)`
- [ ] Frontend: `app/(kitchen)/references/system-settings/page.tsx` — admin-only; table of all settings; edit dialog per row
- [ ] Frontend: `app/(kitchen)/references/system-settings/loading.tsx` — skeleton
- [ ] Frontend: `components/layouts/kitchen-layout.tsx` — add "System Settings" to References nav, visible to admin only
