# Meal Plan Subscription Gate — Design Spec

**Date:** 2026-06-24
**Branch:** `feat/new-updates-for-july`
**Scope:** Sunbites Parent Portal (`~/sunbites-portal`) + Sunbites API (`~/sunbites-api`)

---

## Problem

The weekly meal plan in the parent portal is currently accessible to all authenticated parents regardless of their students' enrollment type. The meal plan is a subscription-only feature and should only be visible to parents who have at least one subscription student.

---

## Requirements

- Parents with **only non-subscription students** must not be able to see or access the meal plan.
- Parents with **at least one subscription student** (including mixed households) can see and access the meal plan.
- Access is enforced at both the **frontend** (nav hidden, route redirects) and **backend** (API returns 403).
- The access flag must stay **accurate without requiring logout/login** — it updates reactively when student data is refreshed.
- The portal logo (circle "S") must be replaced with the actual `icon.png` image across both `variant="icon"` and `variant="full"` of `AppLogo`.

---

## Out of Scope

- Changing the per-week `visible_to_parents` visibility toggle (staff-controlled, unchanged).
- Any changes to the meal plan content or structure.
- Pre-registration workflow (parents don't have portal accounts until a pre-registration is approved; by login time, student type is already known).

---

## Approach

Add a `has_subscription_student: boolean` flag to the parent auth response. Store it in the Zustand auth store alongside the existing `parent: AuthParent` object. The portal layout reads it to show/hide the Meal Plan nav link. The meal plan page reads it to redirect unauthorized access. The backend enforces the same rule at the API layer.

The flag is kept fresh via a **reactive sync**: whenever `GET /portal/students` resolves in any page that calls it, the portal derives the flag from live student data and updates the Zustand store — covering the case where staff links a new subscription student while the parent is already logged in.

---

## Backend Changes (`~/sunbites-api`)

### 1. `AuthController` — Login response

**File:** `app/Http/Controllers/Portal/AuthController.php`

Add `has_subscription_student` to the parent array in the `login` method. Import `App\Enums\StudentType` at the top of the file:

```php
use App\Enums\StudentType;

// Inside login(), update the return statement:
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
```

### 2. `ProfileController` — Profile response

**File:** `app/Http/Controllers/Portal/ProfileController.php`

The controller uses a private `parentData(ParentUser $parent): array` helper that is shared by both `show` and `update`. Add the field there so both responses include it automatically. Import `App\Enums\StudentType`:

```php
use App\Enums\StudentType;

// Inside parentData():
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

### 3. `MealPlannerController` — Subscription guard

**File:** `app/Http/Controllers/Portal/MealPlannerController.php`

The method is named `show` (not `index`). Add the subscription check at the very top of `show`, before the branch and visibility logic. Import `App\Enums\StudentType`:

```php
use App\Enums\StudentType;

public function show(Request $request): JsonResponse
{
    $validated = $request->validate([...]);

    $parent = $request->user();

    // Guard: meal plan is subscription-only
    $hasSubscription = $parent->students()
        ->where('student_type', StudentType::Subscription)
        ->exists();

    if (! $hasSubscription) {
        abort(403, 'Meal plan access requires a subscription student.');
    }

    // ... rest of existing branch + visibility logic unchanged
}
```

---

## Frontend Changes (`~/sunbites-portal`)

### 1. `AuthParent` type

**File:** `types/auth.ts` _(not `types/portal.ts` — `AuthParent` lives here)_

Add the new field:

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
  has_subscription_student: boolean; // new
}
```

### 2. Zustand auth store — add `updateParent` action

**File:** `lib/store/auth.ts`

The store currently has `login` and `logout` only. Add an `updateParent` action for the reactive sync:

```typescript
interface AuthState {
  token: string | null;
  parent: AuthParent | null;
  login: (token: string, parent: AuthParent) => void;
  logout: () => void;
  updateParent: (parent: AuthParent) => void; // new
}

export const useAuthStore = create<AuthState>()(
  persist(
    (set) => ({
      token: null,
      parent: null,
      login: (token, parent) => set({ token, parent }),
      logout: () => set({ token: null, parent: null }),
      updateParent: (parent) => set({ parent }),  // new
    }),
    {
      name: "portal-auth",
      storage: createJSONStorage(() => sessionStorage),
    },
  ),
);
```

### 3. `portal-layout.tsx` — Conditional Meal Plan nav link

**File:** `components/layouts/portal-layout.tsx`

Read the flag from the auth store and render the Meal Plan nav link only when `true`:

```typescript
const hasSubscriptionStudent = useAuthStore(s => s.parent?.has_subscription_student ?? false);

// Desktop nav — replace the unconditional Meal Plan link
{hasSubscriptionStudent && (
  <NavLink href="/meal-plan">Meal Plan</NavLink>
)}

// Mobile nav — same
{hasSubscriptionStudent && (
  <MobileNavLink href="/meal-plan">Meal Plan</MobileNavLink>
)}
```

No loading state needed — the flag is in Zustand from the moment the parent logs in.

### 4. `meal-plan/page.tsx` — Route guard

**File:** `app/(portal)/meal-plan/page.tsx`

The page is already `"use client"`. Add a guard using `useRouter` inside `useEffect` — `redirect()` cannot be called during Client Component render:

```typescript
const router = useRouter();
const hasSubscriptionStudent = useAuthStore(s => s.parent?.has_subscription_student ?? false);

useEffect(() => {
  if (!hasSubscriptionStudent) {
    router.replace('/dashboard');
  }
}, [hasSubscriptionStudent, router]);

if (!hasSubscriptionStudent) return null;
```

The `return null` prevents the meal plan content from flashing before the redirect fires.

### 5. `app-logo.tsx` — Replace circle "S" with `icon.png`

**File:** `components/app-logo.tsx`

Replace both the `icon` and `full` variant circle divs with a Next.js `Image` component pointing to `/icon.png` (served from `public/icon.png`). The `rounded-full` class preserves the circular crop:

```typescript
import Image from "next/image";

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
      <Image src="/icon.png" alt="Sunbites" width={40} height={40} className="rounded-full" />
      <div className="flex flex-col">
        <span className="text-base font-bold leading-tight text-foreground">Sunbites</span>
        <span className="text-xs font-medium leading-tight text-muted-foreground">Your Healthy Kitchen</span>
      </div>
    </div>
  );
}
```

### 6. Reactive flag sync — in pages that call `studentsApi.list`

There is no shared `hooks/` directory. The `studentsApi.list` call (`GET /portal/students`) exists in three pages:
- `app/(portal)/students/page.tsx`
- `app/(portal)/students/[id]/page.tsx`
- `app/(portal)/feedback/page.tsx`

In each of these pages, after the students query resolves, add an effect to sync the flag:

```typescript
const { updateParent } = useAuthStore();
const parent = useAuthStore(s => s.parent);

useEffect(() => {
  if (!students || !parent) return;
  const hasSubscription = students.some(s => s.student_type === 'subscription');
  if (parent.has_subscription_student !== hasSubscription) {
    updateParent({ ...parent, has_subscription_student: hasSubscription });
  }
}, [students, parent, updateParent]);
```

This covers the case where staff links a new subscription student while the parent is logged in — the next visit to any of these pages silently corrects the flag, and the nav reacts immediately.

---

## Access Matrix

| Parent's students | `has_subscription_student` | Nav shows Meal Plan | API allows access |
|---|---|---|---|
| All non-subscription | `false` | No | No (403) |
| All subscription | `true` | Yes | Yes |
| Mixed (at least one subscription) | `true` | Yes | Yes |

---

## Data Flow

```
Login
  └── POST /portal/auth/login
        └── returns parent + has_subscription_student
              └── stored in Zustand auth store
                    ├── portal-layout: show/hide Meal Plan nav
                    └── meal-plan/page: redirect if false

While logged in
  └── GET /portal/students (on Students, Student Detail, or Feedback page)
        └── useEffect derives hasSubscription from live data
              └── updateParent() updates Zustand store if flag changed
                    └── nav + route guard react immediately
```

---

## Tests

### Backend

New test file: `tests/Feature/Portal/MealPlannerAccessTest.php`
Existing login tests in: `tests/Feature/Portal/AuthTest.php` (or equivalent)

| Test | Assertion |
|---|---|
| Parent with only non-subscription students calls `GET /portal/meal-planner` | `403` |
| Parent with only subscription students calls `GET /portal/meal-planner` | `200` |
| Parent with mixed students calls `GET /portal/meal-planner` | `200` |
| Login returns `has_subscription_student: true` for subscription parent | Response JSON contains `parent.has_subscription_student = true` |
| Login returns `has_subscription_student: false` for non-subscription parent | Response JSON contains `parent.has_subscription_student = false` |
| `GET /portal/profile` returns correct `has_subscription_student` value | Response JSON contains correct flag |
| `PATCH /portal/profile` also returns correct `has_subscription_student` value | Response JSON contains correct flag (via shared `parentData()`) |

### Frontend

| Test | File | Assertion |
|---|---|---|
| Layout hides Meal Plan nav when `has_subscription_student: false` | `portal-layout.test.tsx` | Nav link absent |
| Layout shows Meal Plan nav when `has_subscription_student: true` | `portal-layout.test.tsx` | Nav link present |
| Layout shows Meal Plan nav for mixed household | `portal-layout.test.tsx` | Nav link present |
| `/meal-plan` with `has_subscription_student: false` redirects | `meal-plan/page.test.tsx` | `router.replace('/dashboard')` called |
| `/meal-plan` with `has_subscription_student: true` renders content | `meal-plan/page.test.tsx` | Meal plan UI visible |
| Students query resolves → store flag updates | `students/page.test.tsx` | `updateParent` called with corrected flag |

---

## Edge Cases

| Scenario | Handled by |
|---|---|
| Parent logs in, staff later adds a subscription student | Reactive sync via `updateParent` on next students page visit |
| Parent bookmarks `/meal-plan` before losing subscription access | Route guard `useEffect` → `router.replace('/dashboard')` |
| Pre-registered student (pending approval) | Not applicable — no portal account until approval; type set at that point |
| Mixed household (subscription + non-subscription kids) | Flag is `true`; parent sees Meal Plan; non-subscription kid's data unaffected |
| API called directly (Postman, curl) | Backend 403 gate enforces restriction regardless of frontend |
| `PATCH /portal/profile` response | Flag included via shared `parentData()` helper — no extra work needed |
