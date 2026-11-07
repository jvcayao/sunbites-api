# Phase 5 — GitHub Actions CI/CD

Two workflows:

| Workflow | File | Trigger | Target |
|----------|------|---------|--------|
| Staging | `.github/workflows/staging.yml` | Push to `staging` branch | Staging environment |
| Production | `.github/workflows/production.yml` | Manual (`workflow_dispatch`) | Production environment |

Both workflows run tests before deploying. Production requires a manual trigger and reviewer approval.

---

## 5.1 Required GitHub Secrets

Go to GitHub repo → **Settings** → **Secrets and variables** → **Actions** → **New repository secret**

| Secret | Value | Where to get it |
|--------|-------|----------------|
| `CLOUD_API_TOKEN` | Laravel Cloud API token | cloud.laravel.com → Settings → API Tokens |

> One token is enough — the `cloud deploy` CLI command uses it for both environments. Keep it as a repository-level secret.

---

## 5.2 Get Your Laravel Cloud API Token

```bash
# View your current auth config (token is stored here after cloud auth)
cat ~/.config/cloud/config.json
```

Or generate a new one at [cloud.laravel.com](https://cloud.laravel.com) → **Settings** → **API Tokens** → **Create token**.

Add the token to GitHub Secrets as `CLOUD_API_TOKEN`.

---

## 5.3 Staging Workflow

**File:** `.github/workflows/staging.yml`

```yaml
name: Deploy to Staging

on:
  push:
    branches:
      - staging

jobs:
  test:
    name: Run Tests
    runs-on: ubuntu-latest

    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: mbstring, pdo, pdo_sqlite
          coverage: none

      - name: Install dependencies
        run: composer install --no-interaction --prefer-dist --optimize-autoloader

      - name: Copy env
        run: cp .env.example .env

      - name: Generate app key
        run: php artisan key:generate

      - name: Run migrations
        run: php artisan migrate --force
        env:
          DB_CONNECTION: sqlite
          DB_DATABASE: ':memory:'

      - name: Run tests
        run: php artisan test --compact
        env:
          DB_CONNECTION: sqlite
          DB_DATABASE: ':memory:'

  deploy:
    name: Deploy to Staging
    runs-on: ubuntu-latest
    needs: test

    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Install Laravel Cloud CLI
        run: composer global require laravel/cloud-cli

      - name: Deploy to staging
        run: cloud deploy sunbites-api staging -n
        env:
          CLOUD_API_TOKEN: ${{ secrets.CLOUD_API_TOKEN }}

      - name: Monitor deployment
        run: cloud deploy:monitor -n
        env:
          CLOUD_API_TOKEN: ${{ secrets.CLOUD_API_TOKEN }}
```

---

## 5.4 Production Workflow

**File:** `.github/workflows/production.yml`

Production is **never** triggered automatically. Only a manual `workflow_dispatch` deploys to production.

```yaml
name: Deploy to Production

on:
  workflow_dispatch:
    inputs:
      confirm:
        description: 'Type DEPLOY to confirm production deployment'
        required: true
        default: ''

jobs:
  validate:
    name: Validate Confirmation
    runs-on: ubuntu-latest

    steps:
      - name: Check confirmation input
        run: |
          if [ "${{ github.event.inputs.confirm }}" != "DEPLOY" ]; then
            echo "Confirmation failed. Type DEPLOY to proceed."
            exit 1
          fi

  test:
    name: Run Tests
    runs-on: ubuntu-latest
    needs: validate

    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: mbstring, pdo, pdo_sqlite
          coverage: none

      - name: Install dependencies
        run: composer install --no-interaction --prefer-dist --optimize-autoloader

      - name: Copy env
        run: cp .env.example .env

      - name: Generate app key
        run: php artisan key:generate

      - name: Run migrations
        run: php artisan migrate --force
        env:
          DB_CONNECTION: sqlite
          DB_DATABASE: ':memory:'

      - name: Run tests
        run: php artisan test --compact
        env:
          DB_CONNECTION: sqlite
          DB_DATABASE: ':memory:'

  deploy:
    name: Deploy to Production
    runs-on: ubuntu-latest
    needs: test
    environment: production        # requires GitHub environment protection rules

    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Install Laravel Cloud CLI
        run: composer global require laravel/cloud-cli

      - name: Deploy to production
        run: cloud deploy sunbites-api production -n
        env:
          CLOUD_API_TOKEN: ${{ secrets.CLOUD_API_TOKEN }}

      - name: Monitor deployment
        run: cloud deploy:monitor -n
        env:
          CLOUD_API_TOKEN: ${{ secrets.CLOUD_API_TOKEN }}
```

---

## 5.5 GitHub Environment Protection (Production)

The production workflow uses `environment: production` which allows GitHub to enforce approval gates.

Set this up at GitHub repo → **Settings** → **Environments** → **New environment** → `production`:

- Enable **Required reviewers** — add yourself (or a lead)
- Restrict to **`main` branch only** — prevents deploying from feature branches
- Enable **Prevent self-review** if you want a second set of eyes

With this in place, even if someone triggers the `workflow_dispatch`, the deploy job pauses for reviewer approval before running.

---

## 5.6 Branch Strategy

```
main ─────────────────────────────────────── Production (manual deploy)
  │
  └── staging ────────────────────────────── Staging (auto-deploy on push)
        │
        └── feat/your-feature ─────────────── Development (no deploy)
```

**Flow:**
1. Developer creates `feat/` branch from `staging`
2. PR merged into `staging` → auto-deploys to staging environment
3. After staging is verified → PR from `staging` into `main`
4. Go to GitHub Actions → **Deploy to Production** → **Run workflow** → type `DEPLOY` → reviewer approves

---

## 5.7 Verify Workflows

After adding both workflow files, push to `staging` and confirm:

1. Tests pass
2. `cloud deploy:monitor` shows successful deployment
3. `https://api-staging.sunbites.com.ph/api/health` returns 200

For production:

1. Go to GitHub → Actions → **Deploy to Production** → **Run workflow**
2. Enter `DEPLOY` in the confirmation field
3. Approve the environment gate
4. Monitor for success

---

## Phase 5 Checklist

- [ ] `CLOUD_API_TOKEN` added to GitHub repository secrets
- [ ] `.github/workflows/staging.yml` created and committed
- [ ] `.github/workflows/production.yml` created and committed
- [ ] GitHub `production` environment created with required reviewers
- [ ] GitHub `production` environment restricted to `main` branch
- [ ] Staging workflow triggered and succeeded on first push
- [ ] Production workflow tested with `workflow_dispatch`

---

## Full Infrastructure Cost Summary

| Resource | Monthly Cost |
|----------|-------------|
| Staging compute (1 vCPU / 512 MB) | ~$4–5 |
| Staging MySQL (1 vCPU / 512 MB) | ~$5.50 |
| Production compute (1 vCPU / 1 GB) | ~$7 |
| Production MySQL (1 vCPU / 1 GB) | ~$11 |
| Resend (≤3,000 emails/month) | $0 |
| GitHub Actions | $0 (public repo or free tier) |
| **Total** | **~$27–28/month** |
