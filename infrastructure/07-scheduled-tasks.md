# Phase 7 — Scheduled Tasks

The app runs scheduled commands via Laravel's scheduler. On Laravel Cloud, the scheduler runs as a background process.

---

## Scheduled Commands

Defined in [routes/console.php](../routes/console.php):

| Command | Frequency | Purpose |
|---------|-----------|---------|
| `activitylog:clean` | Daily | Prunes activity log entries older than the configured retention period (default: 365 days in `config/activitylog.php`) |
| `queue:prune-failed --hours=168` | Weekly | Removes failed queue jobs older than 7 days |

---

## 7.1 Register the Scheduler as a Background Process on Laravel Cloud

Laravel Cloud does not run `schedule:run` automatically — you must register it as a background process in each environment.

```bash
cloud background-process:create --json -n
```

When prompted:
- **Command:** `php artisan schedule:run`
- **Environment:** `staging` (repeat for `production`)

> Laravel Cloud will run this command every minute, which is how Laravel's scheduler works — it checks internally which commands are due.

---

## 7.2 Verify Scheduled Tasks Are Registered

List background processes to confirm:

```bash
cloud background-process:list --json -n
```

You should see two background processes per environment:
1. `php artisan queue:work --sleep=3 --tries=3 --max-time=3600`
2. `php artisan schedule:run`

---

## 7.3 Adding New Scheduled Commands

Add new schedules in [routes/console.php](../routes/console.php):

```php
Schedule::command('your:command')->daily();
```

No changes needed in Laravel Cloud — the scheduler background process picks them up automatically on the next deploy.

---

## Phase 7 Checklist

- [ ] Scheduler background process `php artisan schedule:run` registered in `staging`
- [ ] Scheduler background process `php artisan schedule:run` registered in `production`
- [ ] `activitylog:clean` confirmed running (check CloudWatch / Laravel Cloud logs after 24h)
- [ ] `queue:prune-failed` confirmed running (check after 7 days)
