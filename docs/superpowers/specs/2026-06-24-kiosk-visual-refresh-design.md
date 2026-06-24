# Kiosk Visual Refresh — Design Spec

**Date:** 2026-06-24
**Project:** sunbites-pos
**Status:** Approved
**File to modify:** `app/(kiosk)/kiosk/page.tsx`

---

## Overview

A visual and animation refresh of the kiosk balance check page (`/kiosk`). The goal is to make the page feel warm, branded, and joyful — especially for students who scan and see a healthy balance. The page stays on a light background but gains prominent Sunbites branding, lively scanning feedback, and a celebration or gentle warning tied to the student's balance level.

No backend changes. No new routes. One file changes: `page.tsx`. A new npm package (`canvas-confetti`) is added.

---

## Balance Tiers

All visual states (emoji, confetti, border glow, message) are driven by a single threshold check against `parseFloat(student.balance)`:

| Tier | Condition | Emoji | Confetti | Card border | Message |
|---|---|---|---|---|---|
| **Green** | ≥ ₱150 | — | ✅ fires | none | — |
| **Orange** | ₱80–₱149 | 😢 | ✗ no | `ring-2 ring-orange-400` + orange glow | "Your balance is running low. Please ask a cashier to top up soon!" |
| **Red** | ≤ ₱79 | 😰 | ✗ no | `ring-2 ring-red-400` + red glow | "Insufficient balance. Please top up before ordering." |

The tier replaces the previous three-tier color logic (`≥50 green`, `>0 orange`, `≤0 red`). The new balance text color follows the same tier: `text-green-600`, `text-orange-500`, `text-red-600`.

---

## Layout — All States

The Sunbites logo is always visible at the top of the screen, regardless of state. It uses `AppLogo` with `variant="full"` and is positioned fixed at the top-center with comfortable vertical padding.

```
┌────────────────────────────────────┐
│     🔴 Sunbites                    │  ← AppLogo (always visible, top-center)
│        Your Healthy Kitchen        │
│                                    │
│       [state content here]         │
│                                    │
└────────────────────────────────────┘
```

Background: `bg-background` (white). No color change to the page background itself.

---

## Scanning State

- Logo at top
- Centered scan guide: a `256×256` rounded rectangle with corner bracket accents (CSS `before`/`after` on corner divs — four `<div>` elements positioned at corners with `border-t border-l`, etc.)
- Inside the guide: a red horizontal bar that sweeps top → bottom → top on a 2-second CSS keyframe loop (`@keyframes scanLine`)
- Guide border: subtle `pulse` animation on opacity
- Prompt text: "Scan your ID card" — gentle `animate-pulse` breathing effect (Tailwind utility)

**CSS keyframe to add in `globals.css` (or inline via `<style>`):**
```css
@keyframes scanLine {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(220px); }
}
```

Camera viewfinder `<video>` remains mounted and fills the screen (unchanged).

---

## Result State — Animations

Three things fire simultaneously when `state === "result"`:

### 1. Confetti burst (green tier only)
- Package: `canvas-confetti` (npm, ~4 KB, no CDN)
- Install: `npm install canvas-confetti` + `npm install -D @types/canvas-confetti`
- Fire in a `useEffect` when `state === "result"` and `balanceNum >= 150`
- Call: `confetti({ particleCount: 120, spread: 80, origin: { y: 0.3 } })`
- Fires once per result display (effect dep: `[state, balanceNum]`)

### 2. Card spring pop-in
- Tailwind: `animate-in zoom-in-75 fade-in duration-300`
- Custom bounce easing via CSS variable override or inline `style={{ animationTimingFunction: 'cubic-bezier(0.34, 1.56, 0.64, 1)' }}`

### 3. Balance count-up
- A `useCountUp(target, duration)` hook inside `page.tsx` (file-private, not exported)
- Uses `requestAnimationFrame` to animate from `0` to `target` over `duration` ms (default 900ms)
- Easing: `easeOut` (`t => 1 - Math.pow(1 - t, 3)`)
- Starts when `student` becomes non-null
- Displays as `₱{value.toFixed(2)}`
- The count-up value is only visual — the tier logic always uses `parseFloat(student.balance)` (the real value), never the animated count

```typescript
function useCountUp(target: number, duration = 900): number {
  const [value, setValue] = useState(0);

  useEffect(() => {
    if (target === 0) { setValue(0); return; }
    let start: number | null = null;
    const step = (ts: number) => {
      if (!start) start = ts;
      const progress = Math.min((ts - start) / duration, 1);
      const eased = 1 - Math.pow(1 - progress, 3);
      setValue(target * eased);
      if (progress < 1) requestAnimationFrame(step);
    };
    const raf = requestAnimationFrame(step);
    return () => cancelAnimationFrame(raf);
  }, [target, duration]);

  return value;
}
```

---

## Result Card Layout

```
┌──────────────────────────────────────────┐
│                                          │  ← card, max-w-sm, centered
│  Hi, [FirstName]! 👋                     │  ← friendly greeting, text-lg
│                                          │
│    ●●●   YC   ●●●                        │  ← initials avatar (bg-primary)
│    Yunix Cayao                           │  ← name, text-2xl bold
│    Grade 9                               │  ← grade, muted
│                                          │
│    [ Non-Subscription ]                  │  ← badge
│                                          │
│    😰  ₱79.00  (counting up)             │  ← emoji (orange/red tier only)
│                                          │
│    ⚠️  Insufficient balance.             │  ← tier message (orange/red only)
│       Please top up before ordering.    │
│                                          │
│    Last Orders                           │
│    Rice Meal, Water    Jun 23 · ₱55.00  │
│    Snack Pack          Jun 22 · ₱25.00  │
│                                          │
│    [ Scan another ]                      │
└──────────────────────────────────────────┘
```

Card border: `ring-2 ring-orange-400 shadow-orange-100` (orange tier) or `ring-2 ring-red-400 shadow-red-100` (red tier). Green tier: no ring, default `shadow-2xl`.

---

## Error State

- Card shakes horizontally using a CSS keyframe (`@keyframes shake`) when it appears
- Applied via `animate-[shake_0.4s_ease-in-out]`
- Keyframe:
  ```css
  @keyframes shake {
    0%, 100% { transform: translateX(0); }
    20% { transform: translateX(-8px); }
    40% { transform: translateX(8px); }
    60% { transform: translateX(-6px); }
    80% { transform: translateX(6px); }
  }
  ```
- Logo still visible at top. Error card: unchanged content ("QR not recognized. Please see a cashier.")

---

## Camera Blocked State

No change — logo visible at top, text message centered.

---

## New npm Packages

| Package | Purpose | Install |
|---|---|---|
| `canvas-confetti` | Confetti animation | `npm install canvas-confetti` |
| `@types/canvas-confetti` | TypeScript types | `npm install -D @types/canvas-confetti` |

---

## Files Changed

| File | Change |
|---|---|
| `app/(kiosk)/kiosk/page.tsx` | Full visual refresh — logo, animations, tier logic, count-up hook, confetti |
| `app/globals.css` | Add `@keyframes scanLine` and `@keyframes shake` |
| `package.json` | Add `canvas-confetti` dependency |

---

## Testing

Existing 11 tests in `kiosk.test.tsx` remain passing. The balance tier thresholds change, so update the test fixtures:
- `balance: "245.00"` → green (≥ 150) ✅ no change needed (245 ≥ 150)
- `balance: "30.00"` → now **red** (≤ 79), not orange — update the orange test to use `"100.00"`
- `balance: "0.00"` → red (≤ 79) ✅ still red

Add three new tests for the emoji/message behavior:
- Green tier (balance ≥ 150): no tier message rendered
- Orange tier (80–149): renders 😢 and "running low" message
- Red tier (≤ 79): renders 😰 and "Insufficient balance" message

`canvas-confetti` is mocked in Jest (`jest.mock('canvas-confetti', () => jest.fn())`).
`requestAnimationFrame` is already available in jsdom.

---

## Out of Scope

- Sound effects
- Custom mascot/character illustrations
- Animated background patterns or gradients
- Per-branch logo customization
