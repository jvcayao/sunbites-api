# Phase 5 — GitHub Actions CI/CD

> **Cloud CLI warning:** with one environment, the CLI treats any environment name it cannot match as `main`. Always type `main`. A command naming another environment still acts on production.
>
> **2026-11-07:** the staging environment was removed from Laravel Cloud. The `staging` branch stays and runs tests only. Merging to `main` does not deploy: production deploys only through the Deploy to Production workflow. CI runs on MySQL 8.4, not SQLite.

Three workflows, all in [`.github/workflows/`](../.github/workflows/):

| Workflow | File | Trigger | Does |
|----------|------|---------|------|
| CI | `ci.yml` | Pull request targeting `staging` or `main` | Runs the MySQL 8.4 test job |
| Staging Tests | `staging.yml` | Push to `staging` | Runs the same test job. Tests only — there is nothing to deploy to |
| Deploy to Production | `production.yml` | Manual (`workflow_dispatch`) | Validates the confirmation input, runs the same test job, then deploys |

All three run the identical `test` job (MySQL 8.4 service container, PHP 8.5, `./vendor/bin/pint --test`, then `vendor/bin/phpunit` directly against MySQL — never `php artisan test`, which would clear the `DB_*` env vars and silently fall back to SQLite). `production.yml`'s `test` job additionally needs the `validate` job to pass first.

---

## 5.1 Required GitHub Secret

`CLOUD_API_TOKEN` lives on the GitHub Actions **environment** named `production` (Settings → Environments → `production`), not as a repository-level secret. Only `production.yml`'s `deploy` job declares `environment: production`, so only that job can read it. The `production` environment's allowed branch is `main`, and it has **no required reviewers**.

| Secret | Value | Where to get it |
|--------|-------|------------------|
| `CLOUD_API_TOKEN` | Laravel Cloud API token | cloud.laravel.com → Settings → API Tokens |

---

## 5.2 Get Your Laravel Cloud API Token

```bash
# View your current auth config (token is stored here after cloud auth)
cat ~/.config/cloud/config.json
```

Or generate a new one at [cloud.laravel.com](https://cloud.laravel.com) → **Settings** → **API Tokens** → **Create token**.

Add the token to the GitHub `production` environment as `CLOUD_API_TOKEN`.

---

## 5.3 Release Process

Production has no auto-deploy. The only way to release is:

1. Go to GitHub → Actions → **Deploy to Production** → **Run workflow**
2. Enter `DEPLOY` in the confirmation field (the `validate` job rejects anything else)
3. The `test` job runs on MySQL 8.4
4. The `deploy` job runs `cloud deploy "Sunbites API Prod" main -n`, which builds the image, runs `php artisan migrate --force`, and restarts the web process and queue worker

There is no monitor step and no required-reviewer gate on this workflow today.

---

## 5.4 Branch Flow

```
main ──────────────────────────────────── Production (manual deploy only)
  │
  └── staging ──────────────────────────── Tests only, no deploy
        │
        └── feat/your-feature ──────────── Development (no deploy)
```

1. Developer creates a `feat/` branch from `staging`
2. PR merged into `staging` → CI runs the test job; nothing deploys
3. PR from `staging` into `main` → CI runs the test job; merging does not deploy
4. Release separately via **Deploy to Production** (§5.3)

---

## Phase 5 Checklist

- [ ] `CLOUD_API_TOKEN` added to the GitHub `production` environment
- [ ] `.github/workflows/ci.yml` committed
- [ ] `.github/workflows/staging.yml` committed
- [ ] `.github/workflows/production.yml` committed
- [ ] GitHub `production` environment exists, allowed branch `main`
- [ ] CI passes on a pull request
- [ ] Deploy to Production tested with `workflow_dispatch`

---

## Full Infrastructure Cost Summary

| Resource | Monthly Cost |
|----------|-------------|
| Production compute (1 vCPU / 1 GB) | ~$7 |
| Production MySQL (1 vCPU / 1 GB) | ~$11 |
| Resend (≤3,000 emails/month) | $0 |
| GitHub Actions | $0 (public repo or free tier) |
| **Total** | **~$18/month** |
