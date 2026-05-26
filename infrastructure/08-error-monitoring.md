# Phase 8 — Error Monitoring

Production errors need to be tracked and alerted on. Without monitoring, you only find out about bugs when parents or staff report them.

**Service: Sentry** — industry-standard error monitoring, free tier available, full Laravel integration with stack traces, request context, and performance tracing.

---

## 8.1 Create a Sentry Account

1. Go to [sentry.io](https://sentry.io) and sign up (free with GitHub)
2. Create a new project → select **Laravel**
3. Sentry gives you a **DSN** — copy it

---

## 8.2 Install the Package

```bash
composer require sentry/sentry-laravel
```

---

## 8.3 Publish Config and Set DSN

```bash
php artisan sentry:publish --dsn=YOUR_DSN_HERE
```

This creates `config/sentry.php` and adds `SENTRY_LARAVEL_DSN` to your `.env`.

---

## 8.4 Wire Into bootstrap/app.php

```php
use Sentry\Laravel\Integration;

->withExceptions(function (Exceptions $exceptions): void {
    Integration::handles($exceptions);

    // ... existing exception renderers
})
```

---

## 8.5 Set the DSN in Laravel Cloud

Add to both staging and production environment variables:

```env
SENTRY_LARAVEL_DSN=https://your-key@sentry.io/your-project-id
SENTRY_TRACES_SAMPLE_RATE=0
```

> `SENTRY_TRACES_SAMPLE_RATE=0` disables performance tracing (keeps costs/quota low). Set to `0.1` to sample 10% of requests if you want performance data.

---

## 8.6 Verify

Test that Sentry receives errors:

```bash
php artisan sentry:test
```

Check your Sentry dashboard — a test event should appear within seconds.

---

## 8.7 What Sentry Captures Automatically

- All unhandled exceptions with full stack trace
- The HTTP request that caused the error (method, URL, headers)
- Authenticated user context (who triggered it)
- Release/environment tagging (`APP_ENV` separates staging from production)

---

## 8.8 Free Tier Limits

| Plan | Errors/month | Retention |
|------|-------------|-----------|
| Free | 5,000 | 30 days |
| Team (~$26/mo) | 50,000 | 90 days |

The free tier is more than sufficient for early production. Sentry also sends email alerts for new error types automatically.

---

## Phase 8 Checklist

- [x] Sentry account created at sentry.io
- [x] `sentry/sentry-laravel` package installed
- [x] `php artisan sentry:publish` run — `config/sentry.php` created
- [x] `Integration::handles($exceptions)` added to `bootstrap/app.php`
- [ ] `SENTRY_LARAVEL_DSN` set in staging environment on Laravel Cloud
- [ ] `SENTRY_LARAVEL_DSN` set in production environment on Laravel Cloud
- [ ] `php artisan sentry:test` confirmed test event appears in dashboard
