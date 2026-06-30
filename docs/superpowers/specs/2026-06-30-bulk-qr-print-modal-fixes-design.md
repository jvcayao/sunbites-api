# Bulk QR Print Modal Fixes

**Date:** 2026-06-30
**Scope:** `~/sunbites-pos` — `app/(kitchen)/students/page.tsx` only
**Type:** Bug fixes + minor feature addition

---

## Summary

Three issues in the `BatchQrModal` / `QrCard` components of the students page:

1. Modal dialog too narrow for multiple columns — cards overflow or overlap in the preview
2. Non-subscription student cards render with red colors in the preview instead of yellow
3. Cards-per-row only has options 1–3; option 4 is missing and the default is 2 instead of 4

---

## Bug 1 — Modal Width Does Not Adapt to Cards-Per-Row

### Problem

`BatchQrModal` uses a fixed `sm:max-w-2xl` (672px) dialog width. With 4 preview cards at 152px each plus 3 gaps at 12px, the minimum preview width is 644px. After adding dialog horizontal padding (~32px), the total exceeds 672px, causing the preview grid to overflow or cards to overlap.

### Fix

Compute the dialog's `max-width` class from the current `cols` value:

| `cols` | `DialogContent` class |
|--------|----------------------|
| 1 | `sm:max-w-sm` |
| 2 | `sm:max-w-xl` |
| 3 | `sm:max-w-2xl` |
| 4 | `sm:max-w-4xl` |

Wrap the preview grid in an `overflow-y-auto max-h-[60vh]` container so that many students do not push the dialog off-screen vertically.

```tsx
const dialogWidthClass = {
  1: "sm:max-w-sm",
  2: "sm:max-w-xl",
  3: "sm:max-w-2xl",
  4: "sm:max-w-4xl",
}[cols];

<DialogContent className={dialogWidthClass}>
  ...
  <div className="overflow-y-auto max-h-[60vh]">
    {/* preview grid */}
  </div>
```

---

## Bug 2 — QrCard (Preview) Ignores Student Type Colors

### Problem

`QrCard` (lines 617–811) is the screen-preview card rendered inside the dialog. It hardcodes `oklch(0.577 0.245 27.325)` (red) for every student, regardless of `student.student_type`. The actual print output (`PrintCard`) already calls `getCardAccentColors(student.student_type)` correctly — the preview is simply missing the same call.

### Fix

Call `getCardAccentColors(student.student_type)` at the top of `QrCard` and replace every hardcoded color with the correct token. Complete map of all 11 occurrences:

| Line | Hardcoded value | Token | Note |
|------|----------------|-------|------|
| 651 | `"2px solid oklch(0.577 0.245 27.325)"` | `colors.borderColor` | outer card border |
| 662 | `backgroundColor: "oklch(0.577 0.245 27.325)"` | `colors.headerBg` | header background |
| 667 | `color: "white"` | `colors.headerText` | non-sub uses `#1a1611` (dark), not white |
| 679 | `color: "rgba(255,255,255,0.85)"` | `colors.headerSubText` | non-sub uses `rgba(26,22,17,0.75)` |
| 709 | `"2px solid oklch(0.577 0.245 27.325)"` | `colors.borderColor` | photo border |
| 719 | `"2px solid oklch(0.577 0.245 27.325)"` | `colors.borderColor` | avatar border (no-photo) |
| 720 | `backgroundColor: "#fff3f0"` | `colors.avatarBg` | avatar bg (no-photo) |
| 726 | `color: "oklch(0.577 0.245 27.325)"` | `colors.accentColor` | avatar initial text — non-sub uses `#d69400` |
| 745 | `color: "oklch(0.577 0.245 27.325)"` | `colors.accentColor` | grade level text |
| 790 | `backgroundColor: "#fff3f0"` | `colors.footerBg` | footer background |
| 791 | `borderTop: "1px solid #fdd8cc"` | `colors.footerBorder` | footer border |

> `borderColor` and `accentColor` differ for non-subscription (`#f4b400` vs `#d69400`). Do not interchange them.

Neutral values that must NOT change: QR box border `#e0e0e0`, QR text `#888`, body text `#555`/`#444`/`#666` — these are type-neutral styling.

```tsx
function QrCard({ student }: { student: Student }) {
  const colors = getCardAccentColors(student.student_type);
  // use colors.* per the table above
```

---

## Feature Addition — Cards-Per-Row: Add 4, Reorder, Change Default

### Change

- State type: `1 | 2 | 3` → `1 | 2 | 3 | 4`
- Default: `2` → `4`
- Button render order: `[1, 2, 3]` → `[4, 3, 2, 1]` (descending)
- Preview grid: add `"grid-cols-4"` mapping for `cols === 4`

```tsx
const [cols, setCols] = useState<1 | 2 | 3 | 4>(4);

// Button row
{([4, 3, 2, 1] as const).map((n) => ( ... ))}

// Preview className
cols === 1 ? "grid-cols-1"
  : cols === 2 ? "grid-cols-2"
  : cols === 3 ? "grid-cols-3"
  : "grid-cols-4"
```

The print portal already uses `gridTemplateColumns: \`repeat(${cols}, 53.98mm)\`` — no change needed there since it handles any number dynamically.

---

## Files Changed

| File | Change |
|------|--------|
| `app/(kitchen)/students/page.tsx` | All three fixes — `QrCard`, `BatchQrModal` |
| `lib/utils/card-accent-colors.ts` | No change (already correct) |
| `app/(kitchen)/students/student-list.test.tsx` | Update/add tests for `cols=4` default and non-subscription preview color |

---

## Testing

- Existing test at line 258–293 verifies `PrintCard` header color by student type — add a parallel assertion for `QrCard` (the preview)
- Add test: `BatchQrModal` opens with `cols` defaulting to 4, and 4-column button is highlighted
- Verify non-subscription preview card does not use red header color
