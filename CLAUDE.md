# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with this repository.

## What This Project Is

**Sunbites API** is a **pure JSON REST API** built with Laravel 13. There is no frontend, no Inertia, and no server-rendered pages. All routes under `routes/api.php` return JSON. The only Blade templates are transactional emails in `resources/views/emails/`.

Two external frontend applications consume this API:
- `~/sunbites-pos` — POS & admin app (Next.js)
- `~/sunbites-portal` — Parent portal (Next.js)

---

## Commands

All commands must be run through Sail:

```bash
# Start / stop
vendor/bin/sail up -d
vendor/bin/sail stop

# Run all tests
vendor/bin/sail artisan test --compact

# Run a single test file
vendor/bin/sail artisan test --compact tests/Feature/EnrollmentTest.php

# Run by test name filter
vendor/bin/sail artisan test --compact --filter=testSubscriptionStudent

# Format PHP after changes
vendor/bin/sail bin pint --dirty --format agent

# Inspect routes
vendor/bin/sail artisan route:list --path=api --except-vendor
```

---

## Route Architecture

All routes are JSON API routes. There are two consumer groups:

| File | Prefix | Consumer | Auth guard |
|---|---|---|---|
| `routes/kitchen-api.php` | `/api/v1/` | POS / admin app | `auth:sanctum` + ability `staff` |
| `routes/portal-api.php` | `/api/v1/portal/` | Parent portal | `auth:parents` + ability `parent` |

### Staff Role Hierarchy

Permission middleware uses `role:` from spatie/laravel-permission:

```
admin > manager > supervisor > (regular staff)
```

Most mutations require `admin|manager`. Read-heavy endpoints allow `admin|manager|supervisor`. All staff see basic POS endpoints.

---

## Key Architecture Patterns

### Branch Scoping

Most models use the `HasBranch` trait, which applies a global `BranchScope`. This automatically filters all queries by the active branch. The active branch is set per-request (via `X-Branch-Id` header) and bound to the service container as `app('active_branch')`.

To bypass branch scoping: `Student::withoutBranch()->get()`

### Two Auth Guards

- Staff (`User` model) → `auth:sanctum` guard + token ability `staff`
- Parents (`ParentUser` model, `parents` table) → `auth:parents` guard + token ability `parent`

Never mix guards. Staff endpoints must use `auth:sanctum` and parent endpoints must use `auth:parents`.

### Student Wallet

Students implement `bavix/laravel-wallet`. Balances are stored in the `wallets` table. All balance mutations (top-up, checkout deductions, credit settlement) must go through wallet transactions — never update balance directly.

### Activity Logging

Key models use `spatie/laravel-activitylog` via the `LogsActivity` trait. Auth events, enrollment, payment changes, and checkout are all logged. When writing controller code that creates/updates/deletes significant records, check whether the model already logs automatically before adding manual `activity()` calls.

---

## Domain Model Overview

| Model | Key relationships | Notes |
|---|---|---|
| `Student` | `BelongsTo Branch`, `HasMany Orders`, `HasMany StudentMonthlyPayments`, `BelongsToMany Parents` | Has wallet, soft-deleted, branch-scoped |
| `User` | Staff; `BelongsToMany Branches`, has Spatie roles | Soft-deleted |
| `ParentUser` | `BelongsToMany Students` (via `parent_student` pivot) | Table is `parents`, separate guard |
| `Order` / `OrderItem` | `BelongsTo Student`, `BelongsTo Branch` | Branch-scoped |
| `PosMenuItem` | `BelongsToMany InventoryItem` (ingredient mapping) | Branch-scoped |
| `InventoryItem` | `HasMany InventoryLogs` | Branch-scoped, soft-archivable |
| `StudentMonthlyPayment` | `BelongsTo Student` | Seeded at enrollment for subscription students |
| `BranchMonthlyAmount` | `BelongsTo Branch` | Per-branch monthly fee overrides |

---

## Enrollment & Subscription Business Rules

- **Subscription students** get `StudentMonthlyPayment` records seeded for each `SchoolMonth` at enrollment time.
- The school months and default day-counts live in `config/sunbites.php`.
- Branch-level overrides for monthly amounts are stored in `branch_monthly_amounts`.
- Credit limit is `config('sunbites.credit_limit')` (default 300).

---

## Testing Conventions

- Use `RefreshDatabase` on every Feature test.
- Always `actingAs($user, 'sanctum')` or `actingAs($parent, 'parents')` — never test protected endpoints without auth.
- Use model factories and existing factory states. Check factory states before setting attributes manually.
- Test files are under `tests/Feature/` by domain area (e.g. `tests/Feature/Kitchen/`, `tests/Feature/Portal/`, `tests/Feature/Auth/`).

---

## What NOT to Do

- Do **not** use `Inertia::render()` or return Inertia responses — this is a pure JSON API.
- Do **not** add frontend routes or Blade views (except emails).
- Do **not** bypass branch scoping unless there is a specific cross-branch reason.
- Do **not** mutate wallet balances directly — always use the `bavix/laravel-wallet` API.
- Do **not** commit wayfinder-generated TypeScript files to this repo — those belong in the frontend apps.

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.5. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `vendor/bin/sail composer show --direct` to list direct dependencies with versions, or `vendor/bin/sail composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `vendor/bin/sail npm run build` or ask the user to run `vendor/bin/sail npm run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `vendor/bin/sail artisan route:list`). Use `vendor/bin/sail artisan list` to discover available commands and `vendor/bin/sail artisan [command] --help` to check parameters.
- Inspect routes with `vendor/bin/sail artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `vendor/bin/sail artisan config:show app.name`, `vendor/bin/sail artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `vendor/bin/sail artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `vendor/bin/sail artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== sail rules ===

# Laravel Sail

- This project runs inside Laravel Sail's Docker containers. You MUST execute all commands through Sail.
- Start services using `vendor/bin/sail up -d` and stop them with `vendor/bin/sail stop`.
- Open the application in the browser by running `vendor/bin/sail open`.
- Always prefix PHP, Artisan, Composer, and Node commands with `vendor/bin/sail`. Examples:
    - Run Artisan Commands: `vendor/bin/sail artisan migrate`
    - Install Composer packages: `vendor/bin/sail composer install`
    - Execute Node commands: `vendor/bin/sail npm run dev`
    - Execute PHP scripts: `vendor/bin/sail php [script]`
- View all available Sail commands by running `vendor/bin/sail` without arguments.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `vendor/bin/sail artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `vendor/bin/sail artisan list` and check their parameters with `vendor/bin/sail artisan [command] --help`.
- If you're creating a generic PHP class, use `vendor/bin/sail artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `vendor/bin/sail artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `vendor/bin/sail artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/sail bin pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/sail bin pint --test --format agent`, simply run `vendor/bin/sail bin pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This project uses PHPUnit. Create tests with `vendor/bin/sail artisan make:test --phpunit {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `vendor/bin/sail artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/sail bin phpunit` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

=== maatwebsite/excel/core rules ===

# Laravel Excel

- Use `maatwebsite/excel` for spreadsheet exports, imports, queued spreadsheet work, CSV handling, and PhpSpreadsheet integration in Laravel applications.
- Prefer explicit export/import classes with package concerns over ad-hoc spreadsheet generation in controllers, jobs, or commands.
- Activate the `laravel-excel` skill when working with `Excel::download()`, `Excel::store()`, `Excel::queue()`, `Excel::raw()`, `Excel::import()`, `Excel::toArray()`, `Excel::toCollection()`, export/import concerns, queued imports/exports, validation, CSV settings, styling, events, formulas, charts, drawings, multiple sheets, mapped cells, macros, config, cache, transactions, temporary files, or `Excel::fake()`.
- For broad docs, all-feature tasks, or missing-feature audits, use the skill's `references/package.md` feature matrix before answering.
- For large datasets, prefer `FromQuery` with queued exports or `WithChunkReading` and `WithBatchInserts` for imports.
- Test spreadsheet behavior with `Excel::fake()` when asserting dispatch/download/store/import intent, and inspect generated files only when cell contents, formatting, sheets, or writer behavior must be proven.

=== spatie/laravel-activitylog/core rules ===

## Activitylog

- `spatie/laravel-activitylog` logs model events and manual activities to a database table, with subjects, causers, tracked attribute changes, and custom properties.
- Always activate the `activitylog-development` skill when working with activity logging or any code that uses the `LogsActivity`, `CausesActivity`, or `HasActivity` traits, `LogOptions`, the `Activity` model, or the `activity()` helper.
- If the skill is unavailable, read `vendor/spatie/laravel-activitylog/resources/boost/skills/activitylog-development/SKILL.md` before making changes.

</laravel-boost-guidelines>
