# Branch-Switch Data Staleness + Approve Hardening — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make switching the active branch in the POS immediately show the new branch's data (no F5), and stop `PreRegistrationController::approve` from 500-ing on a cross-branch mismatch.

**Architecture:** Frontend — a `BranchCacheSync` client component watches the Zustand `activeBranch` id and calls `queryClient.resetQueries()` on a branch switch (`clear()` on logout), forcing all mounted TanStack Query observers to refetch with the new `X-Branch-Id`. Backend — `approve()` re-fetches the locked pre-registration with `withoutBranch()` (consistent with the unscoped route binding) plus a `null` guard.

**Tech Stack:** Next.js 15 App Router, React 19, TanStack Query v5.101, Zustand (persist/sessionStorage) — `~/sunbites-pos`. Laravel 13, PHPUnit 12, Sail — `~/sunbites-api`.

**Spec:** `docs/superpowers/specs/2026-07-02-branch-switch-data-staleness-design.md`

## Global Constraints

- **Two repos.** Backend tasks run in `~/sunbites-api` (already on branch `fix/pos-branch-switch-staleness`). Frontend tasks run in `~/sunbites-pos` (create branch `fix/pos-branch-switch-staleness` off `main`).
- **Frontend cache primitive (verified):** use `queryClient.resetQueries()` on a branch→branch switch (it refetches mounted observers; `clear()` does **not**). Use `queryClient.clear()` only on logout (`branch → null`).
- **Frontend conventions:** `"use client"` only where hooks are used; **named exports** (no default) for components; no `any`; kebab-case filenames `components/{name}.tsx`; server state via TanStack Query.
- **Backend conventions:** run everything through Sail (`vendor/bin/sail …`); `RefreshDatabase`/`LazilyRefreshDatabase` on feature tests; `actingAs`/Sanctum with the `staff` ability; run `vendor/bin/sail bin pint --dirty --format agent` before committing.
- **TDD:** write the failing test first, watch it fail, implement, watch it pass, commit.

---

### Task 1: Harden `PreRegistrationController::approve` against cross-branch null (backend)

**Files:**
- Modify: `app/Http/Controllers/Kitchen/PreRegistrationController.php` (the `approve()` method, ~line 176)
- Test: `tests/Feature/Kitchen/PreRegistrationApprovalDuplicateTest.php` (add one test + one import)

**Interfaces:**
- Consumes: existing `PreRegistrationApprovalDuplicateTest` setUp (`$this->admin`, `$this->branch`, `preRegWithContact()`, `asAdmin()`), `App\Models\Branch`, `App\Enums\PreRegistrationStatus`.
- Produces: no new public API; `approve()` keeps returning the same JSON shape.

- [ ] **Step 1: Add the `PreRegistrationStatus` import to the test file**

At the top of `tests/Feature/Kitchen/PreRegistrationApprovalDuplicateTest.php`, add to the `use` block:

```php
use App\Enums\PreRegistrationStatus;
```

- [ ] **Step 2: Write the failing regression test**

Add this method to `PreRegistrationApprovalDuplicateTest`:

```php
public function test_approve_succeeds_when_active_branch_differs_from_pre_registration_branch(): void
{
    Mail::fake();

    // Pre-registration belongs to branch A ($this->branch).
    $preReg = $this->preRegWithContact(['student_number' => null]);

    // Admin also has access to a second branch B, which is the ACTIVE branch on the request.
    $branchB = Branch::factory()->create(['is_active' => true]);
    $this->admin->branches()->attach($branchB->id, ['assigned_at' => now(), 'assigned_by' => null]);

    Sanctum::actingAs($this->admin, ['staff']);

    $response = $this->withHeaders(['X-Branch-Id' => $branchB->id])
        ->postJson("/api/v1/pre-registrations/{$preReg->id}/approve");

    $response->assertOk();

    // Enrolled into the pre-registration's OWN branch (A), not the active branch (B).
    $this->assertDatabaseHas('students', [
        'first_name' => 'Juan',
        'last_name' => 'dela Cruz',
        'branch_id' => $this->branch->id,
    ]);
    $this->assertDatabaseHas('pre_registrations', [
        'id' => $preReg->id,
        'status' => PreRegistrationStatus::Approved->value,
    ]);
}
```

- [ ] **Step 3: Run the test and confirm it FAILS**

Run: `vendor/bin/sail artisan test --compact --filter=test_approve_succeeds_when_active_branch_differs_from_pre_registration_branch`
Expected: FAIL — HTTP 500, `Attempt to read property "status" on null` at `PreRegistrationController.php:179` (the scoped `find()` returns `null`).

- [ ] **Step 4: Apply the fix**

In `app/Http/Controllers/Kitchen/PreRegistrationController.php`, inside `approve()`, replace the locked re-fetch (currently line 176):

```php
// Before
$locked = PreRegistration::lockForUpdate()->find($preRegistration->id);
```

```php
// After
$locked = PreRegistration::withoutBranch()->lockForUpdate()->find($preRegistration->id);

abort_if($locked === null, 404, 'Pre-registration not found.');
```

Leave the rest of the method unchanged.

- [ ] **Step 5: Run the regression test and confirm it PASSES**

Run: `vendor/bin/sail artisan test --compact --filter=test_approve_succeeds_when_active_branch_differs_from_pre_registration_branch`
Expected: PASS.

- [ ] **Step 6: Run the full approval suite (no regressions)**

Run: `vendor/bin/sail artisan test --compact tests/Feature/Kitchen/PreRegistrationApprovalDuplicateTest.php`
Expected: all tests pass.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Http/Controllers/Kitchen/PreRegistrationController.php tests/Feature/Kitchen/PreRegistrationApprovalDuplicateTest.php
git commit -m "fix: approve pre-registration regardless of active branch

Re-fetch the locked pre-registration with withoutBranch() so it matches
the (unscoped) route binding, and guard against null, instead of reading
->status on a null scoped find(). Fixes the cross-branch 500."
```

---

### Task 2: `BranchCacheSync` watcher + wire into providers (frontend)

**Files:**
- Create: `components/branch-cache-sync.tsx`
- Create: `components/branch-cache-sync.test.tsx`
- Modify: `app/providers.tsx`

**Interfaces:**
- Consumes: `useAuthStore` from `@/lib/store/auth` (selector `s.activeBranch?.id`), `useQueryClient` from `@tanstack/react-query`, `apiClient` from `@/lib/api/client` (test only), `server` from `@/__tests__/mocks/server` (test only).
- Produces: `export function BranchCacheSync(): null` — a render-null side-effect component mounted inside `QueryClientProvider`.

- [ ] **Step 1: Create the feature branch in the POS repo**

```bash
cd ~/sunbites-pos
git switch main && git switch -c fix/pos-branch-switch-staleness
```

(If the working tree is dirty, stash or commit unrelated work first.)

- [ ] **Step 2: Write the failing test**

Create `components/branch-cache-sync.test.tsx`:

```tsx
import { act, render, screen, waitFor } from "@testing-library/react";
import {
  QueryClient,
  QueryClientProvider,
  useQuery,
} from "@tanstack/react-query";
import { http, HttpResponse } from "msw";

import { server } from "@/__tests__/mocks/server";
import { apiClient } from "@/lib/api/client";
import { useAuthStore } from "@/lib/store/auth";

import { BranchCacheSync } from "./branch-cache-sync";

import type { AuthUser, Branch } from "@/types/auth";

const API = process.env.NEXT_PUBLIC_API_URL;
const branchA: Branch = { id: 1, name: "Antipolo", slug: "antipolo" };
const branchB: Branch = { id: 2, name: "Iloilo", slug: "iloilo" };
const user: AuthUser = {
  id: 1,
  first_name: "Test",
  last_name: "Admin",
  full_name: "Test Admin",
  email: "admin@sunbites.test",
  roles: ["admin"],
  branches: [branchA, branchB],
};

// Echo the active branch header so we can prove which branch the data came from.
function useProbe() {
  return useQuery({
    queryKey: ["probe"],
    queryFn: () => apiClient.get<{ branch: string | null }>("/probe"),
  });
}

function Probe() {
  const { data } = useProbe();
  return <div>branch:{data?.branch ?? "none"}</div>;
}

function renderWithClient() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { staleTime: 60_000, retry: false } },
  });
  const utils = render(
    <QueryClientProvider client={queryClient}>
      <BranchCacheSync />
      <Probe />
    </QueryClientProvider>,
  );
  return { queryClient, ...utils };
}

beforeEach(() => {
  server.use(
    http.get(`${API}/probe`, ({ request }) =>
      HttpResponse.json({ branch: request.headers.get("X-Branch-Id") }),
    ),
  );
  act(() => {
    useAuthStore.getState().logout();
  });
});

describe("BranchCacheSync", () => {
  it("refetches with the new branch when the active branch switches", async () => {
    act(() => {
      useAuthStore.getState().login("token", user);
      useAuthStore.getState().setActiveBranch(branchA);
    });

    renderWithClient();
    expect(await screen.findByText("branch:1")).toBeInTheDocument();

    act(() => {
      useAuthStore.getState().setActiveBranch(branchB);
    });

    expect(await screen.findByText("branch:2")).toBeInTheDocument();
  });

  it("does not reset on first branch selection (null -> B)", async () => {
    act(() => {
      useAuthStore.getState().login("token", user); // activeBranch = null
    });

    const { queryClient } = renderWithClient();
    const resetSpy = jest.spyOn(queryClient, "resetQueries");
    await screen.findByText("branch:none");

    act(() => {
      useAuthStore.getState().setActiveBranch(branchA);
    });

    expect(resetSpy).not.toHaveBeenCalled();
  });

  it("clears the cache on logout (B -> null)", async () => {
    act(() => {
      useAuthStore.getState().login("token", user);
      useAuthStore.getState().setActiveBranch(branchA);
    });

    const { queryClient } = renderWithClient();
    await screen.findByText("branch:1");
    expect(queryClient.getQueryData(["probe"])).toBeDefined();

    act(() => {
      useAuthStore.getState().logout(); // activeBranch -> null
    });

    await waitFor(() =>
      expect(queryClient.getQueryData(["probe"])).toBeUndefined(),
    );
  });
});
```

- [ ] **Step 3: Run the test and confirm it FAILS**

Run: `npx jest components/branch-cache-sync.test.tsx`
Expected: FAIL — cannot resolve `./branch-cache-sync` (module does not exist yet).

- [ ] **Step 4: Create the component**

Create `components/branch-cache-sync.tsx`:

```tsx
"use client";

import { useEffect, useRef } from "react";
import { useQueryClient } from "@tanstack/react-query";

import { useAuthStore } from "@/lib/store/auth";

/**
 * Resets the TanStack Query cache whenever the active branch changes so the UI
 * never shows another branch's data. Renders nothing.
 *
 * - Branch switch (A -> B): resetQueries() — refetches all active observers
 *   with the new X-Branch-Id. (clear() alone does NOT refetch mounted queries.)
 * - Logout (B -> null): clear() — drops the previous user's cached data with
 *   no refetch.
 * - First selection (null -> B) and hard refresh: no-op (guarded).
 */
export function BranchCacheSync() {
  const queryClient = useQueryClient();
  const branchId = useAuthStore((s) => s.activeBranch?.id ?? null);
  const previousBranchId = useRef(branchId);

  useEffect(() => {
    if (
      previousBranchId.current !== null &&
      previousBranchId.current !== branchId
    ) {
      if (branchId === null) {
        queryClient.clear();
      } else {
        queryClient.resetQueries();
      }
    }
    previousBranchId.current = branchId;
  }, [branchId, queryClient]);

  return null;
}
```

- [ ] **Step 5: Run the test and confirm it PASSES**

Run: `npx jest components/branch-cache-sync.test.tsx`
Expected: PASS (3 tests).

- [ ] **Step 6: Wire the watcher into the app**

In `app/providers.tsx`, add the import and render `<BranchCacheSync />` as the first child inside `QueryClientProvider`:

```tsx
"use client";

import { useState } from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

import { BranchCacheSync } from "@/components/branch-cache-sync";

interface ProvidersProps {
  children: React.ReactNode;
}

export function Providers({ children }: ProvidersProps) {
  const [queryClient] = useState(
    () =>
      new QueryClient({
        defaultOptions: {
          queries: { staleTime: 60_000, retry: 1 },
        },
      }),
  );

  return (
    <QueryClientProvider client={queryClient}>
      <BranchCacheSync />
      {children}
    </QueryClientProvider>
  );
}
```

- [ ] **Step 7: Type-check, lint, and run the full test suite**

Run:
```bash
npm run type-check
npm run lint
npx jest
```
Expected: type-check clean, lint clean, all tests pass (247 existing + 3 new).

- [ ] **Step 8: Commit**

```bash
git add components/branch-cache-sync.tsx components/branch-cache-sync.test.tsx app/providers.tsx
git commit -m "fix: reset query cache on branch switch so POS shows new branch data

Add BranchCacheSync watcher that calls queryClient.resetQueries() when the
active branch changes (clear() on logout) and mount it in Providers. Fixes
stale previous-branch data persisting until a hard refresh."
```

---

## Self-Review

**1. Spec coverage:**
- Frontend `resetQueries()` on switch, `clear()` on logout, guard on `null → B` and F5 → Task 2 (component + tests + wiring). ✅
- Backend `withoutBranch()->lockForUpdate()->find()` + null guard → Task 1. ✅
- Backend regression test (branch mismatch → 200, enrolled into pre-reg's branch) → Task 1 Step 2. ✅
- Frontend tests: switch refetches / first-login no-reset / logout clears → Task 2 Step 2. ✅
- Out-of-scope items (key namespacing, middleware reorder, per-branch authz) → not implemented, correct. ✅

**2. Placeholder scan:** No TBD/TODO; every code step shows complete code and exact commands. ✅

**3. Type consistency:** `BranchCacheSync` (no props, returns `null`) used identically in the test, the component, and `providers.tsx`. `resetQueries()`/`clear()`/`getQueryData` are the real TanStack v5 QueryClient methods. `Branch`/`AuthUser` shapes match `lib/store/auth.test.ts` fixtures. `PreRegistrationStatus::Approved->value === 'approved'`. ✅
