# Spec 12 — Tasks

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

- [x] 9.1 `components/layouts/kitchen-layout.tsx` — remove `<ReminderBell />` from header; keep only `<NotificationBell />` — done (commit f1faffc)
- [x] 9.2 `components/notification-bell.tsx` (POS) — add `.listen("PreRegistrationNotification", () => refetch())` — done (commit 5552eed)
- [x] 9.3 `types/staff-notification.ts` — replace flat type with discriminated union; add `PreRegistrationData` interface — done (commit a9bf175)
- [x] 9.4 `lib/relative-time.ts` (new) — same relative-time helper as portal — done (commit 5552eed)
- [x] 9.5 `app/(kitchen)/notifications/page.tsx` — full MagicBell redesign: type-aware cards for both notification types; click routing to respective pages — done (commit 0e10135)
- [x] 9.6 POS staff notification page tests (5/5 pass) covering type-aware rendering and click routing — done (commit 8df2ba2)
- [x] 9.7 POS lint: 0 errors — confirmed

---

## Task 10: Announcements Pages Redesign

> Added 2026-06-13 — see `docs/superpowers/specs/2026-06-13-unified-notification-system-design.md` section "POS Announcements Page Redesign"

- [x] 10.1 `app/(kitchen)/announcements/page.tsx` — fixed badge colors (parents=purple, staff=blue); added relative timestamps — done (commit 2bc73a4)
- [x] 10.2 `app/(kitchen)/announcements/create/page.tsx` — replaced radio inputs with pill toggle buttons; added character count (N/1000) on textarea — done (commit 1150120)
- [x] 10.3 `app/(kitchen)/announcements/[id]/page.tsx` — added "{N} read / {total}" summary header on recipients; replaced toLocaleDateString with relativeTime in Read At column — done (commit 94b0d40)
- [x] 10.4 POS lint: 0 errors — confirmed
