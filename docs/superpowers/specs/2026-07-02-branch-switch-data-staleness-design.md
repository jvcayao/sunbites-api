# Branch-Switch Data Staleness (POS) + Approve-Endpoint Hardening

**Date:** 2026-07-02
**Status:** Approved for implementation
**Projects:** `~/sunbites-pos` (frontend), `~/sunbites-api` (backend)

---

## Problem

When a staff/admin user switches their active branch in the POS (e.g. Antipolo → Iloilo), the on-screen data keeps showing the **previous** branch's records. Only a hard refresh (F5) makes the data switch to the new branch.

### Root cause (confirmed in code)

1. `activeBranch` is stored in the Zustand auth store (`lib/store/auth.ts`, persisted to `sessionStorage`).
2. The API client (`lib/api/client.ts`) reads `store.activeBranch` **per request** and sends the `X-Branch-Id` header — so *future* requests already use the new branch.
3. Switching branch is a **client-side** navigation: `handleSelectBranch` in `app/(auth)/branch/page.tsx` calls `setActiveBranch(branch)` then `router.push(...)`. No full page reload occurs, so the single long-lived `QueryClient` (`app/providers.tsx`, created once via `useState`) survives.
4. TanStack Query keys **do not include the branch id** (`["students", params]`, `["analytics", params]`, `["parents", params]`, …), and **nothing clears or invalidates the query cache when the branch changes**. With `staleTime: 60_000`, TanStack Query considers the cached (old-branch) data fresh and serves it without refetching.
5. Pressing F5 destroys the in-memory `QueryClient`, so every query refetches — this time with the new `X-Branch-Id` — which is why a hard refresh "fixes" it.

The same cross-branch mismatch is the source of the Sentry error `ErrorException: Attempt to read property "status" on null` at `PreRegistrationController::approve` (line 179): route-model binding runs (as middleware) **before** `SetActiveBranch`, so it resolves the pre-registration unscoped; the scoped `lockForUpdate()->find()` inside the transaction then returns `null` when the active branch differs from the record's branch.

---

## Goals

- Switching active branch immediately shows the new branch's data — no F5 required.
- No possibility of stale or cross-branch data being displayed after a switch.
- The `approve` endpoint can never crash (500) on a branch mismatch — defense-in-depth for the residual edge cases the frontend fix cannot cover (deep links, direct navigation, requests in flight during a switch).

## Non-Goals (Out of Scope)

- Namespacing every TanStack Query key by branch id (rejected: invasive, fragile — a single missed key silently reintroduces the bug).
- Changing the middleware ordering so route-model binding becomes branch-scoped globally (too broad; would change binding semantics for every branch-scoped route).
- Per-branch authorization on `approve` (a separate feature; approve is currently gated only by `role:admin|manager`).

---

## Frontend Design (`~/sunbites-pos`)

### Strategy: reset the query cache on branch change

On a branch switch, call **`queryClient.resetQueries()`**. This resets every query to its initial state (clearing stale data) **and refetches all active observers**, so every mounted screen — including persistent layout queries like the notification/reminder bells — reloads with the new `X-Branch-Id`. Chosen over per-key namespacing because it is central (one file), future-proof (no per-key upkeep), and makes cross-branch data leakage structurally impossible.

> **Verified empirically (TanStack Query v5.101.0, `staleTime: 60_000`).** `queryClient.clear()` does **not** refetch already-mounted observers — a component that stays mounted (e.g. a header/bell) keeps showing stale data after `clear()`. `resetQueries()` **does** refetch active observers and resets their data. `invalidateQueries()` also refetches but keeps the old data visible during the background refetch (a stale flash). An end-to-end probe (watcher + branch-scoped query) confirmed `resetQueries()` updates the screen to the new branch's data with no reload. This corrects an earlier draft that used `clear()`.

On **logout** (`B → null`) the watcher uses `queryClient.clear()` instead: fully remove the previous user's cached data with no refetch (avoids firing token-less requests as the app redirects to login).

Trade-off accepted: after a switch, screens briefly show their loading/skeleton states, and switching back to a previously-viewed branch refetches rather than restoring instantly.

### Component: `BranchCacheSync`

A small client component that watches the active branch id and resets the cache when it changes. It is rendered **inside** `QueryClientProvider` so it can access `useQueryClient()`.

```tsx
// components/branch-cache-sync.tsx
"use client";

import { useEffect, useRef } from "react";
import { useQueryClient } from "@tanstack/react-query";

import { useAuthStore } from "@/lib/store/auth";

export function BranchCacheSync() {
  const queryClient = useQueryClient();
  const branchId = useAuthStore((s) => s.activeBranch?.id ?? null);
  const previousBranchId = useRef(branchId);

  useEffect(() => {
    if (previousBranchId.current !== null && previousBranchId.current !== branchId) {
      if (branchId === null) {
        queryClient.clear(); // logout: wipe prior user's data, no refetch
      } else {
        queryClient.resetQueries(); // switch: reset + refetch active with new branch
      }
    }
    previousBranchId.current = branchId;
  }, [branchId, queryClient]);

  return null;
}
```

Mounted in `app/providers.tsx`:

```tsx
return (
  <QueryClientProvider client={queryClient}>
    <BranchCacheSync />
    {children}
  </QueryClientProvider>
);
```

### Behavior matrix

| Transition | When | Action | Rationale |
|---|---|---|---|
| `null → B` | First branch selection after login | none | `previousBranchId` is `null`; guard skips (cache is empty anyway) |
| `A → B` | Branch switch | **`resetQueries()`** | Reset stale data + refetch active observers with new branch |
| `B → null` | Logout (`logout()` sets `activeBranch` to `null`) | **`clear()`** | Security bonus: drop prior user's cached data, no refetch |
| (F5 / hard refresh) | Store rehydrates from `sessionStorage` | none | The `!== null` guard skips regardless of whether Zustand hydrates synchronously (prev initializes equal) or asynchronously (prev starts `null`); the cache is fresh anyway |

Note: after `resetQueries()`, active observers refetch (verified). Branch-agnostic queries (branch list, user profile) also refetch — cheap and acceptable.

---

## Backend Design (`~/sunbites-api`)

### Harden `PreRegistrationController::approve`

Make the locked re-fetch consistent with the (unscoped) route binding, and guard against `null`. At `app/Http/Controllers/Kitchen/PreRegistrationController.php` (currently line 176):

```php
// Before
$locked = PreRegistration::lockForUpdate()->find($preRegistration->id);

// After
$locked = PreRegistration::withoutBranch()->lockForUpdate()->find($preRegistration->id);

abort_if($locked === null, 404, 'Pre-registration not found.');
```

- `withoutBranch()` (from the `HasBranch` trait) removes the `BranchScope`, so the lock targets exactly the record route-model binding already resolved — regardless of the active branch. This eliminates the `null` return that caused the 500.
- The `abort_if` is defensive: it converts any genuinely-missing record (e.g. deleted between binding and lock) into a clean `404` instead of a fatal property-read on `null`.
- No authorization change: `approve` is gated by `role:admin|manager`, and binding was already cross-branch, so this does not widen access.

---

## Data Flow (after fix)

1. User switches branch on `/branch` → `setActiveBranch(B)`.
2. `BranchCacheSync` detects `A → B` → `queryClient.resetQueries()` (active observers refetch; inactive queries reset for fresh fetch on next mount).
3. `router.push(home)`; mounted/newly-mounted screens fetch with the new branch.
4. API client sends `X-Branch-Id: B` → backend returns branch B data.
5. If any request still reaches `approve` with a mismatched branch (edge case), `withoutBranch()->find()` resolves the record and the endpoint responds normally (no 500).

---

## Testing

### Frontend (Jest 30 + RTL + MSW 2)

- **Switch refetches new-branch data (end-to-end):** render a screen that consumes a branch-scoped query; MSW handler returns different data depending on the `X-Branch-Id` request header; change `activeBranch` from A to B via the store; assert the rendered data updates from branch A's to branch B's values. (Behavior test — mirrors the verified probe.)
- **First login / F5 does not over-refetch:** with an empty cache, transition `null → B`; assert `resetQueries` is not triggered (guards the `previousBranchId !== null` condition).
- **Logout clears:** transition `B → null`; assert cached data is dropped and no refetch is fired.

### Backend (PHPUnit 12)

- **`test_approve_succeeds_when_active_branch_differs_from_pre_registration_branch`** — pending pre-reg in branch A; admin with access to branch A **and** branch B; `actingAs` + `X-Branch-Id: B`; POST approve → asserts `200`/`assertOk`, student enrolled, pre-reg status `approved`. (Reproduces the exact Sentry scenario; asserts success instead of 500.)
- Existing `PreRegistrationApprovalDuplicateTest` cases (duplicate blocking, parent linking, happy path) remain green.

---

## Files Touched

**Frontend (`~/sunbites-pos`):**
- `components/branch-cache-sync.tsx` — new watcher component
- `components/branch-cache-sync.test.tsx` — new tests
- `app/providers.tsx` — render `<BranchCacheSync />` inside `QueryClientProvider`

**Backend (`~/sunbites-api`):**
- `app/Http/Controllers/Kitchen/PreRegistrationController.php` — `withoutBranch()` + `abort_if` null guard in `approve()`
- `tests/Feature/Kitchen/PreRegistrationApprovalDuplicateTest.php` — new regression test (or a new dedicated test file)
