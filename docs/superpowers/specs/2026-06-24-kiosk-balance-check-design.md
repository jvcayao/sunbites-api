# Kiosk Balance Check Mode — Design Spec

**Date:** 2026-06-24
**Project:** sunbites-pos + sunbites-api
**Status:** Approved

---

## Overview

A public, unauthenticated kiosk page at `/kiosk` in the sunbites-pos app. Students hold their physical QR ID card up to any phone or tablet camera; the page decodes the QR code, calls a new public API endpoint, and displays the student's wallet balance and last 5 orders. No login, no manual input, one URL for all branches and devices.

**Goal:** Reduce cashier interruptions during rush hour. Students self-check their balance before queuing.

---

## Decisions

- **Approach B chosen:** Global QR lookup with no branch context. QR codes (`SB-{12 chars}`) are globally unique across all branches — confirmed by the `generateUniqueQrCode()` method which uses `withoutBranch()` for uniqueness checks. No branch parameter in the URL.
- **Camera-only input:** No manual typing. Only `@zxing/browser` camera scanning. Eliminates ambiguity and reduces surface area.
- **One URL:** `/kiosk` — works for a mounted tablet (bookmark once) or any student's phone (navigate and allow camera).

---

## Architecture

### 1. Laravel API — New Public Endpoint

**File:** `routes/api.php` — added to the existing `public` route group.

```
POST /api/v1/public/kiosk/lookup
```

- **Auth:** None
- **Rate limit:** `throttle:10,1` (10 requests/minute per IP)
- **Request body:** `{ "qr_code": "SB-aB3cDeF9gHiJ" }`
- **Controller:** `App\Http\Controllers\Public\KioskLookupController@lookup`

**Validation:**
- `qr_code` required, string, must start with `SB-` (reject before DB hit)

**Lookup logic:**
- `Student::withoutBranch()->where('qr_code', $qrCode)->first()`
- Return `404` if no student matches the QR code
- Return `403` if student is found but `enrollment_status` is anything other than `EnrollmentStatus::Enrolled` — this covers `Paused`, `Unenrolled`, `Banned`, and `Graduated`
- Return `200` with safe response shape only for `Enrolled` students

**Important:** The frontend renders the same "Please see a cashier." error card for both `404` and `403`. No difference is shown to the student — a banned or paused student cannot tell whether their QR was recognized or not.

**Success response shape:**
```json
{
  "name": "Juan dela Cruz",
  "initials": "JD",
  "grade_level": "Grade 3",
  "student_type": "subscription",
  "balance": "245.00",
  "last_orders": [
    { "items": "Rice Meal, Water", "total": "55.00", "date": "Jun 23, 2026" },
    { "items": "Snack Pack", "total": "25.00", "date": "Jun 22, 2026" }
  ]
}
```

**No photo in response:** The existing photo endpoint (`/api/v1/students/{id}/photo`) requires `auth:sanctum` — the kiosk has no token and would receive a 401. Rather than creating a separate public photo endpoint, the avatar uses initials only (first letter of first name + first letter of last name), derived server-side and returned as `initials`. This also keeps the student ID out of the response entirely.

**Intentionally excluded from response:** student ID, internal UUID, qr_code value, student number, photo URL, parent info, address, full transaction history.

**Error responses:**
- `404 { "message": "Student not found." }` — QR code does not match any student
- `403 { "message": "Student is not eligible." }` — student found but status is `Paused`, `Unenrolled`, `Banned`, or `Graduated`
- `429` — rate limit exceeded

The frontend treats `404`, `403`, and any other non-200 response identically: shows "QR not recognized. Please see a cashier." — students cannot distinguish between "not found" and "restricted."

**`last_orders` data source:** The 5 most recent `Order` records for the student (ordered by `created_at` desc, `limit(5)`), each with their `OrderItem->name` values joined by comma and the order `total`. `OrderItem->name` is denormalized (stored directly on the row) — no join to `PosMenuItem` needed. Formatted server-side — no client-side pagination needed.

**Wallet balance:** Accessed via `$student->wallet?->balanceFloatNum ?? 0.0` (bavix/laravel-wallet). The `wallet` relationship must be eager-loaded before returning the response.

**`student_type`:** A `StudentType` enum — serialize using `->value` to return the string (e.g. `"subscription"` or `"non_subscription"`).

### 2. sunbites-pos — New Kiosk Page

**Route:** `/kiosk`
**File:** `app/(kiosk)/kiosk/page.tsx` with a `(kiosk)` route group that has its own minimal layout (no nav, no header, fullscreen).

The page is outside `(kitchen)` and `(pos)` — no auth middleware applies.

**New files:**
```
app/
  (kiosk)/
    layout.tsx               ← minimal fullscreen layout, no nav
    kiosk/
      page.tsx               ← kiosk page (Client Component)
      loading.tsx            ← blank screen while camera initializes

hooks/
  use-kiosk-lookup.ts        ← mutation hook for public kiosk API

lib/
  api/
    kiosk.ts                 ← kioskApi.lookup(qrCode) service

types/
  kiosk.ts                   ← KioskStudent type
```

---

## UI/UX Flow

The kiosk page has exactly **four states**:

### State 1 — Idle/Scanning
- Camera viewfinder fills the screen (via `@zxing/browser` `BrowserMultiFormatReader`)
- Centered scan-guide rectangle overlay (like a camera app frame)
- Prompt text: "Scan your ID card"
- Sunbites logo at the top
- No input fields, no buttons (except tiny "?" hint at bottom if needed)
- Scanner runs continuously — auto-fires when a valid `SB-` QR is detected

### State 2 — Loading
- Triggered immediately when QR is detected
- Scanner locks for 1 second (debounce) to prevent duplicate requests
- Viewfinder dims, centered spinner appears
- Lookup fires: `POST /api/v1/public/kiosk/lookup`

### State 3 — Result
- Student card appears (fades in):
  - Large circular avatar — colored circle with initials (e.g. "JD") — no photo
  - Full name (large) + grade level
  - Subscription type badge (Subscription / Non-Subscription)
  - Wallet balance: extra-large, bold
    - Green: balance ≥ ₱50
    - Orange: balance > ₱0 and < ₱50
    - Red: balance ≤ ₱0
  - "Last Orders" section: up to 5 rows — item names on left, date + amount on right
  - Countdown progress bar at the bottom (10 seconds)
- When countdown reaches 0: auto-reset to State 1, camera restarts

### State 4 — Error
- Friendly card: "QR not recognized. Please see a cashier."
- Auto-dismisses after 5 seconds → back to State 1
- No technical error details shown to the student

---

## Camera Implementation

**Library:** `@zxing/browser` (BrowserMultiFormatReader) — not yet installed in sunbites-pos. Must be added: `npm install @zxing/browser`.

```typescript
// hooks/use-kiosk-lookup.ts (pseudocode)
const reader = new BrowserMultiFormatReader();
reader.decodeFromVideoDevice(null, videoRef.current, (result, error) => {
  if (result) {
    const text = result.getText();
    if (text.startsWith("SB-") && !isLocked) {
      lock(); // prevent duplicate scans
      fireLookup(text);
    }
  }
});
```

- `decodeFromVideoDevice(null, ...)` — `null` selects the default/rear camera
- Camera permission requested on page load; if denied, show a static message: "Camera access required. Please allow camera and refresh."
- Cleanup: stop the reader on component unmount

---

## Security

| Guard | Detail |
|---|---|
| Rate limit | `throttle:10,1` — 10 req/min/IP |
| QR prefix check | Controller rejects any value not starting with `SB-` before DB query |
| Read-only | No mutations, no wallet changes, no session created |
| Minimal response | Internal IDs, QR value, and parent data never returned |
| No CORS changes | Kiosk is served from the same Next.js origin as the rest of the POS app |

---

## Testing

### Laravel (PHPUnit — `tests/Feature/Public/KioskLookupTest.php`)

- Returns correct name, balance, and last 5 orders for a valid enrolled student QR
- Returns 404 for an unknown QR code
- Returns 403 for a valid QR belonging to a `Paused` student
- Returns 403 for a valid QR belonging to an `Unenrolled` student
- Returns 403 for a valid QR belonging to a `Banned` student
- Returns 403 for a valid QR belonging to a `Graduated` student
- Returns 429 after exceeding rate limit (mock throttle)
- Confirms sensitive fields are absent from the response (`id`, `qr_code`, `student_number`, parent data)
- Rejects QR values that do not start with `SB-`

### sunbites-pos (Jest + RTL + MSW — `app/(kiosk)/kiosk/kiosk.test.tsx`)

- `@zxing/browser` is mocked; tests simulate the `onResult` callback firing with a valid QR string
- Renders result card with correct name, balance (green/orange/red), and orders after successful lookup
- Shows identical "Please see a cashier." error card for 404 (not found) response
- Shows identical "Please see a cashier." error card for 403 (restricted student) response — same UI, no differentiation
- Auto-resets to scan state after 10 seconds (use `jest.useFakeTimers`)
- Shows "Camera access required" message when camera permission is denied (mock `getUserMedia` rejection)

---

## Out of Scope (v1)

- Manual student number entry
- Branch selection
- Wallet top-up from kiosk
- Admin toggle to enable/disable the kiosk URL
- Analytics on kiosk usage
- PIN to lock/unlock the kiosk screen
