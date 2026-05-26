# Phase 4 — Resend Email Setup

Resend is the mailing service for both staging and production. It integrates natively with Laravel's mail system via the `resend/resend-laravel` package.

---

## Why Resend

| | Resend | Amazon SES |
|---|---|---|
| Free tier | 3,000 emails/month (permanent) | 3,000/month (first 12 months only) |
| Paid | $0.90/1,000 over free tier | $0.10/1,000 |
| Setup complexity | Low (API key only) | Medium (IAM user + domain verify) |
| Laravel native | Official package | Built-in transport |
| Dashboard | Yes (logs, analytics) | Basic |

> At Sunbites' expected volume (parent notifications, receipts, reports), Resend's free tier is likely sufficient indefinitely.

---

## 4.1 Create a Resend Account

1. Go to [resend.com](https://resend.com) and sign up
2. Create **two API keys** — one for staging, one for production:
   - Staging key name: `sunbites-staging`
   - Production key name: `sunbites-production`
3. Save both keys — you will set them as environment variables in Laravel Cloud

---

## 4.2 Verify Your Domain in Resend

Go to Resend dashboard → **Domains** → Add domain → `sunbites.com.ph`

Resend will give you DNS records to add:
- SPF record
- DKIM record
- DMARC record (optional but recommended)

Add these records to your DNS provider. Resend will automatically verify once propagated.

> One domain verification covers both staging and production since both send from `@sunbites.com.ph`.

---

## 4.3 Install the Resend Laravel Package

```bash
composer require resend/resend-laravel
```

This package adds the `resend` mail transport to Laravel.

---

## 4.4 Configure Laravel Mail

The `MAIL_MAILER=resend` env var is already set in both environment variable files from Phase 3.

Verify [config/mail.php](../config/mail.php) has the `resend` mailer entry (it is included by default in Laravel 11+):

```php
'resend' => [
    'transport' => 'resend',
],
```

If it is not there, add it to the `mailers` array in `config/mail.php`.

---

## 4.5 Add RESEND_KEY to Laravel Cloud

Set the API key in each environment via the Laravel Cloud CLI:

```bash
# For staging
cloud environment:variables staging --json -n --force
# Add: RESEND_KEY=re_xxxxxxxxxxxxxxxxxxxx  (staging key)

# For production
cloud environment:variables production --json -n --force
# Add: RESEND_KEY=re_xxxxxxxxxxxxxxxxxxxx  (production key)
```

---

## 4.6 Test Email Delivery on Staging

After the first staging deploy, run a test via tinker:

```bash
cloud tinker staging --code='Mail::raw("Test from staging", function($m) { $m->to("your@email.com")->subject("Sunbites staging mail test"); });' --timeout=30 -n
```

Verify it arrives and check Resend dashboard for delivery status.

---

## 4.7 Email Addresses in Use

| Purpose | From Address |
|---------|-------------|
| All transactional mail | `noreply@sunbites.com.ph` |
| Staging (labeled differently) | `noreply@sunbites.com.ph` with name `Sunbites (Staging)` |

---

## Phase 4 Checklist

- [ ] Resend account created
- [ ] Staging API key created (`sunbites-staging`)
- [ ] Production API key created (`sunbites-production`)
- [ ] Domain `sunbites.com.ph` verified in Resend
- [ ] `resend/resend-laravel` package installed
- [ ] `RESEND_KEY` set in staging environment
- [ ] `RESEND_KEY` set in production environment
- [ ] Test email sent from staging and confirmed delivered

---

**Next:** [05-cicd.md](05-cicd.md) — GitHub Actions CI/CD workflows
