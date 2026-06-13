# Spec 10 — Tasks

## Task 1: Database & Configuration

- [x] 1.1 Run `php artisan notifications:table` to generate the `notifications` migration; migrate — done (prev session)
- [x] 1.2 Create migration for `parent_payment_reminders` table — done (prev session, short index name `ppr_unique`)
- [x] 1.3 Add `Notifiable` trait to `App\Models\ParentUser` — done (prev session)
- [x] 1.4 Verify `payment_reminder_days` exists in `SystemConfiguration` — confirmed (Spec 09 Task 1)
- [x] 1.5 Create `App\Models\ParentPaymentReminder` — done (prev session)
- [x] 1.6 Run Pint formatter; run existing test suite — 412/412 green (prev session)

---

## Task 2: Reverb Setup & Notification Class

- [x] 2.1 Install Laravel Reverb; env vars added to `.env` + `.env.example` — done (prev session)
- [x] 2.2 Create `App\Notifications\PaymentReminderNotification` implementing `ShouldQueue` and `ShouldBroadcast` — done
- [x] 2.3 Create `routes/channels.php` with `parents.{parentId}` channel auth; register in `bootstrap/app.php` — done (prev session)
- [x] 2.4 Register broadcast auth route `POST /broadcasting/auth` in `routes/portal-api.php` — done (uses `[BroadcastController::class, 'authenticate']`)
- [x] 2.5 Run Pint formatter; tests green — 412/412

---

## Task 3: Backend — Portal Notification Endpoints

- [x] 3.1 Create `App\Http\Controllers\Portal\NotificationController` (index, unreadCount, markRead, markAllRead, destroy, clearAll) — done
- [x] 3.2 Create `App\Http\Controllers\Portal\StudentPaymentHistoryController` — done
- [x] 3.3 Register routes in `routes/portal-api.php` — done
- [x] 3.4 Run Pint formatter — done

---

## Task 4: Backend — POS Reminder Endpoints

- [x] 4.1 Create `App\Http\Controllers\Kitchen\ReminderController` (bellCount, eligibleParents, send, show) — done
- [x] 4.2 Register routes in `routes/kitchen-api.php` — done
- [x] 4.3 Run Pint formatter — done

---

## Task 5: Backend Tests

- [x] 5.1 Create `tests/Feature/Portal/NotificationTest.php` — 9 tests pass
- [x] 5.2 Create `tests/Feature/Portal/StudentPaymentHistoryTest.php` — 4 tests pass
- [x] 5.3 Create `tests/Feature/Kitchen/ReminderTest.php` — 12 tests pass
- [x] 5.4 `Notification::fake()` + `Notification::assertSentTo()` used in send tests — done
- [x] 5.5 Run all new tests + full suite — 437/437 green

---

## Task 6: Frontend POS — Reminders

- [x] 6.1 `types/reminder.ts` — done
- [x] 6.2 `lib/api/reminders.ts` — done
- [x] 6.3 `components/reminder-bell.tsx` — done
- [x] 6.4 Add `ReminderBell` to `components/layouts/kitchen-layout.tsx`; add "Reminders" nav item — done
- [x] 6.5 `app/(kitchen)/reminders/page.tsx` — done
- [x] 6.6 `app/(kitchen)/reminders/loading.tsx` — done
- [x] 6.7 `app/(kitchen)/reminders/[parentId]/page.tsx` — done
- [x] 6.8 `app/(kitchen)/reminders/[parentId]/loading.tsx` — done; POS lint 0 errors

---

## Task 7: Frontend Portal — Echo Provider & Notifications

- [x] 7.1 `npm install laravel-echo pusher-js`; add env vars — done
- [x] 7.2 `types/notification.ts` — done
- [x] 7.3 `lib/api/notifications.ts` — done
- [x] 7.4 Add `paymentHistory(studentId)` to `studentsApi` — done
- [x] 7.5 `components/providers/echo-provider.tsx` — done
- [x] 7.6 Add `<EchoProvider>` to portal layout — done
- [x] 7.7 `components/notification-bell.tsx` — done
- [x] 7.8 Add `NotificationBell` to portal header layout — done
- [x] 7.9 `app/(portal)/notifications/page.tsx` — done
- [x] 7.10 `app/(portal)/notifications/loading.tsx` — done
- [x] 7.11 Add "Payment History" tab to `app/(portal)/students/[id]/page.tsx` — done; portal lint 0 errors

---

## Task 8: Bug Fixes & MagicBell Redesign (Portal)

> Added 2026-06-13 — see `docs/superpowers/specs/2026-06-13-unified-notification-system-design.md`

- [ ] 8.1 `types/notification.ts` — replace `PaymentReminderData`-only definition with a discriminated union on the `type` FQCN field; add `AnnouncementData` interface (`announcement_id`, `title`, `message`, `sender_name`, `sent_at`)
- [ ] 8.2 `components/notification-bell.tsx` — add `.listen("AnnouncementNotification", () => refetch())` alongside existing `PaymentReminderNotification` listener so badge updates in real time for both types
- [ ] 8.3 `lib/utils/relative-time.ts` (new) — human-relative timestamp helper: "just now" / "{N}m" / "{N}h" / "{N}d" / "Jun 10"
- [ ] 8.4 `app/(portal)/notifications/page.tsx` — full MagicBell redesign: unread dot (left), bold type-aware title, 2-line preview, relative timestamp (right), `...` context menu (Mark as read / Delete); header: mark-all-read icon + cog; click routing: `PaymentReminderNotification` → `/payments`, `AnnouncementNotification` → inline accordion expansion; empty state: bell illustration + "You're all caught up"
- [ ] 8.5 Update portal notification component tests to cover `AnnouncementNotification` cards rendering correctly (not "Payment reminder")
- [ ] 8.6 Portal lint: 0 errors
