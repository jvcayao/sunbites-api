# Laravel Cloud Deployment — Full Process & Troubleshooting Record

This document covers the complete deployment of the Sunbites API to Laravel Cloud, including every issue encountered and how it was resolved. It serves as a runbook for future deployments and a reference for the team.

---

## Environments

| Environment | URL | Branch | Deploy |
|-------------|-----|--------|--------|
| Staging | `https://api-staging.sunbites.com.ph` | `staging` | Auto on push |
| Production | `https://api.sunbites.com.ph` | `main` | Manual `workflow_dispatch` |

Laravel Cloud app name: **Sunbites API Prod**

---

## CI/CD Pipeline

Both environments use GitHub Actions. Workflows are in `.github/workflows/`.

### How staging deploys

Push any commit to the `staging` branch — GitHub Actions automatically runs tests then deploys.

### How production deploys

1. Go to GitHub → Actions → **Deploy to Production** → **Run workflow**
2. Type `DEPLOY` in the confirmation field
3. Approve the environment protection gate
4. Monitor for success

### Authenticating the CLI in GitHub Actions

The Laravel Cloud CLI token format (`2902|TOKEN`) contains a `|` pipe character, which the shell interprets as a pipe operator. **Do not** use `cloud auth:token --add $TOKEN` in CI — it hangs or misparses the token.

Instead, write the config file directly:

```yaml
- name: Authenticate with Laravel Cloud
  env:
    CLOUD_TOKEN: ${{ secrets.CLOUD_API_TOKEN }}
  run: |
    mkdir -p ~/.config/cloud
    printf '{"api_tokens":["%s"]}' "$CLOUD_TOKEN" > ~/.config/cloud/config.json
```

### Build command

The deploy build command must only run `composer install`. Do **not** include `npm ci && npm run build` — the API is backend-only with no frontend assets to compile. Including it causes the build to fail.

Correct build command:
```
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
```

---

## Issues Encountered and Fixes

### 1. `cloud auth:token --add` fails in CI

**Symptom:** GitHub Actions step hangs or exits with error when adding the Cloud API token.

**Cause:** The token format `2902|TOKEN` uses `|` which the shell treats as a pipe operator.

**Fix:** Write `~/.config/cloud/config.json` directly using `printf`:
```yaml
printf '{"api_tokens":["%s"]}' "$CLOUD_TOKEN" > ~/.config/cloud/config.json
```

---

### 2. Vite build failure during deploy

**Symptom:** Deploy fails with Vite/npm errors during the build phase.

**Cause:** The build command included `npm ci && npm run build` but the API has no frontend to compile.

**Fix:** Remove npm commands from the build command. Use only:
```
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
```

---

### 3. `DB_CONNECTION` defaulting to SQLite on Laravel Cloud

**Symptom:** Migrations fail with SQLite-specific errors on the Cloud environment even though a MySQL cluster is provisioned.

**Cause:** `DB_CONNECTION` was not set in the environment — Laravel defaulted to `sqlite` from `.env.example`.

**Fix:**
```bash
cloud environment:variables staging --action=set --key=DB_CONNECTION --value=mysql --force -n
```

---

### 4. MySQL unique index name too long (64-character limit)

**Symptom:**
```
SQLSTATE[42000]: Syntax error or access violation: 1059
Identifier name '...' is too long
```

**Cause:** The auto-generated unique index name for `weekly_meal_plans` was 71 characters. MySQL 8.4 enforces a 64-character limit on identifier names.

**Fix:** Provide an explicit short name in the migration:
```php
// Before (auto-generated — 71 chars, fails on MySQL)
$table->unique(['branch_id', 'school_month', 'week_number', 'day_of_week']);

// After (explicit short name — passes MySQL)
$table->unique(['branch_id', 'school_month', 'week_number', 'day_of_week'], 'weekly_meal_plans_unique');
```

---

### 5. Dirty database state after partial migration failure

**Symptom:** Subsequent migration runs fail because tables or columns from a previously failed migration already exist.

**Cause:** MySQL DDL statements (`ALTER TABLE`, `CREATE TABLE`) are **not transactional** — unlike SQLite, a failed migration leaves the database in a partially migrated state.

**Fix (one-time recovery):** Run `migrate:fresh` on the affected environment. This drops all tables and re-runs all migrations from scratch. Only safe when there is no real data to preserve.

```bash
cloud command:run staging --cmd='php artisan migrate:fresh --force' -n
```

After recovery, restore the deploy command to the standard `php artisan migrate --force`.

---

### 6. Migration fails — `dropUnique` before `unique` on MySQL

**Symptom:**
```
SQLSTATE[42000]: Can't DROP INDEX; check that column/key exists
```

**Cause:** The migration called `dropUnique` on the old constraint before adding the new one. On MySQL (non-transactional DDL), this fails because the new unique constraint the app depends on doesn't exist yet if the drop step errors.

**Fix:** Reorder — add the new unique constraint first, then drop the old one:
```php
// Correct order
$table->unique(['student_id', 'school_month', 'year']);
$table->dropUnique(['student_id', 'school_month']);
```

This applies to both:
- `2026_05_24_044403_add_year_and_days_to_branch_monthly_amounts_table.php`
- `2026_05_24_044404_add_year_to_student_monthly_payments_table.php`

---

### 7. CORS errors from frontend apps

**Symptom:** Browser shows `CORS error` on login or API requests from `pos-staging.sunbites.com.ph`.

**Cause:** `config/cors.php` had a hardcoded `allowed_origins` array that only included production domains. The staging subdomains were never added. Setting `CORS_ALLOWED_ORIGINS` via env var had no effect because the config file didn't read from it.

**Fix:** Update `config/cors.php` to include staging domains and support the env var:
```php
'allowed_origins' => array_filter(array_merge(
    [
        'http://localhost:3000',
        'http://localhost:3001',
        'https://pos.sunbites.com.ph',
        'https://portal.sunbites.com.ph',
        'https://pos-staging.sunbites.com.ph',
        'https://portal-staging.sunbites.com.ph',
    ],
    array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS', '')))
)),
```

Also set the required Sanctum and session env vars on each environment:
```bash
cloud environment:variables staging --action=set --key=SANCTUM_STATEFUL_DOMAINS --value="pos-staging.sunbites.com.ph,portal-staging.sunbites.com.ph,localhost,localhost:3000,127.0.0.1" --force -n
cloud environment:variables staging --action=set --key=SESSION_DOMAIN --value=".sunbites.com.ph" --force -n
```

---

### 8. `cloud deploy:monitor` failing after deploy

**Symptom:** The `cloud deploy:monitor -n` step in GitHub Actions exits with an error immediately after `cloud deploy`.

**Cause:** `cloud deploy` already streams deployment status inline and waits for completion. There is nothing left to monitor by the time `deploy:monitor` runs.

**Fix:** Remove the `deploy:monitor` step from both workflow files. The deploy step is self-contained.

---

### 9. Laravel Cloud command runner is not interactive

**Symptom:** Running `php artisan sunbites:create-admin` via `cloud command:run` hangs or fails because it uses `ask()`, `secret()`, and `choice()` prompts.

**Cause:** The Cloud command runner does not support interactive TTY input.

**Fix:** Use `cloud tinker` with `--code` to run PHP directly:
```bash
cloud tinker production --code='
$user = App\Models\User::create([
    "name" => "Admin",
    "email" => "admin@example.com",
    "password" => bcrypt("your-password"),
]);
$user->assignRole("admin");
echo "Created: " . $user->email . "\n";
' --timeout=60 -n
```

---

### 10. New env vars don't take effect without a redeploy

**Symptom:** Setting an env var via `cloud environment:variables --action=set` returns success, but `config()` in tinker still shows the old value.

**Cause:** The running server instances are not restarted when env vars are changed via CLI. The new values are stored in Cloud's config but not propagated to live processes.

**Fix:** Always redeploy after setting env vars, or run `php artisan config:clear` to clear the config cache (this works for uncached values but a redeploy is more reliable):
```bash
cloud deploy "Sunbites API Prod" staging -n
# or for just clearing cache:
cloud command:run staging --cmd='php artisan config:clear' -n
```

---

### 11. User has no role — "User does not have the right roles"

**Symptom:** Logged-in user sees "Failed to load users" with a 403 response: `"User does not have the right roles."`

**Cause:** The user was created but never assigned a Spatie role. The `sunbites:create-admin` Artisan command handles this, but users created manually via tinker skip the role assignment step.

**Fix:** Assign the role via tinker:
```bash
cloud tinker production --code='
App\Models\User::all()->each(function($user) {
    $user->assignRole("admin");
    echo $user->email . " => admin\n";
});
' --timeout=60 -n
```

---

### 12. User has no branch assigned

**Symptom:** After login, user sees: `"Your account has no branch assigned. Contact your administrator."`

**Cause:** Users are branch-scoped via a `branch_user` pivot table. Newly created users have no branch attached.

**Important:** The `users` table does **not** have a `branch_id` column — it is a many-to-many relationship through `branch_user`.

**Fix:**
```bash
cloud tinker production --code='
$branch = App\Models\Branch::where("name", "like", "%ANTIPOLO%")->firstOrFail();
App\Models\User::all()->each(function($user) use ($branch) {
    $user->branches()->syncWithoutDetaching([
        $branch->id => ["assigned_at" => now(), "assigned_by" => null]
    ]);
    echo "Assigned " . $user->email . " to " . $branch->name . "\n";
});
' --timeout=60 -n
```

---

### 13. Activation email links to localhost

**Symptom:** Parent invitation email contains an activation link pointing to `http://localhost:3000/activate?token=...`

**Cause:** `config/app.php` defines `portal_url` using `env('PORTAL_APP_URL', 'http://localhost:3000')`. The `PORTAL_APP_URL` env var was not set on the Cloud environments.

**Fix:**
```bash
cloud environment:variables production --action=set --key=PORTAL_APP_URL --value="https://portal.sunbites.com.ph" --force -n
cloud environment:variables staging --action=set --key=PORTAL_APP_URL --value="https://portal-staging.sunbites.com.ph" --force -n
```

Then redeploy (env vars require a redeploy to propagate):
```bash
cloud deploy "Sunbites API Prod" production -n
cloud deploy "Sunbites API Prod" staging -n
```

---

### 14. Emails not sending — mailer defaulting to `log`

**Symptom:** Invitation emails show "Success" in the POS but are never received.

**Cause:** `config/mail.php` defaults to `env('MAIL_MAILER', 'log')`. Without `MAIL_MAILER` set, all emails are written to the log file instead of being sent.

**Fix:**
```bash
cloud environment:variables production --action=set --key=MAIL_MAILER --value="resend" --force -n
cloud environment:variables production --action=set --key=MAIL_FROM_ADDRESS --value="noreply@sunbites.com.ph" --force -n
cloud environment:variables production --action=set --key=MAIL_FROM_NAME --value="Sunbites" --force -n
```

---

### 15. Resend domain not verified — emails silently rejected

**Symptom:** `MAIL_MAILER=resend` is set and `RESEND_API_KEY` is configured, but emails are still not received.

**Cause:** The sending domain (`sunbites.com.ph`) was not verified in Resend. Resend silently rejects sends from unverified domains.

**Fix:**
1. Go to [resend.com/domains](https://resend.com/domains)
2. Add `sunbites.com.ph` and click the domain to get DNS records
3. Add the provided MX, TXT (SPF), and TXT (DKIM) records in your DNS provider
4. Click the verify (▶) button in Resend to trigger verification
5. Wait for all records to show as verified

To test before domain is verified, temporarily use Resend's test address:
```bash
cloud environment:variables production --action=set --key=MAIL_FROM_ADDRESS --value="onboarding@resend.dev" --force -n
```

---

## Commands Run During Initial Deployment

This is the chronological record of every `cloud` CLI command executed to get both environments live.

### Step 1 — Install Laravel Cloud CLI

```bash
composer global require laravel/cloud-cli --no-interaction
echo "$HOME/.composer/vendor/bin" >> $GITHUB_PATH  # in CI
```

### Step 2 — Authenticate

```bash
# Locally
cloud auth -n

# In GitHub Actions (token has | in it — write config directly)
printf '{"api_tokens":["%s"]}' "$CLOUD_TOKEN" > ~/.config/cloud/config.json
```

### Step 3 — Fix MySQL default connection on staging

```bash
cloud environment:variables staging --action=set --key=DB_CONNECTION --value=mysql --force -n
```

### Step 4 — Recover from dirty DB state (migrate:fresh)

Run only once after a partial migration failure. Drops everything and re-runs from scratch.

```bash
cloud command:run staging --cmd='php artisan migrate:fresh --force' -n
```

### Step 5 — Deploy staging

```bash
cloud deploy "Sunbites API Prod" staging -n
```

### Step 6 — Deploy production

```bash
cloud deploy "Sunbites API Prod" production -n
```

### Step 7 — Fix CORS and Sanctum on staging

```bash
cloud environment:variables staging --action=set --key=SANCTUM_STATEFUL_DOMAINS --value="pos-staging.sunbites.com.ph,portal-staging.sunbites.com.ph,localhost,localhost:3000,127.0.0.1" --force -n
cloud environment:variables staging --action=set --key=CORS_ALLOWED_ORIGINS --value="https://pos-staging.sunbites.com.ph,https://portal-staging.sunbites.com.ph,http://localhost:3000,http://localhost:3001" --force -n
cloud environment:variables staging --action=set --key=SESSION_DOMAIN --value=".sunbites.com.ph" --force -n
cloud command:run staging --cmd='php artisan config:clear' -n
```

### Step 8 — Fix CORS and Sanctum on production

```bash
cloud environment:variables production --action=set --key=SANCTUM_STATEFUL_DOMAINS --value="pos.sunbites.com.ph,portal.sunbites.com.ph,localhost,localhost:3000,127.0.0.1" --force -n
cloud environment:variables production --action=set --key=CORS_ALLOWED_ORIGINS --value="https://pos.sunbites.com.ph,https://portal.sunbites.com.ph,http://localhost:3000,http://localhost:3001" --force -n
cloud environment:variables production --action=set --key=SESSION_DOMAIN --value=".sunbites.com.ph" --force -n
cloud command:run production --cmd='php artisan config:clear' -n
```

### Step 9 — Create first admin user via tinker (non-interactive)

The `sunbites:create-admin` Artisan command uses interactive prompts — not supported in Cloud. Use tinker instead:

```bash
cloud tinker production --code='
$user = App\Models\User::create([
    "name" => "Admin",
    "email" => "admin@sunbites.com.ph",
    "password" => bcrypt("your-secure-password"),
]);
$user->assignRole("admin");
echo "Created: " . $user->email . "\n";
' --timeout=60 -n
```

### Step 10 — Assign branch to users

```bash
cloud tinker production --code='
$branch = App\Models\Branch::where("name", "like", "%ANTIPOLO%")->firstOrFail();
App\Models\User::all()->each(function($user) use ($branch) {
    $user->branches()->syncWithoutDetaching([
        $branch->id => ["assigned_at" => now(), "assigned_by" => null]
    ]);
    echo "Assigned " . $user->email . " to " . $branch->name . "\n";
});
' --timeout=60 -n
```

### Step 11 — Assign admin role to users

```bash
cloud tinker staging --code='
App\Models\User::all()->each(function($user) {
    $user->assignRole("admin");
    echo $user->email . " => admin\n";
});
' --timeout=60 -n
```

### Step 12 — Configure email (Resend)

```bash
# Both environments
cloud environment:variables production --action=set --key=MAIL_MAILER --value="resend" --force -n
cloud environment:variables production --action=set --key=MAIL_FROM_ADDRESS --value="noreply@sunbites.com.ph" --force -n
cloud environment:variables production --action=set --key=MAIL_FROM_NAME --value="Sunbites" --force -n
cloud environment:variables production --action=set --key=RESEND_API_KEY --value="your-key-here" --force -n

cloud environment:variables staging --action=set --key=MAIL_MAILER --value="resend" --force -n
cloud environment:variables staging --action=set --key=MAIL_FROM_ADDRESS --value="noreply@sunbites.com.ph" --force -n
cloud environment:variables staging --action=set --key=MAIL_FROM_NAME --value="Sunbites" --force -n
cloud environment:variables staging --action=set --key=RESEND_API_KEY --value="your-key-here" --force -n
```

### Step 13 — Fix parent activation link URL

```bash
cloud environment:variables production --action=set --key=PORTAL_APP_URL --value="https://portal.sunbites.com.ph" --force -n
cloud environment:variables staging --action=set --key=PORTAL_APP_URL --value="https://portal-staging.sunbites.com.ph" --force -n

# Env vars require a redeploy to take effect — config:clear alone is not enough
cloud deploy "Sunbites API Prod" production -n
cloud deploy "Sunbites API Prod" staging -n
```

### Step 14 — Verify config values are live

```bash
cloud tinker production --code='
echo "Mailer: " . config("mail.default") . "\n";
echo "From: " . config("mail.from.address") . "\n";
echo "Portal URL: " . config("app.portal_url") . "\n";
echo "Resend key set: " . (config("services.resend.key") ? "yes" : "no") . "\n";
' --timeout=30 -n
```

---

## Useful Diagnostic Commands

```bash
# Check PHP config values on a live environment
cloud tinker production --code='
echo config("mail.default") . "\n";
echo config("app.portal_url") . "\n";
echo config("app.url") . "\n";
' --timeout=30 -n

# List all users with their roles
cloud tinker production --code='
$users = App\Models\User::with("roles")->get();
foreach ($users as $u) {
    echo $u->email . " | " . $u->roles->pluck("name")->join(", ") . "\n";
}
' --timeout=30 -n

# Run a migration
cloud command:run production --cmd='php artisan migrate --force' -n

# Clear all caches
cloud command:run production --cmd='php artisan optimize:clear' -n

# Seed the database
cloud command:run production --cmd='php artisan db:seed --force' -n

# Seed a specific seeder
cloud command:run production --cmd='php artisan db:seed --class=BranchSeeder --force' -n
```

---

## Security Notes

- **Never share API keys in chat or commit them to git.** If a key is accidentally exposed, revoke it immediately in the provider dashboard and generate a new one.
- Set all secrets exclusively via `cloud environment:variables --action=set` or the Laravel Cloud dashboard.
- The `RESEND_API_KEY` exposed during setup was revoked and regenerated before use.
- Production deployments require manual trigger + `DEPLOY` confirmation + GitHub environment approval gate.
- Never push directly to `main` — always create a branch and open a PR.
