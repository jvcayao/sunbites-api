# Bulk QR Print Modal Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Fix three bugs in the bulk QR print modal on the students page: dynamic dialog width, correct preview colors per student type, and add cols=4 as the new default.

**Architecture:** All changes are isolated to two files in `~/sunbites-pos` — the page component (`page.tsx`) and its co-located test file (`student-list.test.tsx`). No API, no new files, no new dependencies. TDD throughout: write the failing test first, then implement the minimum code to pass it.

**Tech Stack:** Next.js 15, React 19, TypeScript, Tailwind v4, shadcn/ui Dialog, Jest 30, React Testing Library, MSW 2

## Global Constraints

- All commands run from `~/sunbites-pos` (not from sunbites-api)
- Test runner: `npm test -- --testPathPattern="student-list" --watchAll=false`
- Do NOT change `lib/utils/card-accent-colors.ts` — it is already correct
- Do NOT change `PrintCard` — it already uses `getCardAccentColors` correctly
- Neutral colors `#e0e0e0`, `#888`, `#555`, `#444`, `#666` in `QrCard` must NOT change — they are type-neutral
- `borderColor` and `accentColor` differ for non-subscription (`#f4b400` vs `#d69400`) — do not interchange them
- Every task ends with a `pint`-style note: run `npm run lint` in sunbites-pos if it exists; otherwise skip

---

## File Map

| File | What changes |
|------|-------------|
| `app/(kitchen)/students/page.tsx` | `QrCard`: add `data-qr-preview-card`, call `getCardAccentColors`, replace 11 hardcoded colors. `BatchQrModal`: cols state `1\|2\|3\|4` default 4, buttons `[4,3,2,1]`, `grid-cols-4`, dynamic `dialogWidthClass`, scroll wrapper. |
| `app/(kitchen)/students/student-list.test.tsx` | Add three new tests: non-sub preview color, default cols=4 button state, dialog width class. |

---

## Task 1 — Fix QrCard Preview Colors

**Files:**
- Modify: `app/(kitchen)/students/page.tsx` (lines 617–811, `QrCard` component)
- Test: `app/(kitchen)/students/student-list.test.tsx`

**Interfaces:**
- Consumes: `getCardAccentColors(studentType: StudentType): CardAccentColors` from `@/lib/utils/card-accent-colors`
- Produces: `QrCard` root div gains `data-qr-preview-card` attribute for test querying

---

- [x] **Step 1.1: Write the failing test**

Add a new `describe` block at the bottom of `student-list.test.tsx` (after line 293):

```typescript
describe("QrCard preview colors in batch print modal", () => {
  it("renders a red header in preview for subscription students", async () => {
    const user = userEvent.setup();
    render(<StudentsPage />);
    await screen.findByText("Maria Santos");

    const checkboxes = screen.getAllByRole("checkbox");
    await user.click(checkboxes[0]);
    await user.click(screen.getByRole("button", { name: /print qr codes/i }));

    const previewCard = document.querySelector(
      "[data-qr-preview-card]",
    ) as HTMLElement;
    expect(previewCard).not.toBeNull();
    const header = previewCard.firstElementChild as HTMLElement;
    expect(header.style.backgroundColor).toBe("rgb(229, 50, 42)");
  });

  it("renders a yellow header in preview for non-subscription students", async () => {
    const user = userEvent.setup();
    render(<StudentsPage />);
    await screen.findByText("Carlo Mendoza");

    const tabs = screen.getAllByRole("button", { name: /non-subscription/i });
    const nonSubTab = tabs.find((t) =>
      t.textContent?.toLowerCase().includes("non-subscription ("),
    );
    if (nonSubTab) await user.click(nonSubTab);

    const checkboxes = screen.getAllByRole("checkbox");
    await user.click(checkboxes[0]);
    await user.click(screen.getByRole("button", { name: /print qr codes/i }));

    const previewCard = document.querySelector(
      "[data-qr-preview-card]",
    ) as HTMLElement;
    expect(previewCard).not.toBeNull();
    const header = previewCard.firstElementChild as HTMLElement;
    expect(header.style.backgroundColor).toBe("rgb(244, 180, 0)");
  });
});
```

- [x] **Step 1.2: Run tests to confirm they fail**

```bash
cd ~/sunbites-pos && npm test -- --testPathPattern="student-list" --watchAll=false
```

Expected: the two new tests fail with "previewCard is null" (no `data-qr-preview-card` attribute exists yet).

- [x] **Step 1.3: Add `data-qr-preview-card` to QrCard root div**

In `page.tsx`, locate the `QrCard` function return statement (line 643). Replace the root `<div` opening:

```tsx
// Before (line 644–656):
    <div
      style={{
        display: "flex",
        flexDirection: "column",
        width: "100%",
        height: "100%",
        borderRadius: "6px",
        border: "2px solid oklch(0.577 0.245 27.325)",
        overflow: "hidden",
        backgroundColor: "white",
        fontFamily: "sans-serif",
        textAlign: "center",
      }}
    >

// After:
    <div
      data-qr-preview-card
      style={{
        display: "flex",
        flexDirection: "column",
        width: "100%",
        height: "100%",
        borderRadius: "6px",
        border: "2px solid oklch(0.577 0.245 27.325)",
        overflow: "hidden",
        backgroundColor: "white",
        fontFamily: "sans-serif",
        textAlign: "center",
      }}
    >
```

- [x] **Step 1.4: Add `getCardAccentColors` call and replace all 11 hardcoded color values**

At the top of `QrCard`, add the colors call (after the `enrolledFormatted` lines, before the `return`):

```tsx
// Add this import at the top of the file if not already present:
import { getCardAccentColors } from "@/lib/utils/card-accent-colors";

// Inside QrCard, add after line 641 (enrolledFormatted):
  const colors = getCardAccentColors(student.student_type);
```

Then apply all 11 token substitutions (replace exactly these values, leaving neutral colors untouched):

| Location in JSX | Old value | New value |
|----------------|-----------|-----------|
| Root div `border` | `"2px solid oklch(0.577 0.245 27.325)"` | `` `2px solid ${colors.borderColor}` `` |
| Header div `backgroundColor` | `"oklch(0.577 0.245 27.325)"` | `colors.headerBg` |
| Header `<p>` (Sunbites Kitchen) `color` | `"white"` | `colors.headerText` |
| Header `<p>` (Student Canteen ID) `color` | `"rgba(255,255,255,0.85)"` | `colors.headerSubText` |
| Photo `<img>` `border` | `"2px solid oklch(0.577 0.245 27.325)"` | `` `2px solid ${colors.borderColor}` `` |
| Avatar initials div `border` | `"2px solid oklch(0.577 0.245 27.325)"` | `` `2px solid ${colors.borderColor}` `` |
| Avatar initials div `backgroundColor` | `"#fff3f0"` | `colors.avatarBg` |
| Avatar initials div `color` | `"oklch(0.577 0.245 27.325)"` | `colors.accentColor` |
| Grade level `<p>` `color` | `"oklch(0.577 0.245 27.325)"` | `colors.accentColor` |
| Footer div `backgroundColor` | `"#fff3f0"` | `colors.footerBg` |
| Footer div `borderTop` | `"1px solid #fdd8cc"` | `` `1px solid ${colors.footerBorder}` `` |

Do NOT change: `#e0e0e0` (QR box border), `#888` (QR text), `#555`/`#444`/`#666` (body text).

- [x] **Step 1.5: Run tests to confirm they pass**

```bash
cd ~/sunbites-pos && npm test -- --testPathPattern="student-list" --watchAll=false
```

Expected: all tests pass including the two new preview color tests.

- [x] **Step 1.6: Commit**

```bash
cd ~/sunbites-pos && git add app/\(kitchen\)/students/page.tsx app/\(kitchen\)/students/student-list.test.tsx
git commit -m "fix(students): apply student-type colors to QrCard preview in batch print modal"
```

---

## Task 2 — Cards-Per-Row: Add 4, Make Default, Reorder Buttons

**Files:**
- Modify: `app/(kitchen)/students/page.tsx` (`BatchQrModal` component, lines 813–922)
- Test: `app/(kitchen)/students/student-list.test.tsx`

**Interfaces:**
- Consumes: `cols` state (type changes from `1 | 2 | 3` to `1 | 2 | 3 | 4`, default from `2` to `4`)
- Produces: button row shows `[4] [3] [2] [1]`; preview grid has a `grid-cols-4` case

---

- [x] **Step 2.1: Write the failing test**

Add inside the existing `describe("StudentsPage")` block in `student-list.test.tsx`:

```typescript
it("BatchQrModal defaults to 4 cards per row with button 4 highlighted", async () => {
  const user = userEvent.setup();
  render(<StudentsPage />);
  await screen.findByText("Maria Santos");

  const checkboxes = screen.getAllByRole("checkbox");
  await user.click(checkboxes[0]);
  await user.click(screen.getByRole("button", { name: /print qr codes/i }));

  // "4" button should exist and be active (font-semibold)
  const btn4 = screen.getByRole("button", { name: "4" });
  expect(btn4).toHaveClass("font-semibold");

  // "2" should exist but NOT be active
  const btn2 = screen.getByRole("button", { name: "2" });
  expect(btn2).not.toHaveClass("font-semibold");
});
```

- [x] **Step 2.2: Run test to confirm it fails**

```bash
cd ~/sunbites-pos && npm test -- --testPathPattern="student-list" --watchAll=false
```

Expected: FAIL — button "4" does not exist yet (options are 1, 2, 3 with default 2).

- [x] **Step 2.3: Update `BatchQrModal` cols state, button array, and preview grid**

In `page.tsx` inside `BatchQrModal` (line 814):

```tsx
// Before:
  const [cols, setCols] = useState<1 | 2 | 3>(2);

// After:
  const [cols, setCols] = useState<1 | 2 | 3 | 4>(4);
```

Update the button render array (line 871):

```tsx
// Before:
            {([1, 2, 3] as const).map((n) => (

// After:
            {([4, 3, 2, 1] as const).map((n) => (
```

Update the preview grid className (lines 890–897):

```tsx
// Before:
            className={cn(
              "grid gap-3 mt-2 justify-items-center",
              cols === 1
                ? "grid-cols-1"
                : cols === 2
                  ? "grid-cols-2"
                  : "grid-cols-3",
            )}

// After:
            className={cn(
              "grid gap-3 mt-2 justify-items-center",
              cols === 1
                ? "grid-cols-1"
                : cols === 2
                  ? "grid-cols-2"
                  : cols === 3
                    ? "grid-cols-3"
                    : "grid-cols-4",
            )}
```

The print portal `gridTemplateColumns: \`repeat(${cols}, 53.98mm)\`` already handles any number — no change needed there.

- [x] **Step 2.4: Run tests to confirm they pass**

```bash
cd ~/sunbites-pos && npm test -- --testPathPattern="student-list" --watchAll=false
```

Expected: all tests pass.

- [x] **Step 2.5: Commit**

```bash
cd ~/sunbites-pos && git add app/\(kitchen\)/students/page.tsx app/\(kitchen\)/students/student-list.test.tsx
git commit -m "feat(students): add cols=4 option to batch QR print modal, default to 4, reorder buttons [4,3,2,1]"
```

---

## Task 3 — Dynamic Dialog Width + Scroll Container

**Files:**
- Modify: `app/(kitchen)/students/page.tsx` (`BatchQrModal`, `DialogContent` and preview wrapper)
- Test: `app/(kitchen)/students/student-list.test.tsx`

**Interfaces:**
- Consumes: `cols` state (now `1 | 2 | 3 | 4` from Task 2)
- Produces: `DialogContent` className changes with `cols`; preview grid wrapped in `overflow-y-auto max-h-[60vh]`

---

- [x] **Step 3.1: Write the failing test**

Add inside `describe("StudentsPage")` in `student-list.test.tsx`:

```typescript
it("BatchQrModal dialog has sm:max-w-4xl class when cols is 4 (default)", async () => {
  const user = userEvent.setup();
  render(<StudentsPage />);
  await screen.findByText("Maria Santos");

  const checkboxes = screen.getAllByRole("checkbox");
  await user.click(checkboxes[0]);
  await user.click(screen.getByRole("button", { name: /print qr codes/i }));

  const dialog = screen.getByRole("dialog");
  expect(dialog).toHaveClass("sm:max-w-4xl");
});

it("BatchQrModal dialog width shrinks when cols is changed to 3", async () => {
  const user = userEvent.setup();
  render(<StudentsPage />);
  await screen.findByText("Maria Santos");

  const checkboxes = screen.getAllByRole("checkbox");
  await user.click(checkboxes[0]);
  await user.click(screen.getByRole("button", { name: /print qr codes/i }));

  // Switch to 3 cols
  await user.click(screen.getByRole("button", { name: "3" }));

  const dialog = screen.getByRole("dialog");
  expect(dialog).toHaveClass("sm:max-w-2xl");
  expect(dialog).not.toHaveClass("sm:max-w-4xl");
});
```

- [x] **Step 3.2: Run tests to confirm they fail**

```bash
cd ~/sunbites-pos && npm test -- --testPathPattern="student-list" --watchAll=false
```

Expected: FAIL — dialog currently has `sm:max-w-2xl` regardless of cols.

- [x] **Step 3.3: Add `dialogWidthClass` lookup and update `DialogContent`**

In `page.tsx` inside `BatchQrModal`, add the width lookup after the `cols` state (line 815):

```tsx
  const dialogWidthClass: Record<1 | 2 | 3 | 4, string> = {
    1: "sm:max-w-sm",
    2: "sm:max-w-xl",
    3: "sm:max-w-2xl",
    4: "sm:max-w-4xl",
  };
```

Update the `DialogContent` opening tag (line 860):

```tsx
// Before:
        <DialogContent className="sm:max-w-2xl">

// After:
        <DialogContent className={dialogWidthClass[cols]}>
```

- [x] **Step 3.4: Wrap the preview grid in a scroll container**

In `page.tsx`, wrap the preview grid `<div className={cn("grid gap-3 ...` with a scroll container:

```tsx
// Before:
          {/* Screen preview — portrait card aspect ratio */}
          <div
            className={cn(
              "grid gap-3 mt-2 justify-items-center",
              ...
            )}
          >
            {students.map((s) => (
              <div key={s.id} className="w-[152px] h-[240px]">
                <QrCard student={s} />
              </div>
            ))}
          </div>

// After:
          {/* Screen preview — portrait card aspect ratio */}
          <div className="overflow-y-auto max-h-[60vh]">
            <div
              className={cn(
                "grid gap-3 mt-2 justify-items-center",
                cols === 1
                  ? "grid-cols-1"
                  : cols === 2
                    ? "grid-cols-2"
                    : cols === 3
                      ? "grid-cols-3"
                      : "grid-cols-4",
              )}
            >
              {students.map((s) => (
                <div key={s.id} className="w-[152px] h-[240px]">
                  <QrCard student={s} />
                </div>
              ))}
            </div>
          </div>
```

- [x] **Step 3.5: Run all tests to confirm they pass**

```bash
cd ~/sunbites-pos && npm test -- --testPathPattern="student-list" --watchAll=false
```

Expected: all tests pass (including all previously passing tests — regression check).

- [x] **Step 3.6: Commit**

```bash
cd ~/sunbites-pos && git add app/\(kitchen\)/students/page.tsx app/\(kitchen\)/students/student-list.test.tsx
git commit -m "fix(students): adapt batch QR modal width to cards-per-row selection, add scroll container"
```

---

## Final Verification

- [x] **Run full student-list test suite one last time**

```bash
cd ~/sunbites-pos && npm test -- --testPathPattern="student-list" --watchAll=false
```

All tests pass. No regressions.

- [x] **Invoke `/superpowers:verification-before-completion`** before marking done.
- [x] **Invoke `laravel-simplifier`** (laravel-simplifier agent) to review code changes in `page.tsx`.
- [x] **Mark all tasks complete in this file.**
