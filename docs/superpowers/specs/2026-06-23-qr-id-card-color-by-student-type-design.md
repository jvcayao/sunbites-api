# QR ID Card Color Differentiation by Student Type

**Date:** 2026-06-23
**Project:** sunbites-pos
**Status:** Approved

---

## Overview

Student ID cards printed from the POS app must visually distinguish subscription students from wallet-only (non-subscription) students by using a different header color on the printed card. Colors are sourced from the official Sunbites brand palette scraped from sunbites.com.ph.

---

## Goals

- Subscription students → red header (`#e5322a`, sb-red-500)
- Non-subscription / wallet students → yellow header (`#f4b400`, sb-yellow-400)
- Color differentiation applies to all three print locations in the POS app
- No layout, dimension, or font changes — color swaps only

---

## Brand Colors (from sunbites.com.ph CSS)

### Red scale (subscription)
| Token | Hex |
|---|---|
| sb-red-50 | `#fff1f0` |
| sb-red-100 | `#ffd9d6` |
| sb-red-500 | `#e5322a` ← header background |

### Yellow scale (non-subscription)
| Token | Hex |
|---|---|
| sb-yellow-50 | `#fffbeb` |
| sb-yellow-100 | `#fff1b8` |
| sb-yellow-400 | `#f4b400` ← header background |
| sb-yellow-500 | `#d69400` ← accent on white backgrounds |

### Ink (text)
| Token | Hex |
|---|---|
| sb-ink-900 | `#1a1611` ← dark text on yellow header |

---

## Color Utility

**New file:** `sunbites-pos/lib/utils/card-accent-colors.ts`

Returns a typed color map given a `StudentType`. The utility is the single source of truth — all three print locations import from it.

```ts
export type CardAccentColors = {
  headerBg: string
  headerText: string
  headerSubText: string
  accentColor: string
  avatarBg: string
  borderColor: string
  footerBg: string
  footerBorder: string
}

export function getCardAccentColors(studentType: StudentType): CardAccentColors
```

**Subscription output:**
```ts
{
  headerBg:      "#e5322a",
  headerText:    "#ffffff",
  headerSubText: "rgba(255,255,255,0.85)",
  accentColor:   "#e5322a",
  avatarBg:      "#fff1f0",
  borderColor:   "#e5322a",
  footerBg:      "#fff1f0",
  footerBorder:  "#ffd9d6",
}
```

**Non-subscription output:**
```ts
{
  headerBg:      "#f4b400",
  headerText:    "#1a1611",
  headerSubText: "rgba(26,22,17,0.75)",
  accentColor:   "#d69400",
  avatarBg:      "#fffbeb",
  borderColor:   "#f4b400",
  footerBg:      "#fffbeb",
  footerBorder:  "#fff1b8",
}
```

**Contrast note:** White text on `#f4b400` fails WCAG at ~2:1. Dark ink (`#1a1611`) achieves ~7:1 contrast on yellow, so non-subscription header text is dark, not white.

---

## Files Changed

All changes are in `sunbites-pos`.

| File | Change |
|---|---|
| `lib/utils/card-accent-colors.ts` | **New** — color utility |
| `app/(kitchen)/students/page.tsx` | Update `PrintCard` component — replace all `oklch(0.577 0.245 27.325)` and hardcoded footer colors with values from utility |
| `app/(kitchen)/students/[id]/page.tsx` | Update inline print card (lines ~2282–2450) — same replacement |
| `app/(kitchen)/enrollment/page.tsx` | Update post-enrollment QR print section — same replacement |

---

## Card Elements Affected

The current card uses `oklch(0.577 0.245 27.325)` in five places. Each maps to a color key:

| Card element | Color key used |
|---|---|
| Card outer border | `borderColor` |
| Header background | `headerBg` |
| Header title text | `headerText` |
| Header subtitle text | `headerSubText` |
| Photo border | `accentColor` |
| Avatar background (no photo) | `avatarBg` |
| Avatar initial letter | `accentColor` |
| Grade level text | `accentColor` |
| Footer background | `footerBg` |
| Footer border | `footerBorder` |

No structural changes to layout, dimensions (53.98mm × 85.6mm ID-1), or print CSS.

---

## Print Locations in Scope

All three use `student.student_type: StudentType` which is already available in the component data.

1. **Batch print** — `BatchQrModal` → `PrintCard` in `app/(kitchen)/students/page.tsx`
2. **Individual student page** — inline print card in `app/(kitchen)/students/[id]/page.tsx`
3. **Post-enrollment** — QR print section in `app/(kitchen)/enrollment/page.tsx`

---

## Testing

### Unit test — `getCardAccentColors`
- Subscription input returns `headerBg: "#e5322a"` and `headerText: "#ffffff"`
- Non-subscription input returns `headerBg: "#f4b400"` and `headerText: "#1a1611"`

### Render test — `PrintCard`
- Renders with a subscription student → assert header element has `backgroundColor: "#e5322a"`
- Renders with a non-subscription student → assert header element has `backgroundColor: "#f4b400"`

Existing tests in `students/student-list.test.tsx` must continue to pass.

---

## Out of Scope

- No backend changes — `student_type` is already exposed in `StudentResource`
- No layout or dimension changes
- No changes to the `QrCard` (web display) component — print only
- No changes to the post-enrollment QR-only print (the simple `window.print()` after enrollment that shows just the QR code without the full ID card layout)
