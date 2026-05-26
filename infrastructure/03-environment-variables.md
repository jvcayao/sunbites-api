# Phase 3 — Environment Variables

Set all environment variables for staging and production. Never commit secrets to the repository — all sensitive values go through Laravel Cloud's environment variable manager.

---

## How to Set Variables

```bash
cloud environment:variables {environment} --json -n --force
```

Laravel Cloud will open an editor (or accept piped input) where you paste the full `.env` block for that environment.

To view current variables:

```bash
cloud environment:variables staging --json -n
```

---

## Staging Variables

```env
APP_NAME="Sunbites"
APP_ENV=staging
APP_KEY=                          # generate with: php artisan key:generate --show
APP_DEBUG=false
APP_URL=https://api-staging.sunbites.com.ph
APP_DOMAIN=sunbites.com.ph

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US

APP_MAINTENANCE_DRIVER=file

BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=debug                   # debug on staging for easier troubleshooting

# Database — Laravel Cloud injects DB_* vars automatically when a database is attached.
# Do NOT set these manually unless overriding the attached database.
DB_CONNECTION=mysql

# Sessions
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=.sunbites.com.ph   # leading dot allows subdomain sharing if needed

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
MAIL_FROM_NAME="Sunbites (Staging)"
RESEND_KEY=                        # get from resend.com dashboard

# Error Monitoring — Flare
FLARE_KEY=                         # get from flare.laravel.com

# Sanctum
SANCTUM_STATEFUL_DOMAINS=localhost:3000,localhost:3001
SANCTUM_TOKEN_EXPIRY=10080
SANCTUM_TOKEN_PREFIX=sunbites_

# CORS — Next.js frontend origins
CORS_ALLOWED_ORIGINS=https://staging-portal.sunbites.com.ph,https://staging-pos.sunbites.com.ph
```

---

## Production Variables

```env
APP_NAME="Sunbites"
APP_ENV=production
APP_KEY=                          # different key from staging — generate separately
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
RESEND_KEY=                        # production Resend API key (separate from staging)

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

## Key Differences Between Environments

| Variable | Staging | Production |
|----------|---------|------------|
| `APP_ENV` | `staging` | `production` |
| `APP_DEBUG` | `false` | `false` |
| `APP_URL` | `https://api-staging.sunbites.com.ph` | `https://api.sunbites.com.ph` |
| `LOG_LEVEL` | `debug` | `warning` |
| `MAIL_FROM_NAME` | `Sunbites (Staging)` | `Sunbites` |
| `RESEND_KEY` | Staging Resend API key | Production Resend API key |
| `SANCTUM_STATEFUL_DOMAINS` | localhost + staging frontend domains | Production frontend domains |
| `CORS_ALLOWED_ORIGINS` | Staging Next.js URLs | Production Next.js URLs |

---

## Generating APP_KEY

Never share APP_KEY between environments. Generate one for each:

```bash
php artisan key:generate --show
```

Run this command locally and paste the output into each environment's variables separately.

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

- [ ] Staging `APP_KEY` generated and set
- [ ] Production `APP_KEY` generated and set (different from staging)
- [ ] Staging `RESEND_KEY` set
- [ ] Production `RESEND_KEY` set
- [ ] `FILESYSTEM_DISK=s3` confirmed in both environments
- [ ] `AWS_*` vars confirmed injected automatically (check after bucket is attached)
- [ ] `CORS_ALLOWED_ORIGINS` set correctly for each environment
- [ ] `SANCTUM_STATEFUL_DOMAINS` set correctly for each environment
- [ ] Variables verified with `cloud environment:variables {env} --json -n`

---

**Next:** [04-resend-setup.md](04-resend-setup.md) — Configure Resend email service
