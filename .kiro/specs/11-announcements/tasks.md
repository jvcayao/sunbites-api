# Spec 11 — Tasks

## Task 1: Database & Model

- [x] 1.1 Create migration for `announcements` table — done (2026_06_13_082519)
- [x] 1.2 Create `App\Models\Announcement` with `HasBranch` trait — done
- [x] 1.3 Verify `App\Models\User` already has `Notifiable` trait — confirmed
- [x] 1.4 Run Pint formatter; run existing test suite — 454/454 green

---

## Task 2: Notification Class & Broadcasting

- [x] 2.1 Create `App\Notifications\AnnouncementNotification` — done
- [x] 2.2 Extend `routes/channels.php` with `staff.{userId}` channel auth — done
- [x] 2.3 Register `POST /broadcasting/auth` in `routes/kitchen-api.php` — done
- [x] 2.4 Run Pint formatter — done

---

## Task 3: Backend Announcement Endpoints

- [x] 3.1 Create `App\Http\Controllers\Kitchen\AnnouncementController` (index, store, show) — done
- [x] 3.2 Register routes in `routes/kitchen-api.php` (role:admin|manager|supervisor) — done
- [x] 3.3 Run Pint formatter — done

---

## Task 4: Backend Staff Notification Endpoints

- [x] 4.1 Create `App\Http\Controllers\Kitchen\StaffNotificationController` — done
- [x] 4.2 Register routes in `routes/kitchen-api.php` (all staff) — done
- [x] 4.3 Run Pint formatter — done

---

## Task 5: Backend Tests

- [x] 5.1 `tests/Feature/Kitchen/AnnouncementTest.php` — 9 tests pass
- [x] 5.2 `tests/Feature/Kitchen/StaffNotificationTest.php` — 7 tests pass
- [x] 5.3 Full suite — 454/454 green

---

## Task 6: Frontend POS — Echo Provider

- [x] 6.1 `npm install laravel-echo pusher-js`; env vars added — done
- [x] 6.2 `components/providers/echo-provider.tsx` — done (uses `useAuthStore` token, `authEndpoint` at `/api/v1/broadcasting/auth`)
- [x] 6.3 `<EchoProvider>` added to `app/(kitchen)/layout.tsx` — done

---

## Task 7: Frontend POS — Staff Notification Bell & Inbox

- [x] 7.1 `types/staff-notification.ts` — done
- [x] 7.2 `lib/api/staff-notifications.ts` — done
- [x] 7.3 `components/notification-bell.tsx` — done (Echo subscription on `staff.{userId}`)
- [x] 7.4 `NotificationBell` added to `kitchen-layout.tsx` header alongside `ReminderBell` — done
- [x] 7.5 `app/(kitchen)/notifications/page.tsx` — done
- [x] 7.6 `app/(kitchen)/notifications/loading.tsx` — done

---

## Task 8: Frontend POS — Announcements Pages

- [x] 8.1 `types/announcement.ts` — done
- [x] 8.2 `lib/api/announcements.ts` — done
- [x] 8.3 `app/(kitchen)/announcements/page.tsx` — done
- [x] 8.4 `app/(kitchen)/announcements/loading.tsx` — done
- [x] 8.5 `app/(kitchen)/announcements/create/page.tsx` — done
- [x] 8.6 `app/(kitchen)/announcements/[id]/page.tsx` — done
- [x] 8.7 `app/(kitchen)/announcements/[id]/loading.tsx` — done
- [x] 8.8 "Announcements" nav item added to `kitchen-layout.tsx` (Megaphone icon, supervisor+ only) — done; POS lint 0 errors

---

## Task 9: POS Single Bell Consolidation & MagicBell Redesign

> Added 2026-06-13 — see `docs/superpowers/specs/2026-06-13-unified-notification-system-design.md`
> Corrects Task 7.4 (two bells in header is incorrect per approved design)

- [ ] 9.1 `components/layouts/kitchen-layout.tsx` — remove `<ReminderBell />` from header; keep only `<NotificationBell />`; the Reminders nav item (already in sidebar) remains the sole entry point for the outbound reminders workflow
- [ ] 9.2 `components/notification-bell.tsx` (POS) — add `.listen("PreRegistrationNotification", () => refetch())` so the single bell badge reflects all inbound staff notifications (no backend changes needed — `PreRegistrationNotification` already broadcasts on `staff.{id}`)
- [ ] 9.3 `types/staff-notification.ts` — replace flat type with discriminated union on `type` FQCN field; add `PreRegistrationData` interface (`pre_registration_id`, `student_name`, `branch_name`, `enrollment_type`, `submitted_at`)
- [ ] 9.4 `lib/utils/relative-time.ts` (new) — same relative-time helper as portal (copy or extract to shared if monorepo)
- [ ] 9.5 `app/(kitchen)/notifications/page.tsx` — full MagicBell redesign: type-aware cards for `AnnouncementNotification` and `PreRegistrationNotification`; click routing: announcement → `/announcements/{data.announcement_id}`, pre-registration → `/pre-registrations/{data.pre_registration_id}`; optimistic mark-as-read; empty state
- [ ] 9.6 Update POS notification and layout component tests: assert single bell in header; assert `PreRegistrationNotification` event increments badge; assert click routing
- [ ] 9.7 POS lint: 0 errors

---

## Task 10: Announcements Pages Redesign

> Added 2026-06-13 — see `docs/superpowers/specs/2026-06-13-unified-notification-system-design.md` section "POS Announcements Page Redesign"

- [ ] 10.1 `app/(kitchen)/announcements/page.tsx` — replace plain text list with card-based layout: recipient-type badge (purple=Parents, indigo=Staff), bold title, 2-line gray preview, footer (sender · sent count · read count), relative timestamp top-right, click navigates to detail; "New Announcement" button in header; empty state (Megaphone icon + "No announcements yet")
- [ ] 10.2 `app/(kitchen)/announcements/create/page.tsx` — replace bare form with card-sectioned layout: "Send to" styled pill toggle buttons (not radio inputs); recipients searchable multi-select checklist with "Select all (N)" link and "N selected" badge; character count on textarea; Send button count reflects selection (disabled at 0)
- [ ] 10.3 `app/(kitchen)/announcements/[id]/page.tsx` — two-panel detail: info card (badge, full title, full message, sender, sent-at, total recipients) + recipient table (Name, Status dot, Read at relative time); summary row "{N} read / {total}"
- [ ] 10.4 POS lint: 0 errors
