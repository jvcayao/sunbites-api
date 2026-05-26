# Sunbites API — Infrastructure

Laravel API backend deployed to **Laravel Cloud** with two environments.

| Environment | Branch | URL | Deploy |
|-------------|--------|-----|--------|
| Staging | `staging` | `https://api-staging.sunbites.com.ph` | Auto on push |
| Production | `main` | `https://api.sunbites.com.ph` | Manual trigger |

---

## Stack

| Layer | Service |
|-------|---------|
| Hosting | Laravel Cloud (Flex compute) |
| Database | Laravel Cloud MySQL (Flex) |
| Storage | Laravel Cloud Buckets (S3-compatible) |
| Queue | Laravel Cloud Background Process |
| Scheduler | Laravel Cloud Background Process |
| Email | Resend |
| Error Monitoring | Laravel Flare |
| Auth | Laravel Sanctum |
| CI/CD | GitHub Actions |

---

## Docs

| File | What it covers |
|------|---------------|
| [01-project-setup.md](01-project-setup.md) | Creating the app on Laravel Cloud, connecting GitHub |
| [02-environments.md](02-environments.md) | Staging and production environment configuration |
| [03-environment-variables.md](03-environment-variables.md) | All env vars per environment |
| [04-resend-setup.md](04-resend-setup.md) | Resend email setup |
| [05-cicd.md](05-cicd.md) | GitHub Actions workflows |
| [06-storage.md](06-storage.md) | Laravel Cloud Buckets for student photos and file uploads |
| [07-scheduled-tasks.md](07-scheduled-tasks.md) | Scheduler background process, activitylog:clean, queue pruning |
| [08-error-monitoring.md](08-error-monitoring.md) | Laravel Flare setup for production error tracking |

---

## Naming Convention

| Resource | Name |
|----------|------|
| Laravel Cloud App | `sunbites-api` |
| Staging environment | `staging` |
| Production environment | `production` |
| GitHub staging branch | `staging` |
| GitHub production branch | `main` |
| Staging domain | `api-staging.sunbites.com.ph` |
| Production domain | `api.sunbites.com.ph` |
| Staging bucket | `sunbites-staging` |
| Production bucket | `sunbites-production` |
