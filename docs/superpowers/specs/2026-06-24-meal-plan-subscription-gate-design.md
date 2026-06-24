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

Add a `has_subscription_student: bool` flag to the parent auth response. Store it in the Zustand auth store (alongside the existing `parent` object). The portal layout reads it to show/hide the Meal Plan nav link. The meal plan page reads it to redirect unauthorized access. The backend enforces the same rule at the API layer.

The flag is kept fresh via a **reactive sync**: whenever `GET /portal/students` resolves, the portal derives the flag from live student data and updates the Zustand store — covering the case where staff links a new subscription student while the parent is already logged in.

---

## Backend Changes (`~/sunbites-api`)

### 1. `AuthController` — Login response

**File:** `app/Http/Controllers/Portal/AuthController.php`

Add `has_subscription_student` to the parent object returned on login:

```php
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

Add the same `has_subscription_student` field to the `show` response so the flag remains accurate when the portal refreshes the profile.

### 3. `MealPlannerController` — Subscription guard

**File:** `app/Http/Controllers/Portal/MealPlannerController.php`

Add a subscription check at the start of the `index` method, before any branch or visibility logic:

```php
$hasSubscription = $parent->students()
    ->where('student_type', StudentType::Subscription)
    ->exists();

if (! $hasSubscription) {
    abort(403, 'Meal plan access requires a subscription student.');
}
```

---

## Frontend Changes (`~/sunbites-portal`)

### 1. `AuthParent` type

**File:** `types/portal.ts`

Add the new field to the `AuthParent` interface:

```typescript
export interface AuthParent {
  id: number;
  first_name: string;
  last_name: string;
  email: string;
  phone: string | null;
  address: string | null;
  profile_photo_url: string | null;
  has_subscription_student: boolean; // new
}
```

No Zustand store shape change is required — the flag rides along as part of the existing `parent: AuthParent | null` field.

### 2. `portal-layout.tsx` — Conditional Meal Plan nav link

**File:** `components/layouts/portal-layout.tsx`

Read the flag from the auth store and render the Meal Plan nav link only when `true`:

```typescript
const hasSubscriptionStudent = useAuthStore(s => s.parent?.has_subscription_student ?? false);

// Desktop nav
{hasSubscriptionStudent && (
  <NavLink href="/meal-plan">Meal Plan</NavLink>
)}

// Mobile nav
{hasSubscriptionStudent && (
  <MobileNavLink href="/meal-plan">Meal Plan</MobileNavLink>
)}
```

No loading state is needed — the flag is available in Zustand from the moment the parent logs in.

### 3. `meal-plan/page.tsx` — Route guard

**File:** `app/(portal)/meal-plan/page.tsx`

The meal plan page must be a Client Component (`"use client"`). Use `useRouter` inside a `useEffect` for the redirect — `redirect()` from `next/navigation` cannot be called during Client Component render:

```typescript
"use client";

const router = useRouter();
const hasSubscriptionStudent = useAuthStore(s => s.parent?.has_subscription_student ?? false);

useEffect(() => {
  if (!hasSubscriptionStudent) {
    router.replace('/dashboard');
  }
}, [hasSubscriptionStudent, router]);

if (!hasSubscriptionStudent) return null;
```

The `return null` prevents the page content from flashing before the redirect fires. This handles direct URL access — a parent who bookmarked `/meal-plan` before their last subscription student was unenrolled will be silently redirected to the dashboard.

### 4. `app-logo.tsx` — Replace circle "S" with `icon.png`

**File:** `components/app-logo.tsx`

Replace both the `icon` and `full` variant circle divs with a Next.js `Image` component pointing to `/icon.png` (served from `public/icon.png`):

```typescript
import Image from "next/image";

// icon variant
<Image src="/icon.png" alt="Sunbites" width={40} height={40} className={cn("rounded-full", className)} />

// full variant
<div className={cn("flex items-center gap-3", className)}>
  <Image src="/icon.png" alt="Sunbites" width={40} height={40} className="rounded-full" />
  <div className="flex flex-col">
    <span className="text-base font-bold leading-tight text-foreground">Sunbites</span>
    <span className="text-xs font-medium leading-tight text-muted-foreground">Your Healthy Kitchen</span>
  </div>
</div>
```

The `rounded-full` class preserves the circular crop if `icon.png` is square.

### 5. Reactive flag sync in students hook

**File:** `hooks/use-students.ts` (or wherever `GET /portal/students` is called)

After the students query resolves, derive the subscription flag and update the Zustand store:

```typescript
useEffect(() => {
  if (!students) return;
  const hasSubscription = students.some(s => s.student_type === 'subscription');
  const parent = useAuthStore.getState().parent;
  if (parent && parent.has_subscription_student !== hasSubscription) {
    useAuthStore.getState().setParent({ ...parent, has_subscription_student: hasSubscription });
  }
}, [students]);
```

This keeps the flag accurate when staff links a new subscription student while the parent is already logged in. The nav and route guard react immediately once the students query next runs (no logout required).

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
  └── GET /portal/students (TanStack Query)
        └── derives hasSubscription from live data
              └── updates Zustand store if flag changed
                    └── nav + route guard react immediately
```

---

## Tests

### Backend — `tests/Feature/Portal/MealPlannerTest.php`

| Test | Assertion |
|---|---|
| Parent with only non-subscription students gets `GET /portal/meal-planner` | `403` |
| Parent with only subscription students gets `GET /portal/meal-planner` | `200` |
| Parent with mixed students gets `GET /portal/meal-planner` | `200` |
| Login returns `has_subscription_student: true` for subscription parent | Response JSON has flag `true` |
| Login returns `has_subscription_student: false` for non-subscription parent | Response JSON has flag `false` |
| `GET /portal/profile` returns correct `has_subscription_student` value | Response JSON has correct flag |

### Frontend — `components/layouts/portal-layout.test.tsx` + `app/(portal)/meal-plan/page.test.tsx`

| Test | Assertion |
|---|---|
| Layout hides Meal Plan nav when `has_subscription_student: false` | Nav link not in document |
| Layout shows Meal Plan nav when `has_subscription_student: true` | Nav link in document |
| Layout shows Meal Plan nav for parent with mixed students | Nav link in document |
| `/meal-plan` with `has_subscription_student: false` | Redirects to `/dashboard` |
| `/meal-plan` with `has_subscription_student: true` | Renders meal plan content |
| Students query resolves with subscription student → store flag updates | `has_subscription_student` becomes `true` in store |

---

## Edge Cases

| Scenario | Handled by |
|---|---|
| Parent logs in, staff later adds a subscription student | Reactive sync on next `GET /portal/students` |
| Parent bookmarks `/meal-plan` before losing subscription access | Route guard redirects to `/dashboard` |
| Pre-registered student (pending approval) | Not applicable — parents have no account until approval; student type is set at approval time |
| Mixed household (subscription + non-subscription kids) | Flag is `true`; parent sees Meal Plan; non-subscription kid's data is unaffected |
| API called directly without portal (e.g., Postman) | Backend 403 gate enforces restriction |
