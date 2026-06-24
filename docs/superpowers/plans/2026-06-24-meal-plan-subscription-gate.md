# Meal Plan Subscription Gate — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Gate the weekly meal plan behind subscription access — parents with only non-subscription students cannot see or access it; the backend enforces this with a 403.

**Architecture:** A `has_subscription_student` boolean is added to every parent auth response and stored in the Zustand auth store. The portal layout filters the nav array to hide the Meal Plan link; the meal plan page redirects via `useEffect + router.replace`. A reactive sync on three student-fetching pages keeps the flag accurate after staff enrollment changes. The backend enforces the rule independently via an existence check at the top of `MealPlannerController::show`.

**Tech Stack:** Laravel 13 / PHPUnit 12 (API) · Next.js App Router / React 19 / Zustand / TanStack Query / Jest 30 / RTL (portal)

## Global Constraints

- All PHP commands run through Sail: `vendor/bin/sail artisan …`
- Run Pint after every PHP file change: `vendor/bin/sail bin pint --dirty --format agent`
- Run tests with `--compact` flag. Run minimum required tests during development.
- All backend tests use `LazilyRefreshDatabase` and real database — no mocking Eloquent.
- Portal tests import `render` from `@/__tests__/test-utils`, not from `@testing-library/react`.
- No `any` in TypeScript. No `console.log` in committed code.
- Student factory states: `Student::factory()->subscription()` and `Student::factory()->nonSubscription()`.
- Parent-student pivot requires three extra fields: `linked_at`, `linked_by`, `wallet_alert_threshold`.

---

## File Map

| File | Action | What changes |
|---|---|---|
| `app/Http/Controllers/Portal/AuthController.php` | Modify | Add `has_subscription_student` to login response |
| `app/Http/Controllers/Portal/ProfileController.php` | Modify | Add `has_subscription_student` to `parentData()` helper |
| `app/Http/Controllers/Portal/MealPlannerController.php` | Modify | Add subscription guard at top of `show()` |
| `tests/Feature/Portal/PortalAuthTest.php` | Modify | Assert `has_subscription_student` in login response |
| `tests/Feature/Portal/ProfileTest.php` | Modify | Assert `has_subscription_student` in profile/update responses |
| `tests/Feature/Portal/MealPlannerAccessTest.php` | Create | Test 403/200 cases + login/profile flag |
| `~/sunbites-portal/types/auth.ts` | Modify | Add `has_subscription_student: boolean` to `AuthParent` |
| `~/sunbites-portal/lib/store/auth.ts` | Modify | Add `updateParent` action |
| `~/sunbites-portal/lib/store/auth.test.ts` | Modify | Add `has_subscription_student` to `mockParent`; add `updateParent` test |
| `~/sunbites-portal/components/app-logo.tsx` | Modify | Replace circle "S" with `<Image src="/icon.png">` |
| `~/sunbites-portal/components/layouts/portal-layout.tsx` | Modify | Filter `navLinks` by subscription flag |
| `~/sunbites-portal/components/layouts/portal-layout.test.tsx` | Create | Test nav shows/hides Meal Plan link |
| `~/sunbites-portal/app/(portal)/meal-plan/page.tsx` | Modify | Add `useEffect` route guard |
| `~/sunbites-portal/app/(portal)/meal-plan/page.test.tsx` | Create | Test redirect and render |
| `~/sunbites-portal/app/(portal)/students/page.tsx` | Modify | Add reactive flag sync |
| `~/sunbites-portal/app/(portal)/students/[id]/page.tsx` | Modify | Add reactive flag sync |
| `~/sunbites-portal/app/(portal)/feedback/page.tsx` | Modify | Add reactive flag sync |

---

## Task 1: Backend — Auth responses include `has_subscription_student`

**Files:**
- Modify: `app/Http/Controllers/Portal/AuthController.php`
- Modify: `app/Http/Controllers/Portal/ProfileController.php`
- Modify: `tests/Feature/Portal/PortalAuthTest.php`
- Modify: `tests/Feature/Portal/ProfileTest.php`

**Interfaces:**
- Produces: `parent.has_subscription_student` (bool) in login response and all profile responses — consumed by Task 6 (frontend type) and Task 7 (store).

- [ ] **Step 1: Write failing tests**

In `tests/Feature/Portal/PortalAuthTest.php`, update `test_activated_parent_can_login` and add two new tests. The existing setUp already creates a student attached to the parent without specifying `student_type`, which defaults to a random type — add explicit subscription/non-subscription variants:

```php
public function test_activated_parent_can_login(): void
{
    $response = $this->postJson('/api/v1/portal/auth/login', [
        'email' => 'maria@example.com',
        'password' => 'Password1!',
    ]);

    $response->assertOk()
        ->assertJsonStructure([
            'token',
            'parent' => ['id', 'first_name', 'last_name', 'email', 'has_subscription_student'],
        ]);
}

public function test_login_response_has_subscription_student_true_when_parent_has_subscription_student(): void
{
    // Override the default student (created in setUp) with a subscription one.
    $this->student->update(['student_type' => \App\Enums\StudentType::Subscription->value]);

    $response = $this->postJson('/api/v1/portal/auth/login', [
        'email' => 'maria@example.com',
        'password' => 'Password1!',
    ]);

    $response->assertOk()
        ->assertJsonPath('parent.has_subscription_student', true);
}

public function test_login_response_has_subscription_student_false_when_all_students_are_non_subscription(): void
{
    $this->student->update(['student_type' => \App\Enums\StudentType::NonSubscription->value]);

    $response = $this->postJson('/api/v1/portal/auth/login', [
        'email' => 'maria@example.com',
        'password' => 'Password1!',
    ]);

    $response->assertOk()
        ->assertJsonPath('parent.has_subscription_student', false);
}
```

In `tests/Feature/Portal/ProfileTest.php`, add two new tests at the bottom. Note: `ProfileTest` creates a parent without any linked students (setUp does not attach students). For these tests, create a branch and student first:

```php
public function test_get_profile_includes_has_subscription_student_false_when_no_students(): void
{
    $response = $this->asParent()->getJson('/api/v1/portal/profile');

    $response->assertOk()
        ->assertJsonPath('has_subscription_student', false);
}

public function test_get_profile_includes_has_subscription_student_true_when_subscription_student_linked(): void
{
    $branch = \App\Models\Branch::factory()->create(['is_active' => true]);
    $staff = \App\Models\User::factory()->create();
    $student = \App\Models\Student::factory()->subscription()->create(['branch_id' => $branch->id]);
    $this->parent->students()->attach($student->id, [
        'linked_at' => now(),
        'linked_by' => $staff->id,
        'wallet_alert_threshold' => 0,
    ]);

    $response = $this->asParent()->getJson('/api/v1/portal/profile');

    $response->assertOk()
        ->assertJsonPath('has_subscription_student', true);
}

public function test_patch_profile_response_includes_has_subscription_student(): void
{
    $response = $this->asParent()->patchJson('/api/v1/portal/profile', [
        'first_name' => 'Updated',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['has_subscription_student']);
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
vendor/bin/sail artisan test --compact --filter="test_login_response_has_subscription_student|test_get_profile_includes_has_subscription|test_patch_profile_response_includes"
```

Expected: FAIL — `has_subscription_student` key not found in response.

- [ ] **Step 3: Implement `AuthController` — add flag to login response**

In `app/Http/Controllers/Portal/AuthController.php`:

Add the import after the existing `use` statements:
```php
use App\Enums\StudentType;
```

Update the `login` method's return statement (replace only the `'parent' => [...]` array):
```php
return response()->json([
    'token' => $token,
    'parent' => [
        'id'                       => $parent->id,
        'first_name'               => $parent->first_name,
        'last_name'                => $parent->last_name,
        'email'                    => $parent->email,
        'phone'                    => $parent->phone,
        'address'                  => $parent->address,
        'profile_photo_url'        => $parent->profile_photo_url,
        'has_subscription_student' => $parent->students()
                                          ->where('student_type', StudentType::Subscription)
                                          ->exists(),
    ],
]);
```

- [ ] **Step 4: Implement `ProfileController` — add flag to `parentData()` helper**

In `app/Http/Controllers/Portal/ProfileController.php`:

Add the import after the existing `use` statements:
```php
use App\Enums\StudentType;
```

Replace the `parentData()` method body:
```php
/** @return array<string, mixed> */
private function parentData(ParentUser $parent): array
{
    return [
        'id'                       => $parent->id,
        'first_name'               => $parent->first_name,
        'last_name'                => $parent->last_name,
        'email'                    => $parent->email,
        'phone'                    => $parent->phone,
        'address'                  => $parent->address,
        'profile_photo_url'        => $parent->profile_photo_url,
        'has_subscription_student' => $parent->students()
                                          ->where('student_type', StudentType::Subscription)
                                          ->exists(),
    ];
}
```

- [ ] **Step 5: Format with Pint**

```bash
vendor/bin/sail bin pint --dirty --format agent
```

- [ ] **Step 6: Run the failing tests**

```bash
vendor/bin/sail artisan test --compact --filter="test_login_response_has_subscription_student|test_get_profile_includes_has_subscription|test_patch_profile_response_includes"
```

Expected: All PASS.

- [ ] **Step 7: Run the full Portal auth + profile suite to confirm no regressions**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Portal/PortalAuthTest.php tests/Feature/Portal/ProfileTest.php
```

Expected: All PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Portal/AuthController.php \
        app/Http/Controllers/Portal/ProfileController.php \
        tests/Feature/Portal/PortalAuthTest.php \
        tests/Feature/Portal/ProfileTest.php
git commit -m "feat(portal): add has_subscription_student to auth and profile responses"
```

---

## Task 2: Backend — MealPlannerController subscription guard

**Files:**
- Modify: `app/Http/Controllers/Portal/MealPlannerController.php`
- Create: `tests/Feature/Portal/MealPlannerAccessTest.php`

**Interfaces:**
- Consumes: `StudentType::Subscription` enum, `ParentUser::students()` relationship.
- Produces: `GET /api/v1/portal/meal-planner` returns `403` when parent has no subscription students.

- [ ] **Step 1: Create the test file**

```bash
vendor/bin/sail artisan make:test --phpunit tests/Feature/Portal/MealPlannerAccessTest
```

Replace its contents with:

```php
<?php

namespace Tests\Feature\Portal;

use App\Enums\StudentType;
use App\Models\Branch;
use App\Models\ParentUser;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MealPlannerAccessTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    private User $staffUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->staffUser = User::factory()->create();
    }

    private function createParent(): ParentUser
    {
        return ParentUser::create([
            'first_name'        => 'Maria',
            'last_name'         => 'Santos',
            'email'             => 'maria@example.com',
            'password'          => Hash::make('Password1!'),
            'email_verified_at' => now(),
        ]);
    }

    private function attachStudent(ParentUser $parent, Student $student): void
    {
        $parent->students()->attach($student->id, [
            'linked_at'              => now(),
            'linked_by'              => $this->staffUser->id,
            'wallet_alert_threshold' => 0,
        ]);
    }

    private function asParent(ParentUser $parent): static
    {
        $token = $parent->createToken('portal-token', ['parent'])->plainTextToken;

        return $this->withToken($token);
    }

    public function test_parent_with_only_non_subscription_students_cannot_access_meal_plan(): void
    {
        $parent = $this->createParent();
        $student = Student::factory()->nonSubscription()->create(['branch_id' => $this->branch->id]);
        $this->attachStudent($parent, $student);

        $response = $this->asParent($parent)
            ->getJson('/api/v1/portal/meal-planner?month=june&week=1');

        $response->assertForbidden();
    }

    public function test_parent_with_only_subscription_students_can_access_meal_plan(): void
    {
        $parent = $this->createParent();
        $student = Student::factory()->subscription()->create(['branch_id' => $this->branch->id]);
        $this->attachStudent($parent, $student);

        $response = $this->asParent($parent)
            ->getJson('/api/v1/portal/meal-planner?month=june&week=1');

        $response->assertOk();
    }

    public function test_parent_with_mixed_students_can_access_meal_plan(): void
    {
        $parent = $this->createParent();

        $subStudent = Student::factory()->subscription()->create(['branch_id' => $this->branch->id]);
        $nonSubStudent = Student::factory()->nonSubscription()->create(['branch_id' => $this->branch->id]);
        $this->attachStudent($parent, $subStudent);
        $this->attachStudent($parent, $nonSubStudent);

        $response = $this->asParent($parent)
            ->getJson('/api/v1/portal/meal-planner?month=june&week=1');

        $response->assertOk();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/portal/meal-planner?month=june&week=1')
            ->assertUnauthorized();
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Portal/MealPlannerAccessTest.php
```

Expected: `test_parent_with_only_non_subscription_students_cannot_access_meal_plan` FAILS (gets 200, not 403). Others may pass/fail depending on data.

- [ ] **Step 3: Implement the subscription guard in `MealPlannerController`**

In `app/Http/Controllers/Portal/MealPlannerController.php`:

Add the import after the existing `use` statements:
```php
use App\Enums\StudentType;
```

In the `show()` method, add the guard as the **first thing after** `$parent = $request->user();`:

```php
public function show(Request $request): JsonResponse
{
    $validated = $request->validate([
        'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        'month'     => ['nullable', 'string'],
        'week'      => ['nullable', 'integer', 'min:1', 'max:4'],
    ]);

    $parent = $request->user();

    if (! $parent->students()->where('student_type', StudentType::Subscription)->exists()) {
        abort(403, 'Meal plan access requires a subscription student.');
    }

    // ... rest of the existing method unchanged
```

- [ ] **Step 4: Format with Pint**

```bash
vendor/bin/sail bin pint --dirty --format agent
```

- [ ] **Step 5: Run the access tests**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Portal/MealPlannerAccessTest.php
```

Expected: All 4 PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Portal/MealPlannerController.php \
        tests/Feature/Portal/MealPlannerAccessTest.php
git commit -m "feat(portal): restrict meal plan API to parents with subscription students"
```

---

## Task 3: Frontend — `AuthParent` type + Zustand `updateParent` action

**Files:**
- Modify: `~/sunbites-portal/types/auth.ts`
- Modify: `~/sunbites-portal/lib/store/auth.ts`
- Modify: `~/sunbites-portal/lib/store/auth.test.ts`

**Interfaces:**
- Produces: `AuthParent.has_subscription_student: boolean` — consumed by Tasks 4, 5, 6, 7.
- Produces: `useAuthStore().updateParent(parent: AuthParent): void` — consumed by Task 7.

- [ ] **Step 1: Update `auth.test.ts` — add field to `mockParent` and add `updateParent` test**

Replace the `mockParent` constant and add a new test in `lib/store/auth.test.ts`:

```typescript
const mockParent: AuthParent = {
  id: 1,
  first_name: "Maria",
  last_name: "Santos",
  email: "parent@sunbites.test",
  phone: null,
  address: null,
  profile_photo_url: null,
  created_at: "2026-01-01T00:00:00.000000Z",
  has_subscription_student: false, // new field
};
```

Add this test inside the existing `describe` block:

```typescript
it("updateParent() replaces parent while keeping token", () => {
  const { result } = renderHook(() => useAuthStore());

  act(() => {
    result.current.login("test-token", mockParent);
  });

  const updated: AuthParent = { ...mockParent, has_subscription_student: true };

  act(() => {
    result.current.updateParent(updated);
  });

  expect(result.current.token).toBe("test-token");
  expect(result.current.parent?.has_subscription_student).toBe(true);
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd ~/sunbites-portal && npx jest --testPathPattern="lib/store/auth.test.ts" --no-coverage
```

Expected: TypeScript error on `mockParent` (missing `has_subscription_student`) AND `updateParent` not found.

- [ ] **Step 3: Add `has_subscription_student` to `AuthParent` in `types/auth.ts`**

```typescript
export interface AuthParent {
  id: number;
  first_name: string;
  last_name: string;
  email: string;
  phone: string | null;
  address: string | null;
  profile_photo_url: string | null;
  created_at: string;
  has_subscription_student: boolean;
}
```

- [ ] **Step 4: Add `updateParent` action to `lib/store/auth.ts`**

```typescript
import { create } from "zustand";
import { createJSONStorage, persist } from "zustand/middleware";

import type { AuthParent } from "@/types/auth";

interface AuthState {
  token: string | null;
  parent: AuthParent | null;
  login: (token: string, parent: AuthParent) => void;
  logout: () => void;
  updateParent: (parent: AuthParent) => void;
}

export const useAuthStore = create<AuthState>()(
  persist(
    (set) => ({
      token: null,
      parent: null,
      login: (token, parent) => set({ token, parent }),
      logout: () => set({ token: null, parent: null }),
      updateParent: (parent) => set({ parent }),
    }),
    {
      name: "portal-auth",
      storage: createJSONStorage(() => sessionStorage),
    },
  ),
);
```

- [ ] **Step 5: Run the store tests**

```bash
cd ~/sunbites-portal && npx jest --testPathPattern="lib/store/auth.test.ts" --no-coverage
```

Expected: All PASS.

- [ ] **Step 6: Commit**

```bash
cd ~/sunbites-portal && git add types/auth.ts lib/store/auth.ts lib/store/auth.test.ts
git commit -m "feat(portal): add has_subscription_student to AuthParent and updateParent to store"
```

---

## Task 4: Frontend — Replace `AppLogo` circle "S" with `icon.png`

**Files:**
- Modify: `~/sunbites-portal/components/app-logo.tsx`

**Interfaces:**
- None. Pure visual change; `AppLogo` interface (`variant`, `className`) is unchanged.

- [ ] **Step 1: Replace `components/app-logo.tsx`**

```typescript
import Image from "next/image";

import { cn } from "@/lib/utils";

interface AppLogoProps {
  variant?: "full" | "icon";
  className?: string;
}

export function AppLogo({ variant = "full", className }: AppLogoProps) {
  if (variant === "icon") {
    return (
      <Image
        src="/icon.png"
        alt="Sunbites"
        width={40}
        height={40}
        className={cn("rounded-full", className)}
      />
    );
  }

  return (
    <div className={cn("flex items-center gap-3", className)}>
      <Image
        src="/icon.png"
        alt="Sunbites"
        width={40}
        height={40}
        className="rounded-full"
      />
      <div className="flex flex-col">
        <span className="text-base font-bold leading-tight text-foreground">
          Sunbites
        </span>
        <span className="text-xs font-medium leading-tight text-muted-foreground">
          Your Healthy Kitchen
        </span>
      </div>
    </div>
  );
}
```

- [ ] **Step 2: Commit**

```bash
cd ~/sunbites-portal && git add components/app-logo.tsx
git commit -m "feat(portal): replace circle S logo with icon.png in AppLogo"
```

---

## Task 5: Frontend — Conditional Meal Plan nav link

**Files:**
- Modify: `~/sunbites-portal/components/layouts/portal-layout.tsx`
- Create: `~/sunbites-portal/components/layouts/portal-layout.test.tsx`

**Interfaces:**
- Consumes: `useAuthStore(s => s.parent?.has_subscription_student)` — requires Task 3.
- No interface changes — `PortalLayout` props are unchanged.

- [ ] **Step 1: Create the failing test**

Create `components/layouts/portal-layout.test.tsx`:

```typescript
import { render, screen } from "@/__tests__/test-utils";

import { PortalLayout } from "./portal-layout";

// Prevent NotificationBell from making real API calls in layout tests.
jest.mock("@/components/notification-bell", () => ({
  NotificationBell: () => null,
}));

// Prevent AppLogo from requiring /icon.png in jsdom.
jest.mock("@/components/app-logo", () => ({
  AppLogo: () => <span>Logo</span>,
}));

jest.mock("next/navigation", () => ({
  usePathname: () => "/dashboard",
  useRouter: () => ({ push: jest.fn(), replace: jest.fn() }),
}));

const mockAuthState = {
  token: "test-token",
  parent: {
    id: 1,
    first_name: "Maria",
    last_name: "Santos",
    email: "maria@example.com",
    phone: null,
    address: null,
    profile_photo_url: null,
    created_at: "2026-01-01T00:00:00.000000Z",
    has_subscription_student: false,
  },
  login: jest.fn(),
  logout: jest.fn(),
  updateParent: jest.fn(),
};

jest.mock("@/lib/store/auth", () => ({
  useAuthStore: Object.assign(
    (sel: (s: typeof mockAuthState) => unknown) => sel(mockAuthState),
    { getState: () => mockAuthState },
  ),
}));

describe("PortalLayout — Meal Plan nav visibility", () => {
  beforeEach(() => {
    mockAuthState.parent.has_subscription_student = false;
  });

  it("hides the Meal Plan link when has_subscription_student is false", () => {
    render(<PortalLayout><div>page</div></PortalLayout>);

    expect(screen.queryByRole("link", { name: "Meal Plan" })).not.toBeInTheDocument();
  });

  it("shows the Meal Plan link when has_subscription_student is true", () => {
    mockAuthState.parent.has_subscription_student = true;

    render(<PortalLayout><div>page</div></PortalLayout>);

    expect(screen.getByRole("link", { name: "Meal Plan" })).toBeInTheDocument();
  });

  it("always shows Dashboard, Students, and Feedback links regardless of subscription", () => {
    render(<PortalLayout><div>page</div></PortalLayout>);

    expect(screen.getByRole("link", { name: "Dashboard" })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Students" })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Feedback" })).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd ~/sunbites-portal && npx jest --testPathPattern="portal-layout.test" --no-coverage
```

Expected: `hides the Meal Plan link` FAILS — "Meal Plan" link is found when it shouldn't be.

- [ ] **Step 3: Update `portal-layout.tsx` — filter nav links by subscription flag**

In `components/layouts/portal-layout.tsx`, make two changes:

**Change 1** — Add `has_subscription_student` selector below the existing `parent` selector:
```typescript
const parent = useAuthStore((s) => s.parent);
const hasSubscriptionStudent = useAuthStore((s) => s.parent?.has_subscription_student ?? false);
```

**Change 2** — Replace the `navLinks` constant (which is currently a module-level `const`) with a filtered version computed inside the component, right before the `return`:
```typescript
const visibleNavLinks = navLinks.filter(
  (link) => link.href !== "/meal-plan" || hasSubscriptionStudent,
);
```

**Change 3** — Replace all occurrences of `navLinks.map(` with `visibleNavLinks.map(` (there are two: desktop nav and mobile nav drawer).

The full updated function body starts:
```typescript
export function PortalLayout({ children }: PortalLayoutProps) {
  const [mobileNavOpen, setMobileNavOpen] = useState(false);
  const pathname = usePathname();
  const router = useRouter();
  const parent = useAuthStore((s) => s.parent);
  const hasSubscriptionStudent = useAuthStore((s) => s.parent?.has_subscription_student ?? false);

  const visibleNavLinks = navLinks.filter(
    (link) => link.href !== "/meal-plan" || hasSubscriptionStudent,
  );

  async function handleLogout() { /* unchanged */ }

  return (
    // ... in desktop nav: visibleNavLinks.map(...)
    // ... in mobile nav: visibleNavLinks.map(...)
  );
}
```

- [ ] **Step 4: Run the layout tests**

```bash
cd ~/sunbites-portal && npx jest --testPathPattern="portal-layout.test" --no-coverage
```

Expected: All 3 PASS.

- [ ] **Step 5: Commit**

```bash
cd ~/sunbites-portal && git add components/layouts/portal-layout.tsx \
                                components/layouts/portal-layout.test.tsx
git commit -m "feat(portal): hide Meal Plan nav link for non-subscription parents"
```

---

## Task 6: Frontend — Route guard on meal-plan page

**Files:**
- Modify: `~/sunbites-portal/app/(portal)/meal-plan/page.tsx`
- Create: `~/sunbites-portal/app/(portal)/meal-plan/page.test.tsx`

**Interfaces:**
- Consumes: `useAuthStore(s => s.parent?.has_subscription_student)` — requires Task 3.
- No new exports.

- [ ] **Step 1: Create the failing test**

Create `app/(portal)/meal-plan/page.test.tsx`:

```typescript
import { render, screen, waitFor } from "@/__tests__/test-utils";

import MealPlanPage from "./page";

const mockRouter = { replace: jest.fn(), push: jest.fn() };

jest.mock("next/navigation", () => ({
  useRouter: () => mockRouter,
}));

// Mock mealPlanApi to prevent real network calls. The route guard fires
// before the query matters, but the hook is called unconditionally.
jest.mock("@/lib/api/portal", () => ({
  mealPlanApi: {
    get: jest.fn().mockResolvedValue({ visible_to_parents: true, days: [] }),
  },
}));

const mockAuthState = {
  token: "test-token",
  parent: {
    id: 1,
    first_name: "Maria",
    last_name: "Santos",
    email: "maria@example.com",
    phone: null,
    address: null,
    profile_photo_url: null,
    created_at: "2026-01-01T00:00:00.000000Z",
    has_subscription_student: false,
  },
  login: jest.fn(),
  logout: jest.fn(),
  updateParent: jest.fn(),
};

jest.mock("@/lib/store/auth", () => ({
  useAuthStore: Object.assign(
    (sel: (s: typeof mockAuthState) => unknown) => sel(mockAuthState),
    { getState: () => mockAuthState },
  ),
}));

describe("MealPlanPage — route guard", () => {
  beforeEach(() => {
    mockRouter.replace.mockClear();
    mockAuthState.parent.has_subscription_student = false;
  });

  it("redirects to /dashboard when has_subscription_student is false", async () => {
    render(<MealPlanPage />);

    await waitFor(() => {
      expect(mockRouter.replace).toHaveBeenCalledWith("/dashboard");
    });
  });

  it("renders the Meal Plan heading when has_subscription_student is true", () => {
    mockAuthState.parent.has_subscription_student = true;

    render(<MealPlanPage />);

    expect(screen.getByRole("heading", { name: "Meal Plan" })).toBeInTheDocument();
    expect(mockRouter.replace).not.toHaveBeenCalled();
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd ~/sunbites-portal && npx jest --testPathPattern="meal-plan/page.test" --no-coverage
```

Expected: `redirects to /dashboard` FAILS — `router.replace` not called; page renders normally.

- [ ] **Step 3: Add the route guard to `app/(portal)/meal-plan/page.tsx`**

The page is already `"use client"`. Add three imports at the top and the guard inside the component. The hooks must appear before the early return (React rules).

Add to the import block:
```typescript
import { useEffect } from "react";
import { useRouter } from "next/navigation";
import { useAuthStore } from "@/lib/store/auth";
```

Inside `MealPlanPage`, add the guard after the existing `useState` hooks and before the `useQuery`:

```typescript
export default function MealPlanPage() {
  const [activeMonth, setActiveMonth] = useState<string>("june");
  const [activeWeek, setActiveWeek] = useState<number>(1);

  // Route guard — subscription access only.
  const router = useRouter();
  const hasSubscriptionStudent = useAuthStore(
    (s) => s.parent?.has_subscription_student ?? false,
  );

  // All hooks must be declared before any conditional return.
  const { data, isLoading, error } = useQuery({
    queryKey: ["portal-meal-plan", activeMonth, activeWeek],
    queryFn: () => mealPlanApi.get(activeMonth, activeWeek),
    enabled: hasSubscriptionStudent, // skip the query when access is denied
  });

  useEffect(() => {
    if (!hasSubscriptionStudent) {
      router.replace("/dashboard");
    }
  }, [hasSubscriptionStudent, router]);

  // Return null to prevent content flash while the redirect fires.
  if (!hasSubscriptionStudent) return null;

  return (
    // ... rest of existing JSX unchanged
  );
}
```

Note: `enabled: hasSubscriptionStudent` is added to the query options — this prevents an unnecessary network call when the user is being redirected.

- [ ] **Step 4: Run the route guard tests**

```bash
cd ~/sunbites-portal && npx jest --testPathPattern="meal-plan/page.test" --no-coverage
```

Expected: Both PASS.

- [ ] **Step 5: Commit**

```bash
cd ~/sunbites-portal && git add "app/(portal)/meal-plan/page.tsx" \
                                "app/(portal)/meal-plan/page.test.tsx"
git commit -m "feat(portal): add subscription route guard to meal plan page"
```

---

## Task 7: Frontend — Reactive flag sync on student-fetching pages

**Files:**
- Modify: `~/sunbites-portal/app/(portal)/students/page.tsx`
- Modify: `~/sunbites-portal/app/(portal)/students/[id]/page.tsx`
- Modify: `~/sunbites-portal/app/(portal)/feedback/page.tsx`

**Interfaces:**
- Consumes: `useAuthStore(s => s.updateParent)` and `useAuthStore(s => s.parent)` — requires Task 3.
- No new exports.

**Why three pages:** `studentsApi.list` is called in `StudentsPage`, the detail page component (for the student header and tab list), and `FeedbackForm` (a sub-component of the feedback page). The sync needs to run wherever the list is freshly fetched so it fires on normal navigation.

- [ ] **Step 1: Update `app/(portal)/students/page.tsx`**

Add two imports at the top:
```typescript
import { useEffect } from "react";
import { useAuthStore } from "@/lib/store/auth";
```

Inside `StudentsPage`, add the sync after the existing `useQuery`:

```typescript
export default function StudentsPage() {
  const { data, isLoading, error } = useQuery({
    queryKey: ["students"],
    queryFn: studentsApi.list,
  });

  // Keep has_subscription_student flag accurate after staff enrollment changes.
  const updateParent = useAuthStore((s) => s.updateParent);
  const parent = useAuthStore((s) => s.parent);

  useEffect(() => {
    const students = data?.data;
    if (!students || !parent) return;
    const hasSubscription = students.some((s) => s.student_type === "subscription");
    if (parent.has_subscription_student !== hasSubscription) {
      updateParent({ ...parent, has_subscription_student: hasSubscription });
    }
  }, [data, parent, updateParent]);

  return (
    // ... existing JSX unchanged
  );
}
```

- [ ] **Step 2: Update `app/(portal)/students/[id]/page.tsx`**

The students list query in this file is:
```typescript
const { data: studentsData, isLoading: studentsLoading } = useQuery({
  queryKey: ["students"],
  queryFn: studentsApi.list,
});
```

Add two imports at the top of the file (after existing imports):
```typescript
import { useEffect } from "react"; // may already be imported — add only if missing
import { useAuthStore } from "@/lib/store/auth";
```

Inside the page component, immediately after the `studentsData` query, add:

```typescript
// Keep has_subscription_student flag accurate after staff enrollment changes.
const updateParent = useAuthStore((s) => s.updateParent);
const parent = useAuthStore((s) => s.parent);

useEffect(() => {
  const students = studentsData?.data;
  if (!students || !parent) return;
  const hasSubscription = students.some((s) => s.student_type === "subscription");
  if (parent.has_subscription_student !== hasSubscription) {
    updateParent({ ...parent, has_subscription_student: hasSubscription });
  }
}, [studentsData, parent, updateParent]);
```

- [ ] **Step 3: Update `app/(portal)/feedback/page.tsx`**

The students list query in this file is inside the `FeedbackForm` sub-component:
```typescript
const { data: studentsData } = useQuery({
  queryKey: ["students"],
  queryFn: studentsApi.list,
});
```

Add two imports at the top of the file:
```typescript
import { useEffect } from "react"; // may already be imported — add only if missing
import { useAuthStore } from "@/lib/store/auth";
```

Inside `FeedbackForm`, immediately after the `studentsData` query, add:

```typescript
// Keep has_subscription_student flag accurate after staff enrollment changes.
const updateParent = useAuthStore((s) => s.updateParent);
const parent = useAuthStore((s) => s.parent);

useEffect(() => {
  const students = studentsData?.data;
  if (!students || !parent) return;
  const hasSubscription = students.some((s) => s.student_type === "subscription");
  if (parent.has_subscription_student !== hasSubscription) {
    updateParent({ ...parent, has_subscription_student: hasSubscription });
  }
}, [studentsData, parent, updateParent]);
```

- [ ] **Step 4: Verify TypeScript compiles cleanly**

```bash
cd ~/sunbites-portal && npx tsc --noEmit
```

Expected: No errors.

- [ ] **Step 5: Run the full portal test suite to check for regressions**

```bash
cd ~/sunbites-portal && npx jest --no-coverage --passWithNoTests
```

Expected: All tests pass.

- [ ] **Step 6: Commit**

```bash
cd ~/sunbites-portal && git add "app/(portal)/students/page.tsx" \
                                "app/(portal)/students/[id]/page.tsx" \
                                "app/(portal)/feedback/page.tsx"
git commit -m "feat(portal): sync subscription flag from live student data on page load"
```

---

## Task 8: Final verification

- [ ] **Step 1: Run the full backend test suite**

```bash
vendor/bin/sail artisan test --compact
```

Expected: All tests pass, no regressions.

- [ ] **Step 2: Run the full frontend test suite**

```bash
cd ~/sunbites-portal && npx jest --no-coverage
```

Expected: All tests pass.

- [ ] **Step 3: TypeScript check**

```bash
cd ~/sunbites-portal && npx tsc --noEmit
```

Expected: No errors.
