# Phase 7 — Scheduled Tasks

The app runs scheduled commands via Laravel's scheduler.

---

## Scheduled Commands

Defined in [routes/console.php](../routes/console.php):

| Command | Frequency | Purpose |
|---------|-----------|---------|
| `activitylog:clean` | Daily | Prunes activity log entries older than the configured retention period (default: 365 days in `config/activitylog.php`) |
| `queue:prune-failed --hours=168` | Weekly | Removes failed queue jobs older than 7 days |
| `pre-registrations:expire` | Daily at 00:00 | Expires pre-registrations that have passed their expiry date |

---

## 7.1 The Scheduler on Laravel Cloud

The `App` instance has `usesScheduler` on, so Laravel Cloud runs `php artisan schedule:run` every minute by itself. **No separate `schedule:run` background process needs to be registered** — only the queue worker does (see [02-environments.md](02-environments.md)).

---

## 7.2 Verify Scheduled Tasks Are Registered

List background processes to confirm only the queue worker is registered as one:

```bash
cloud background-process:list --json -n
```

You should see one background process:
1. `php artisan queue:work database --tries=3 --sleep=3 --quiet`

---

## 7.3 Adding New Scheduled Commands

Add new schedules in [routes/console.php](../routes/console.php):

```php
Schedule::command('your:command')->daily();
```

No changes needed in Laravel Cloud — the `App` instance's scheduler picks them up automatically on the next deploy.

---

## Phase 7 Checklist

- [ ] `App` instance's `usesScheduler` confirmed on (no `schedule:run` background process registered)
- [ ] `activitylog:clean` confirmed running (check Laravel Cloud logs after 24h)
- [ ] `queue:prune-failed` confirmed running (check after 7 days)
- [ ] `pre-registrations:expire` confirmed running (check after 24h)
