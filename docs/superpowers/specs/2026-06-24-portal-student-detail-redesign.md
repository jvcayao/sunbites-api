# Portal Student Detail Page — Redesign Spec

**Date:** 2026-06-24
**Scope:** `sunbites-portal` (frontend) + `sunbites-api` (backend)

---

## Overview

Rebuild the parent portal's student detail page (`/students/[id]`) to match the information richness of the POS student detail page, while enforcing parent-only permissions. Parents can view all student info (read-only), upload a photo, print/download the QR code, and filter order and wallet history. The dashboard "Recent Orders" section is also removed as it is superseded by the per-student Order History tab.

---

## What Changes

### Student Detail Page — Full Rebuild

The current `[id]/page.tsx` is a monolithic 580-line client component. It is replaced with a proper Server/Client split and extracted sub-components.

**Route:** `/students/[id]`

#### New Tab Structure

| Tab | Shown for |
|---|---|
| Profile | All students |
| Wallet | All students |
| Order History | All students |
| Payment | Subscription students only |

Default tab: **Profile**. Deep-linking via `?tab=profile|wallet|order-history|payment` is supported.

Tabs removed vs. current: **Contacts** and **Logs** are not included (staff-only features). **Activity** is renamed to **Order History**.

#### Navigation

Back button only. No student switcher in the header. Parents with multiple students navigate via the students list page.

---

## File Structure

```
app/(portal)/students/[id]/
  page.tsx                        ← Server Component: fetches student list, passes matched student as prop
  _components/
    student-detail-shell.tsx      ← "use client" — owns tab state and photo upload state
    student-header.tsx            ← Avatar + upload overlay, name/grade/badges, wallet balance, QR ID, action buttons
    student-qr-actions.tsx        ← QR print layout (@media print) + download-as-PNG logic
    profile-tab.tsx               ← Read-only personal info grid + subscription meals-this-month card
    wallet-tab.tsx                ← Balance card, alert threshold editor, pill-filtered transaction table
    order-history-tab.tsx         ← Pill-filtered order table
    payment-history-tab.tsx       ← Monthly payment records (subscription only)
```

---

## Student Header

Mirrors the POS header layout but removes all staff-only actions.

```
[ Avatar + camera overlay ]  [ Full Name            ] [ Wallet ₱0.00 ] [ QR ID: SB-xxx ]
                             [ Grade 9 · Antipolo   ]
                             [ Enrolled ] [ Non-Sub ]
                                                       [ Print QR ]   [ Download QR ]
```

### Avatar & Photo Upload
- Displays `photo_path` if present; falls back to initials avatar.
- Camera icon overlaid on the avatar. Clicking opens a native file picker (accept: `image/*`, max 5 MB client-side validation).
- On selection, uploads via `POST /portal/students/{student}/photo` (multipart form data, field name `photo`).
- On success, invalidates the `["students"]` query cache to refresh the photo.
- On error, shows a toast: "Photo upload failed. Please try again."

### Wallet Balance Box
- Read-only display of `wallet_balance`. No Top Up button.

### QR ID Box
- Displays the `qr_code` string. No Regenerate button.

### Print QR
- Calls `window.print()`.
- A `@media print` stylesheet (scoped to a print-only div) hides all other page content and shows:
  - QR code image (rendered from `qr_code` string via `qrcode` package)
  - Student full name
  - QR ID string
  - Sunbites branding

### Download QR
- Renders QR to an off-screen `<canvas>` via `qrcode` package.
- Converts canvas to PNG data URL via `canvas.toDataURL("image/png")`.
- Triggers a programmatic `<a download="{student-name}-qr.png">` click.

---

## Profile Tab

Read-only personal information grid, two columns.

| Left column | Right column |
|---|---|
| First Name | Student Number |
| Last Name | Student Type |
| Grade Level | Allergies |
| Section | Notes |
| Birthday | |

- No "Edit Profile" button.
- Empty fields display `—`.
- **Birthday** formatted as `May 15, 2011` (not raw ISO `2011-05-15`).
- **Allergies**: If non-empty, rendered inside an amber-tinted pill/badge to signal safety relevance.
- **Subscription students only**: Below the info grid, a "Meals This Month" usage card (currently floating above the tabs) is moved here. Shows current month/year + category allocations (meal/snack/drink/extra) with used/allocated/remaining counts. This keeps the tab bar clean for all student types.

---

## Wallet Tab

### Layout (top to bottom)

1. **Current Balance card** — large balance display.
2. **Low Balance Alert editor** — existing feature; keep as-is.
3. **Pill filters** — two rows above the transaction table.
4. **Transaction table** — paginated.

### Pill Filters

**Row 1 — Type:** `All` · `Top-up` · `Deductions`
**Row 2 — Time:** `All time` · `Today` · `This week` · `This month`

Pill values map to backend query params:
- `Top-up` → `type=deposit`
- `Deductions` → `type=withdraw`
- `Today` → `from={today}&to={today}`
- `This week` → `from={monday}&to={sunday}`
- `This month` → `from={first-of-month}&to={last-of-month}`

Changing either pill row resets to page 1.

---

## Order History Tab

### Pill Filters

**Row 1 — Method:** `All` · `Cash` · `Wallet`
**Row 2 — Time:** `All time` · `Today` · `This week` · `This month`

Pill values map to backend query params:
- `Cash` → `payment_method=cash`
- `Wallet` → `payment_method=wallet`
- Time range → `from=` / `to=` (same date logic as wallet)

"Total spent" summary reflects the filtered result. Pagination stays (Previous/Next). Changing either pill row resets to page 1.

---

## Payment History Tab

Unchanged from current implementation. Visible to subscription students only. Shows monthly payment records: month, amount, paid/unpaid status, paid date.

---

## Dashboard — Remove Recent Orders

The "Recent Orders" section is removed from `app/(portal)/dashboard/page.tsx`.

- Delete the `RecentOrderRow` and `RecentOrdersSection` components from the dashboard page.
- Remove the `recent_orders` render call.
- Remove `RecentOrder` from the `DashboardData` type import (if unused elsewhere).
- The backend `dashboard` endpoint still returns `recent_orders` in its response — no backend change required for this spec. The field is simply ignored on the frontend.

---

## Backend Changes (sunbites-api)

### 1. `Portal/StudentController.php` — Add fields to response

Add `birthday`, `notes`, and `qr_code` to the map in `index()`:

```php
'birthday'    => $student->birthday?->format('Y-m-d'),
'notes'       => $student->notes,
'qr_code'     => $student->qr_code,
```

### 2. New: `Portal/StudentPhotoController.php`

```
POST /portal/students/{student}/photo
```

- Auth: `auth:parents` + ability `parent`
- Policy check: parent must have the student linked (same as other portal student endpoints).
- Validates: `photo` — required, image, max 5120 KB.
- Stores to `storage/app/public/students/photos/` (same path convention as parent profile photos).
- Updates `$student->photo_path`.
- Returns: `{ photo_path: string }`.

### 3. `Portal/ActivityController.php` — Add `payment_method` filter

Accept optional `payment_method` query param (`cash`, `wallet`). Apply a `when()` condition to the orders query.

```php
->when($request->payment_method, fn ($q, $m) => $q->where('payment_method', $m))
```

### 4. `Portal/WalletController.php` — Add `type` filter

Accept optional `type` query param (`deposit`, `withdraw`). Apply to the wallet transactions query.

The frontend sends `deposit` / `withdraw` directly (mapped from pill labels before the API call).

### 5. `routes/portal-api.php` — Register new route

```php
Route::post('students/{student}/photo', [StudentPhotoController::class, 'store']);
```

---

## Frontend Type & API Updates (sunbites-portal)

### `types/portal.ts`

Add to `StudentSummary`:
```typescript
birthday: string | null;     // ISO date string "YYYY-MM-DD"
notes: string | null;
qr_code: string | null;
```

### `lib/api/portal.ts`

Add to `studentsApi`:
```typescript
uploadPhoto: async (id: number, file: File): Promise<{ photo_path: string }> => {
  // Same multipart pattern as profileApi.uploadPhoto, targeting /portal/students/{id}/photo
}
```

Update `activity()` signature:
```typescript
activity: (id: number, params: {
  page?: number;
  per_page?: number;
  payment_method?: "cash" | "wallet";
  from?: string;
  to?: string;
})
```

Update `wallet()` signature:
```typescript
wallet: (id: number, params?: {
  page?: number;
  type?: "deposit" | "withdraw";
  from?: string;
  to?: string;
})
```

---

## Dependencies

- `qrcode` npm package (for QR image rendering in the browser — check if already installed in sunbites-portal before adding).

---

## Tests

### Backend (PHPUnit)
- `StudentControllerTest`: assert `birthday`, `notes`, `qr_code` are present in response.
- `StudentPhotoControllerTest`: happy path upload updates `photo_path`; non-linked student returns 403; invalid file returns 422.
- `ActivityControllerTest`: assert `payment_method=cash` filters out wallet orders.
- `WalletControllerTest`: assert `type=deposit` filters out withdraw transactions.

### Frontend (Jest + RTL)
- `student-header.test.tsx`: renders initials fallback when no photo; camera overlay click opens file picker; upload success toasts and refetches.
- `order-history-tab.test.tsx`: selecting "Cash" pill calls API with `payment_method=cash`; selecting "Today" calls with correct date range; combined filter sends both params.
- `wallet-tab.test.tsx`: selecting "Top-up" pill calls API with `type=deposit`.
- `profile-tab.test.tsx`: renders all fields; birthday is formatted; allergies badge appears when allergies are non-empty; "Meals This Month" card shows only for subscription students.
