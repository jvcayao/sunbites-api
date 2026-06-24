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
- Student photos are stored on the **private** disk. They cannot be served as a plain URL — they require an authenticated streaming endpoint.
- Displays the photo by fetching `GET /portal/students/{student}/photo` with an `Authorization: Bearer {token}` header, converting the binary response to a blob URL via `URL.createObjectURL()`, and using that as `<img src>`. A `useEffect` cleanup revokes the blob URL on unmount.
- Falls back to an initials avatar when `photo_url` is `null`.
- Camera icon overlaid on the avatar. Clicking opens a native file picker (accept: `image/jpeg,image/png,image/webp`, max 5 MB client-side validation).
- On selection, uploads via `POST /portal/students/{student}/photo` (multipart form data, field name `photo`).
- On success, invalidates the `["students"]` query cache so the photo refetches.
- On error, shows a toast: "Photo upload failed. Please try again."

### Wallet Balance Box
- Read-only display of `wallet_balance`. No Top Up button.

### QR ID Box
- Displays the `qr_code` string. No Regenerate button.

### Print QR
- Calls `window.print()`.
- A `@media print` stylesheet (scoped to a print-only div) hides all other page content and shows:
  - QR code (rendered from `qr_code` string via `react-qr-code` — consistent with POS)
  - Student full name
  - QR ID string
  - Sunbites branding

### Download QR
- `react-qr-code` renders an SVG element. To export as PNG:
  1. Get a `ref` to the SVG element.
  2. Serialize it to an SVG string via `XMLSerializer`.
  3. Draw it onto an off-screen `<canvas>` using a `<img>` with an SVG data URI.
  4. Export via `canvas.toDataURL("image/png")`.
  5. Trigger a programmatic `<a download="{student-name}-qr.png">` click.
- The canvas and SVG steps all happen client-side with no server round-trip.

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

Two methods on a single controller:

```
POST /portal/students/{student}/photo   ← upload
GET  /portal/students/{student}/photo   ← serve (stream private file)
```

**`store` (upload):**
- Authorization: `$this->authorize('view', $student)` — goes through `ParentStudentPolicy::view()` which checks the `parent_student` pivot.
- Validates: `photo` — required, mimes: jpeg,png,webp, max 5120 KB.
- Deletes old photo from private disk if one exists.
- Stores new photo to `photos/students` on the **private** disk (same location as POS).
- Updates `$student->photo_path`.
- Returns: `{ photo_url: string }` — the URL of the serve endpoint, e.g. `url("/api/v1/portal/students/{$student->id}/photo")`.

**`show` (serve):**
- Authorization: same `$this->authorize('view', $student)`.
- Returns 404 if `photo_path` is null.
- Returns `Storage::disk('private')->response($student->photo_path)`.

### 3. `Portal/StudentController.php` — Return `photo_url` not `photo_path`

Replace `'photo_path' => $student->photo_path` with:

```php
'photo_url' => $student->photo_path
    ? url("/api/v1/portal/students/{$student->id}/photo")
    : null,
```

### 4. `Portal/ActivityController.php` — Add `payment_method` filter

Accept optional `payment_method` query param (`cash`, `wallet`). Apply a `when()` condition to the orders query.

```php
->when($request->payment_method, fn ($q, $m) => $q->where('payment_method', $m))
```

### 5. `Portal/WalletController.php` — Add `type` filter

Accept optional `type` query param (`deposit`, `withdraw`). Apply to the wallet transactions query.

The frontend maps pill labels to param values before calling the API: "Top-up" → `deposit`, "Deductions" → `withdraw`.

### 6. `routes/portal-api.php` — Register new routes

```php
Route::get('students/{student}/photo', [StudentPhotoController::class, 'show']);
Route::post('students/{student}/photo', [StudentPhotoController::class, 'store']);
```

---

## Frontend Type & API Updates (sunbites-portal)

### `types/portal.ts`

Replace `photo_path` with `photo_url` in `StudentSummary`, and add missing fields:
```typescript
photo_url: string | null;    // authenticated serve endpoint URL, null if no photo
birthday: string | null;     // ISO date string "YYYY-MM-DD"
notes: string | null;
qr_code: string | null;
```

### `lib/api/portal.ts`

Add to `studentsApi`:
```typescript
fetchPhoto: async (id: number): Promise<string | null> => {
  // Fetches GET /portal/students/{id}/photo with auth header
  // Returns a blob URL (URL.createObjectURL) or null on 404
  // Caller is responsible for revoking via URL.revokeObjectURL() on unmount
}

uploadPhoto: async (id: number, file: File): Promise<{ photo_url: string }> => {
  // Same multipart fetch pattern as profileApi.uploadPhoto
  // POSTs to /portal/students/{id}/photo
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

- `react-qr-code` npm package — already installed in `sunbites-pos`; must be added to `sunbites-portal`. Used for rendering the QR SVG and for the print layout.

---

## Tests

### Backend (PHPUnit)
- `StudentControllerTest`: assert `birthday`, `notes`, `qr_code`, `photo_url` are present in response; `photo_url` is null when no photo; `photo_url` points to the serve endpoint when photo exists.
- `StudentPhotoControllerTest`:
  - `store`: happy path upload updates `photo_path`, returns `photo_url`; non-linked student returns 403; invalid file type returns 422; old photo is deleted from private disk.
  - `show`: returns 200 with image response when photo exists; returns 404 when no photo; non-linked student returns 403.
- `ActivityControllerTest`: assert `payment_method=cash` filters out wallet orders; `spending_total` reflects filtered results only.
- `WalletControllerTest`: assert `type=deposit` filters out withdraw transactions.

### Frontend (Jest + RTL)
- `student-header.test.tsx`: renders initials fallback when no photo; camera overlay click opens file picker; upload success toasts and refetches.
- `order-history-tab.test.tsx`: selecting "Cash" pill calls API with `payment_method=cash`; selecting "Today" calls with correct date range; combined filter sends both params.
- `wallet-tab.test.tsx`: selecting "Top-up" pill calls API with `type=deposit`.
- `profile-tab.test.tsx`: renders all fields; birthday is formatted; allergies badge appears when allergies are non-empty; "Meals This Month" card shows only for subscription students.
