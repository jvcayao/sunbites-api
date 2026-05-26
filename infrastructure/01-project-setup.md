# Phase 1 — Laravel Cloud Project Setup

Create the app on Laravel Cloud and connect it to the GitHub repository.

---

## Prerequisites

- Laravel Cloud account at [cloud.laravel.com](https://cloud.laravel.com)
- Laravel Cloud CLI installed globally
- GitHub repository for `sunbites-api`

---

## 1.1 Install Laravel Cloud CLI

```bash
composer global require laravel/cloud-cli
```

Verify install:

```bash
cloud --version
```

---

## 1.2 Authenticate

```bash
cloud auth -n
```

Follow the browser prompt to log in with your Laravel Cloud account. Your token is stored at `~/.config/cloud/config.json`.

---

## 1.3 Create the Application

```bash
cloud application:create --json -n
```

When prompted:
- **Name:** `sunbites-api`
- **Region:** Choose the region closest to your users (e.g. `us-east-1` or `ap-southeast-1` for Philippines)

Save the application ID from the JSON output — you will need it for environment creation.

Verify:

```bash
cloud application:list --json -n
```

---

## 1.4 Link the Repo to the Project

From inside the `sunbites-api` directory:

```bash
cloud repo:config
```

This writes a `.cloud/config.json` to the project root with your app and default environment. Commit this file.

```bash
git add .cloud/config.json
git commit -m "chore: add Laravel Cloud repo config"
```

---

## 1.5 Discover Available Instance Sizes

Before creating environments, check what Flex sizes are available in your region:

```bash
cloud instance:sizes --json -n
```

**Recommended sizes for Sunbites:**

| Environment | Compute | Reason |
|-------------|---------|--------|
| Staging | `1 vCPU / 512 MB` | Minimal traffic, testing only |
| Production | `1 vCPU / 1 GB` | Handles real parent + POS traffic |

---

## Phase 1 Checklist

- [ ] Laravel Cloud CLI installed and authenticated
- [ ] App `sunbites-api` created in Laravel Cloud
- [ ] `.cloud/config.json` committed to the repository
- [ ] Available instance sizes reviewed

---

**Next:** [02-environments.md](02-environments.md) — Create staging and production environments
