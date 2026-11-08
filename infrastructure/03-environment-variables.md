# Phase 3 — Environment Variables

Set all environment variables for production. Never commit secrets to the repository — all sensitive values go through Laravel Cloud's environment variable manager.

---

## How to Set Variables

```bash
cloud environment:variables {environment} --json -n --force
```

Laravel Cloud will open an editor (or accept piped input) where you paste the full `.env` block for that environment.

To view current variables:

```bash
cloud environment:variables main --json -n
```

---

## Production Variables

```env
APP_NAME="Sunbites"
APP_ENV=production
APP_KEY=                          # generate with: php artisan key:generate --show
APP_DEBUG=false
APP_URL=https://api.sunbites.com.ph
APP_DOMAIN=sunbites.com.ph

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US

APP_MAINTENANCE_DRIVER=file

BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=warning                  # warning level only on production

# Database — injected automatically by Laravel Cloud
DB_CONNECTION=mysql

# Sessions
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=.sunbites.com.ph

# Queue
QUEUE_CONNECTION=database

# Cache
CACHE_STORE=database

# Filesystem — Laravel Cloud Bucket (S3-compatible)
# AWS_* vars are injected automatically by Laravel Cloud when a bucket is attached.
# Do NOT set AWS_* manually.
FILESYSTEM_DISK=s3

# Broadcasting
BROADCAST_CONNECTION=log

# Mail — Resend
MAIL_MAILER=resend
MAIL_FROM_ADDRESS=noreply@sunbites.com.ph
MAIL_FROM_NAME="Sunbites"
RESEND_KEY=                        # get from resend.com dashboard

# Error Monitoring — Flare
FLARE_KEY=                         # get from flare.laravel.com

# Sanctum
SANCTUM_STATEFUL_DOMAINS=portal.sunbites.com.ph,pos.sunbites.com.ph
SANCTUM_TOKEN_EXPIRY=10080
SANCTUM_TOKEN_PREFIX=sunbites_

# CORS — Next.js frontend origins
CORS_ALLOWED_ORIGINS=https://portal.sunbites.com.ph,https://pos.sunbites.com.ph
```

---

## Generating APP_KEY

```bash
php artisan key:generate --show
```

Run this command locally and paste the output into the environment's variables.

---

## Variables Laravel Cloud Injects Automatically

When you attach a database or bucket to an environment, Laravel Cloud injects these automatically — **do not set them manually**:

**Database:**
- `DB_HOST`
- `DB_PORT`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`

**Bucket:**
- `AWS_ACCESS_KEY_ID`
- `AWS_SECRET_ACCESS_KEY`
- `AWS_DEFAULT_REGION`
- `AWS_BUCKET`
- `AWS_ENDPOINT`
- `AWS_USE_PATH_STYLE_ENDPOINT`

---

## Phase 3 Checklist

- [ ] Production `APP_KEY` generated and set
- [ ] Production `RESEND_KEY` set
- [ ] `FILESYSTEM_DISK=s3` confirmed
- [ ] `AWS_*` vars confirmed injected automatically (check after bucket is attached)
- [ ] `CORS_ALLOWED_ORIGINS` set correctly
- [ ] `SANCTUM_STATEFUL_DOMAINS` set correctly
- [ ] Variables verified with `cloud environment:variables main --json -n`

---

**Next:** [04-resend-setup.md](04-resend-setup.md) — Configure Resend email service
