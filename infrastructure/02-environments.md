# Phase 2 — Environment

Configure the one Cloud environment: database, env vars, domain.

---

## 2.1 Create the Production Environment

```bash
cloud environment:create --json -n
```

When prompted:
- **Environment name:** `main`
- **Branch:** `main`
- **Auto-deploy:** `no` — production deploys only through the Deploy to Production GitHub Actions workflow (see [05-cicd.md](05-cicd.md))

---

## 2.2 Create the MySQL Database

```bash
cloud database-cluster:create --json -n
```

Recommended config:
- **Name:** `sunbites-production-db`
- **Type:** MySQL
- **Size:** Flex `1 vCPU / 1 GB` (~$11/month)
- **Environment:** `main`

Then create the database:

```bash
cloud database:create --json -n
```

- **Name:** `sunbites_production`
- **Cluster:** `sunbites-production-db`

---

## 2.3 Configure the Compute Instance

Laravel Cloud sets a default instance — verify and adjust the size.

```bash
cloud instance:list --json -n
# find the instance ID for the "App" instance, then:
cloud instance:update {instance-id} --json -n --force
```

Set size to `flex-1gb` (1 vCPU / 1 GB).

---

## 2.4 Create the Queue Worker (Background Process)

The app uses `QUEUE_CONNECTION=database`. Register a background process so queued jobs run.

```bash
cloud background-process:create --json -n
```

When prompted:
- **Command:** `php artisan queue:work database --tries=3 --sleep=3 --quiet`
- **Environment:** `main`

---

## 2.5 Create the Storage Bucket

The app needs a bucket for file uploads (student photos, Excel exports). See [06-storage.md](06-storage.md) for full setup details.

```bash
cloud bucket:create --json -n
# Name: sunbites_api_prod_bucket | Private | Environment: main
```

Laravel Cloud automatically injects `AWS_*` credentials into the environment — no manual configuration needed.

---

## 2.6 Configure the Custom Domain

```bash
cloud domain:create --json -n
```

- **Domain:** `api.sunbites.com.ph`
- **Environment:** `main`

Then verify:

```bash
cloud domain:verify -n
```

Add the A record or CNAME to your DNS provider.

> Laravel Cloud provisions SSL/TLS automatically once the domain is verified. No ACM setup needed unlike the AWS ECS approach.

---

## 2.7 First Deploy

Once the environment and database are configured, trigger the first deployment through the Deploy to Production GitHub Actions workflow (see [05-cicd.md](05-cicd.md)), which runs:

```bash
cloud deploy "Sunbites API Prod" main -n
```

This will:
1. Build the Docker image
2. Run `php artisan migrate --force`
3. Start the web process and queue worker

Verify production is healthy:

```bash
curl -s https://api.sunbites.com.ph/api/health | jq .
```

---

## Phase 2 Checklist

- [ ] `main` environment created — auto-deploy OFF
- [ ] Production MySQL database cluster created (`sunbites-production-db`)
- [ ] Queue worker background process configured
- [ ] Scheduler on for the `App` instance (`usesScheduler`) — no separate `schedule:run` background process needed
- [ ] Production bucket `sunbites_api_prod_bucket` created
- [ ] Domain `api.sunbites.com.ph` added and DNS verified
- [ ] First deploy successful and health check passes

---

**Next:** [03-environment-variables.md](03-environment-variables.md) — Set env vars
