# Sunbites API — Infrastructure

> **Cloud CLI warning:** with one environment, the CLI treats any environment name it cannot match as `main`. Always type `main`. A command naming another environment still acts on production.
>
> **2026-11-07:** the staging environment was removed from Laravel Cloud. The `staging` branch stays and runs tests only. Merging to `main` does not deploy: production deploys only through the Deploy to Production workflow. CI runs on MySQL 8.4, not SQLite.

Laravel API backend deployed to **Laravel Cloud** with one environment.

| Environment | Branch | URL | Deploy |
|-------------|--------|-----|--------|
| Staging | `staging` | none | Tests only, no deploy |
| Production | `main` | `https://api.sunbites.com.ph` | Manual: Deploy to Production workflow |

---

## Stack

| Layer | Service |
|-------|---------|
| Hosting | Laravel Cloud (Flex compute) |
| Database | Laravel Cloud MySQL (Flex) |
| Storage | Laravel Cloud Buckets (S3-compatible) |
| Queue | Laravel Cloud Background Process |
| Scheduler | Laravel Cloud (`usesScheduler` on the `App` instance) |
| Email | Resend |
| Error Monitoring | Laravel Flare |
| Auth | Laravel Sanctum |
| CI/CD | GitHub Actions |

---

## Docs

| File | What it covers |
|------|---------------|
| [01-project-setup.md](01-project-setup.md) | Creating the app on Laravel Cloud, connecting GitHub |
| [02-environments.md](02-environments.md) | Production environment configuration |
| [03-environment-variables.md](03-environment-variables.md) | All env vars per environment |
| [04-resend-setup.md](04-resend-setup.md) | Resend email setup |
| [05-cicd.md](05-cicd.md) | GitHub Actions workflows |
| [06-storage.md](06-storage.md) | Laravel Cloud Buckets for student photos and file uploads |
| [07-scheduled-tasks.md](07-scheduled-tasks.md) | Scheduler, activitylog:clean, queue pruning, pre-registrations:expire |
| [08-error-monitoring.md](08-error-monitoring.md) | Laravel Flare setup for production error tracking |

---

## Naming Convention

| Resource | Name |
|----------|------|
| Laravel Cloud App | `Sunbites API Prod` |
| Laravel Cloud region | `ap-southeast-1` |
| Production environment | `main` |
| GitHub branch (tests only, no deploy) | `staging` |
| GitHub branch (production) | `main` |
| Production domain | `api.sunbites.com.ph` |
| Production bucket | `sunbites_api_prod_bucket` (private) |
| WebSockets | `reverb-websocket` |
