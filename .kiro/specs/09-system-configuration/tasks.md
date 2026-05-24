# Tasks 09 — System Configuration

## 1. Database
- [x] Migration: create `system_configurations` table — `key` (string, unique), `value` (string), `type` (enum: integer/decimal/string), `label` (string), `description` (text, nullable), timestamps
- [x] Seeder: `SystemConfigurationSeeder` — insert `daily_meal_rate` (135, decimal), `credit_limit` (300, decimal), `loyalty_point_threshold` (1000, decimal) with labels and descriptions
- [x] Register seeder in `DatabaseSeeder`

## 2. Model
- [x] `SystemConfiguration` model — `$fillable`, type enum
- [x] `SystemConfiguration::getValue(key, default)` static helper — `Cache::rememberForever("system_config.{$key}", ...)`; casts value to correct PHP type
- [x] `static::updated()` boot hook — `Cache::forget("system_config.{$config->key}")` on save
- [x] `BranchMonthlyAmount::resolveAmount()` static method extracted from duplicate controller code (laravel-simplifier refactor)

## 3. Controller & Routes
- [x] `SystemConfigurationController::index()` — returns all configs; admin only
- [x] `SystemConfigurationController::update(string $key)` — validates value by type; updates; busts cache; returns updated record; admin only
- [x] Routes in `routes/kitchen-api.php` under `auth:sanctum + ability:staff + role:admin`:
  - `GET /api/v1/system-configurations`
  - `PUT /api/v1/system-configurations/{key}`

## 4. Replace config() Calls
- [x] `EnrollmentController` — replaced `config('sunbites.daily_meal_rate', 135)` with `SystemConfiguration::getValue()` (via `BranchMonthlyAmount::resolveAmount()`)
- [x] `PaymentController` — replaced; `resolveAmount()` removed and moved to `BranchMonthlyAmount` model; `updateAmount()` split from `toggle()` with dedicated route `PATCH .../amount`
- [x] `BranchMonthlyAmountController` — replaced all 3 `config()` calls
- [x] `config/sunbites.php` — `env()` wrappers removed; hardcoded fallback defaults only

## 5. Tests
- [x] `SystemConfigurationTest` (8 tests): list (admin only), update + cache flush, negative value rejected, non-numeric rejected, unknown key 404, manager/supervisor/cashier 403
- [x] `BranchMonthlyAmountTest` — 2 new tests: explicit `amount` override on store and update

## 6. Frontend — Types & API Service
- [x] `types/system-configuration.ts` — `SystemConfigType` union + `SystemConfiguration` interface
- [x] `lib/api/system-configurations.ts` — `systemConfigApi.list()`, `systemConfigApi.update(key, value)`
- [x] `lib/api/students.ts` — `updatePaymentAmount` hits dedicated `PATCH .../amount` endpoint

## 7. Frontend — System Settings Page
- [x] `app/(kitchen)/references/system-settings/page.tsx` — admin-only with three-part redirect guard; table of configs; `EditConfigDialog` with type-aware input and negative-value guard scoped to numeric types; "Saved" indicator on success
- [x] `app/(kitchen)/references/system-settings/loading.tsx` — 3-row skeleton

## 8. Frontend — Navigation
- [x] `components/layouts/kitchen-layout.tsx` — "System Settings" added to `referencesNav`; filtered to admin-only via `referencesNavFiltered`
- [x] `__tests__/mocks/handlers.ts` — handlers for `GET /system-configurations`, `PUT /system-configurations/:key`, `PATCH .../payments/:id/amount`
