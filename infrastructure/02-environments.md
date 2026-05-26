# Phase 2 — Environments

Create and configure both environments. Each environment is independent — separate database, separate env vars, separate domain.

---

## 2.1 Create the Staging Environment

```bash
cloud environment:create --json -n
```

When prompted:
- **Environment name:** `staging`
- **Branch:** `staging`
- **Auto-deploy:** `yes` — deploys automatically on every push to the `staging` branch

---

## 2.2 Create the Production Environment

```bash
cloud environment:create --json -n
```

When prompted:
- **Environment name:** `production`
- **Branch:** `main`
- **Auto-deploy:** `no` — production is triggered manually via GitHub Actions (see [05-cicd.md](05-cicd.md))

---

## 2.3 Create MySQL Databases

Create a separate database cluster and database for each environment.

### Staging Database

```bash
cloud database-cluster:create --json -n
```

Recommended config:
- **Name:** `sunbites-staging-db`
- **Type:** MySQL
- **Size:** Flex `1 vCPU / 512 MB` (~$5.50/month)
- **Environment:** `staging`

Then create the database inside the cluster:

```bash
cloud database:create --json -n
```

- **Name:** `sunbites_staging`
- **Cluster:** `sunbites-staging-db`

### Production Database

```bash
cloud database-cluster:create --json -n
```

Recommended config:
- **Name:** `sunbites-production-db`
- **Type:** MySQL
- **Size:** Flex `1 vCPU / 1 GB` (~$11/month)
- **Environment:** `production`

Then create the database:

```bash
cloud database:create --json -n
```

- **Name:** `sunbites_production`
- **Cluster:** `sunbites-production-db`

---

## 2.4 Configure Compute Instances

Laravel Cloud sets a default instance — verify and adjust sizes per environment.

### Staging

```bash
cloud instance:list --json -n
# find the staging instance ID, then:
cloud instance:update {instance-id} --json -n --force
```

Set size to `1 vCPU / 512 MB`.

### Production

```bash
cloud instance:update {instance-id} --json -n --force
```

Set size to `1 vCPU / 1 GB`.

---

## 2.5 Create Queue Worker (Background Process)

The app uses `QUEUE_CONNECTION=database`. Register a background process so queued jobs run in both environments.

```bash
cloud background-process:create --json -n
```

When prompted:
- **Command:** `php artisan queue:work --sleep=3 --tries=3 --max-time=3600`
- **Environment:** `staging` (repeat for `production`)

---

## 2.6 Create Storage Buckets

Each environment needs its own bucket for file uploads (student photos, Excel exports). See [06-storage.md](06-storage.md) for full setup details.

```bash
# Staging bucket
cloud bucket:create --json -n
# Name: sunbites-staging | Environment: staging

# Production bucket
cloud bucket:create --json -n
# Name: sunbites-production | Environment: production
```

Laravel Cloud automatically injects `AWS_*` credentials into the environment — no manual configuration needed.

---

## 2.7 Configure Custom Domains

### Staging Domain

```bash
cloud domain:create --json -n
```

- **Domain:** `api-staging.sunbites.com.ph`
- **Environment:** `staging`

Then verify via DNS:

```bash
cloud domain:verify -n
```

Add the CNAME record that Laravel Cloud provides to your DNS provider pointing `api-staging.sunbites.com.ph` to the Laravel Cloud domain.

### Production Domain

```bash
cloud domain:create --json -n
```

- **Domain:** `api.sunbites.com.ph`
- **Environment:** `production`

Then verify:

```bash
cloud domain:verify -n
```

Add the A record or CNAME to your DNS provider.

> Laravel Cloud provisions SSL/TLS automatically once the domain is verified. No ACM setup needed unlike the AWS ECS approach.

---

## 2.8 First Deploy (Staging)

Once environments and databases are configured, trigger the first deployment to staging:

```bash
cloud deploy sunbites-api staging -n
cloud deploy:monitor -n
```

This will:
1. Build the Docker image
2. Run `php artisan migrate --force`
3. Start the web process and queue worker

Verify staging is healthy:

```bash
curl -s https://api-staging.sunbites.com.ph/api/health | jq .
```

---

## Phase 2 Checklist

- [ ] `staging` environment created — auto-deploy on `staging` branch
- [ ] `production` environment created — auto-deploy OFF
- [ ] Staging MySQL database cluster created (`sunbites-staging-db`)
- [ ] Production MySQL database cluster created (`sunbites-production-db`)
- [ ] Queue worker background process configured in both environments
- [ ] Scheduler background process (`php artisan schedule:run`) configured in both environments
- [ ] Staging bucket `sunbites-staging` created
- [ ] Production bucket `sunbites-production` created
- [ ] Domain `api-staging.sunbites.com.ph` added and DNS verified
- [ ] Domain `api.sunbites.com.ph` added and DNS verified
- [ ] First staging deploy successful and health check passes

---

**Next:** [03-environment-variables.md](03-environment-variables.md) — Set env vars per environment
