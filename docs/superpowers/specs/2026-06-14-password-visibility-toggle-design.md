# Password Visibility Toggle — Design Spec

**Date:** 2026-06-14
**Scope:** `~/sunbites-portal` and `~/sunbites-pos` (Next.js frontend apps)
**Status:** Approved

---

## Problem

All password inputs across both apps (`type="password"`) give users no way to verify what they are typing. There are 11 password fields across 6 forms with no visibility toggle.

---

## Goal

Every `<Input type="password" />` automatically renders with an Eye / EyeOff lucide-react icon button that toggles the field between masked and plain-text mode. No changes to any form file.

---

## Approach

Enhance the existing shared `Input` component (`components/ui/input.tsx`) in each app. When `type === "password"` is detected, the component manages its own visibility state internally and renders a toggle button. All other input types are unaffected.

This was chosen over a dedicated `PasswordInput` component to achieve zero call-site changes — all 11 existing `<Input type="password" />` usages get the toggle automatically.

---

## Affected Files

| File | Change |
|------|--------|
| `~/sunbites-portal/components/ui/input.tsx` | Add password toggle behavior |
| `~/sunbites-pos/components/ui/input.tsx` | Identical change |

No form files are touched.

---

## Password Fields Covered

### Portal (`~/sunbites-portal`)

| Form | File | Fields |
|------|------|--------|
| Login | `app/(auth)/login/page.tsx` | password |
| Account activation | `app/(auth)/activate/activate-form.tsx` | new password, confirm password |
| Change password | `app/(portal)/profile/page.tsx` | current password, new password, confirm new password |

### POS / Admin (`~/sunbites-pos`)

| Form | File | Fields |
|------|------|--------|
| Login | `app/(auth)/login/page.tsx` | password |
| Reset password | `app/(auth)/reset-password/page.tsx` | new password, confirm new password |
| Create staff user | `app/(kitchen)/references/users/create/page.tsx` | password, confirm password |

---

## Component Design

### Rendering

When `type === "password"`, the `Input` component renders:

```
<div class="relative">
  <InputPrimitive type={shown ? "text" : "password"} class="... pr-8" />
  <button type="button" aria-label="Show/Hide password" class="absolute inset-y-0 right-0 ...">
    <Eye /> or <EyeOff />
  </button>
</div>
```

When `type` is anything else, the component renders exactly as before — no wrapper div, no button.

### State

- `const [shown, setShown] = React.useState(false)` — local to the component instance.
- Defaults to hidden (masked). Each field manages its own state independently.

### Styling

- **Input right padding**: `pr-8` added only for password fields, so typed text does not slide under the button.
- **Button position**: `absolute inset-y-0 right-0`, vertically centered, `pr-2.5` inner padding to match the input's horizontal rhythm.
- **Icon size**: `size-4` (16 px).
- **Icon color**: `text-muted-foreground` at rest → `text-foreground` on hover.
- **Button appearance**: No background, no border — blends visually into the input.

### Accessibility

- `type="button"` on the toggle prevents accidental form submission.
- `aria-label` is `"Show password"` when masked, `"Hide password"` when visible — describes the next action.
- Clicking the toggle does not move keyboard focus away from the input field.

---

## What Does Not Change

- The `Input` component's external API is unchanged — no new props, no breaking changes.
- All existing usages outside of password fields are unaffected.
- No dependencies are added; `lucide-react` is already installed in both apps.
- No backend changes required.

---

## Out of Scope

- A `showPasswordToggle` escape-hatch prop (can be added later if a use case arises).
- Any changes to password validation logic or rules.
- Any changes to the Laravel API.
