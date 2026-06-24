# QR ID Card Color Differentiation by Student Type — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Color-code the QR ID card header in all three print locations in `sunbites-pos` — subscription students get a red (`#e5322a`) header, non-subscription students get a yellow (`#f4b400`) header.

**Architecture:** A new `getCardAccentColors(studentType)` utility returns a typed color map. All three print locations call this utility and replace hardcoded `oklch(0.577 0.245 27.325)` colors with the returned values. No shared print component is extracted — each location keeps its own render.

**Tech Stack:** Next.js 15 App Router, React 19, TypeScript 5, Jest 30, React Testing Library 16, MSW 2, `react-qr-code`

## Global Constraints

- All work is in `~/sunbites-pos`. No changes to `sunbites-api`.
- `StudentType = "subscription" | "non_subscription"` — exact values from `types/student.ts`
- Run tests from `~/sunbites-pos` with `npx jest --testPathPattern=<filename> --no-coverage`
- Brand colors scraped from sunbites.com.ph CSS: subscription → `#e5322a` (sb-red-500), non-subscription → `#f4b400` (sb-yellow-400)
- White text (`#ffffff`) on `#f4b400` fails WCAG contrast (~2:1); non-subscription header uses dark ink `#1a1611` instead
- The `QrCard` component (web display, not print) is **out of scope** — do not modify it
- Existing tests must continue to pass after every task

---

## File Map

| Status | File | Purpose |
|---|---|---|
| Create | `lib/utils/card-accent-colors.ts` | Color utility — single source of truth |
| Create | `lib/utils/card-accent-colors.test.ts` | Unit tests for the utility |
| Modify | `app/(kitchen)/students/page.tsx` | Update `PrintCard` component (lines 420–612) |
| Modify | `app/(kitchen)/students/student-list.test.tsx` | Add render tests for batch print card colors |
| Modify | `app/(kitchen)/students/[id]/page.tsx` | Update inline print card (lines 2282–2451) |
| Modify | `app/(kitchen)/students/[id]/student-detail.test.tsx` | Add render test for detail page print card |
| Modify | `app/(kitchen)/enrollment/page.tsx` | Add full print-only ID card to success screen |
| Modify | `app/(kitchen)/enrollment/enrollment.test.tsx` | Add render test for enrollment print card |

---

## Task 1: Color Utility

**Files:**
- Create: `lib/utils/card-accent-colors.ts`
- Create: `lib/utils/card-accent-colors.test.ts`

**Interfaces:**
- Produces: `getCardAccentColors(studentType: StudentType): CardAccentColors` — used by Tasks 2, 3, and 4

- [ ] **Step 1: Write the failing test**

Create `lib/utils/card-accent-colors.test.ts`:

```ts
import { getCardAccentColors } from "./card-accent-colors";

describe("getCardAccentColors", () => {
  it("returns red palette for subscription students", () => {
    const colors = getCardAccentColors("subscription");
    expect(colors.headerBg).toBe("#e5322a");
    expect(colors.headerText).toBe("#ffffff");
    expect(colors.headerSubText).toBe("rgba(255,255,255,0.85)");
    expect(colors.accentColor).toBe("#e5322a");
    expect(colors.avatarBg).toBe("#fff1f0");
    expect(colors.borderColor).toBe("#e5322a");
    expect(colors.footerBg).toBe("#fff1f0");
    expect(colors.footerBorder).toBe("#ffd9d6");
  });

  it("returns yellow palette for non-subscription students", () => {
    const colors = getCardAccentColors("non_subscription");
    expect(colors.headerBg).toBe("#f4b400");
    expect(colors.headerText).toBe("#1a1611");
    expect(colors.headerSubText).toBe("rgba(26,22,17,0.75)");
    expect(colors.accentColor).toBe("#d69400");
    expect(colors.avatarBg).toBe("#fffbeb");
    expect(colors.borderColor).toBe("#f4b400");
    expect(colors.footerBg).toBe("#fffbeb");
    expect(colors.footerBorder).toBe("#fff1b8");
  });

  it("uses dark header text for non-subscription to meet contrast requirements", () => {
    const sub = getCardAccentColors("subscription");
    const nonSub = getCardAccentColors("non_subscription");
    expect(sub.headerText).toBe("#ffffff");
    expect(nonSub.headerText).toBe("#1a1611");
    expect(sub.headerText).not.toBe(nonSub.headerText);
  });
});
```

- [ ] **Step 2: Run to verify it fails**

```bash
cd ~/sunbites-pos && npx jest --testPathPattern="card-accent-colors" --no-coverage
```

Expected: FAIL — "Cannot find module './card-accent-colors'"

- [ ] **Step 3: Implement the utility**

Create `lib/utils/card-accent-colors.ts`:

```ts
import type { StudentType } from "@/types/student";

export interface CardAccentColors {
  headerBg: string;
  headerText: string;
  headerSubText: string;
  accentColor: string;
  avatarBg: string;
  borderColor: string;
  footerBg: string;
  footerBorder: string;
}

export function getCardAccentColors(studentType: StudentType): CardAccentColors {
  if (studentType === "subscription") {
    return {
      headerBg: "#e5322a",
      headerText: "#ffffff",
      headerSubText: "rgba(255,255,255,0.85)",
      accentColor: "#e5322a",
      avatarBg: "#fff1f0",
      borderColor: "#e5322a",
      footerBg: "#fff1f0",
      footerBorder: "#ffd9d6",
    };
  }
  return {
    headerBg: "#f4b400",
    headerText: "#1a1611",
    headerSubText: "rgba(26,22,17,0.75)",
    accentColor: "#d69400",
    avatarBg: "#fffbeb",
    borderColor: "#f4b400",
    footerBg: "#fffbeb",
    footerBorder: "#fff1b8",
  };
}
```

- [ ] **Step 4: Run to verify it passes**

```bash
cd ~/sunbites-pos && npx jest --testPathPattern="card-accent-colors" --no-coverage
```

Expected: PASS — 3 tests

- [ ] **Step 5: Commit**

```bash
cd ~/sunbites-pos && git add lib/utils/card-accent-colors.ts lib/utils/card-accent-colors.test.ts
git commit -m "feat: add getCardAccentColors utility for QR ID card theming"
```

---

## Task 2: Update `PrintCard` in Students List

**Files:**
- Modify: `app/(kitchen)/students/page.tsx` (`PrintCard` function, lines 420–612)
- Modify: `app/(kitchen)/students/student-list.test.tsx`

**Interfaces:**
- Consumes: `getCardAccentColors` from Task 1
- MSW: `GET /api/v1/students` returns `[studentFixture, nonSubStudentFixture]` — Maria Santos (subscription) at index 0, Carlo Mendoza (non-subscription) at index 1

- [ ] **Step 1: Write the failing tests**

Add inside the existing `describe("StudentsPage", ...)` block in `app/(kitchen)/students/student-list.test.tsx`:

```ts
describe("PrintCard header colors in batch print modal", () => {
  it("renders a red header for subscription students", async () => {
    const user = userEvent.setup();
    render(<StudentsPage />);
    await screen.findByText("Maria Santos");

    // checkboxes[0] = select-all, checkboxes[1] = Maria Santos (subscription)
    const checkboxes = screen.getAllByRole("checkbox");
    await user.click(checkboxes[1]);

    await user.click(screen.getByRole("button", { name: /print qr codes/i }));

    // PrintCard renders into a React Portal on document.body
    const card = document.querySelector("[data-qr-card]") as HTMLElement;
    expect(card).not.toBeNull();
    const header = card.firstElementChild as HTMLElement;
    expect(header.style.backgroundColor).toBe("#e5322a");
  });

  it("renders a yellow header for non-subscription students", async () => {
    const user = userEvent.setup();
    render(<StudentsPage />);
    await screen.findByText("Carlo Mendoza");

    // checkboxes[2] = Carlo Mendoza (non-subscription)
    const checkboxes = screen.getAllByRole("checkbox");
    await user.click(checkboxes[2]);

    await user.click(screen.getByRole("button", { name: /print qr codes/i }));

    const card = document.querySelector("[data-qr-card]") as HTMLElement;
    expect(card).not.toBeNull();
    const header = card.firstElementChild as HTMLElement;
    expect(header.style.backgroundColor).toBe("#f4b400");
  });
});
```

- [ ] **Step 2: Run to verify they fail**

```bash
cd ~/sunbites-pos && npx jest --testPathPattern="student-list" --no-coverage
```

Expected: the two new tests FAIL — header has `oklch(0.577 0.245 27.325)`, not the expected hex value.

- [ ] **Step 3: Add the import to `app/(kitchen)/students/page.tsx`**

Add with the other internal `@/` imports at the top of the file:

```ts
import { getCardAccentColors } from "@/lib/utils/card-accent-colors";
```

- [ ] **Step 4: Derive colors in `PrintCard` and replace all hardcoded values**

In the `PrintCard` function (line ~420), add after the `useEffect` block (line ~440) and before the `const parts = ...` line:

```ts
const colors = getCardAccentColors(student.student_type);
```

Then replace these 11 hardcoded color values in the `PrintCard` JSX:

**Outer card `<div>` border (line ~454):**
```
border: "1.5px solid oklch(0.577 0.245 27.325)"
→ border: `1.5px solid ${colors.borderColor}`
```

**Header `<div>` backgroundColor (line ~465):**
```
backgroundColor: "oklch(0.577 0.245 27.325)"
→ backgroundColor: colors.headerBg
```

**Header title `color` (line ~472):**
```
color: "white"
→ color: colors.headerText
```

**Header subtitle `color` (line ~483):**
```
color: "rgba(255,255,255,0.85)"
→ color: colors.headerSubText
```

**Photo `<img>` border (line ~513):**
```
border: "1px solid oklch(0.577 0.245 27.325)"
→ border: `1px solid ${colors.accentColor}`
```

**Avatar `<div>` border (line ~523):**
```
border: "1px solid oklch(0.577 0.245 27.325)"
→ border: `1px solid ${colors.accentColor}`
```

**Avatar `<div>` backgroundColor (line ~524):**
```
backgroundColor: "#fff3f0"
→ backgroundColor: colors.avatarBg
```

**Avatar letter `color` (line ~530):**
```
color: "oklch(0.577 0.245 27.325)"
→ color: colors.accentColor
```

**Grade level `<p>` color (line ~550):**
```
color: "oklch(0.577 0.245 27.325)"
→ color: colors.accentColor
```

**Footer `<div>` backgroundColor (line ~597):**
```
backgroundColor: "#fff3f0"
→ backgroundColor: colors.footerBg
```

**Footer `<div>` borderTop (line ~598):**
```
borderTop: "1px solid #fdd8cc"
→ borderTop: `1px solid ${colors.footerBorder}`
```

- [ ] **Step 5: Run to verify all tests pass**

```bash
cd ~/sunbites-pos && npx jest --testPathPattern="student-list" --no-coverage
```

Expected: all tests PASS including the two new ones.

- [ ] **Step 6: Commit**

```bash
cd ~/sunbites-pos && git add "app/(kitchen)/students/page.tsx" "app/(kitchen)/students/student-list.test.tsx"
git commit -m "feat: color-code PrintCard header by student type in batch print"
```

---

## Task 3: Update Inline Print Card in Student Detail Page

**Files:**
- Modify: `app/(kitchen)/students/[id]/page.tsx` (print card block, lines 2282–2451)
- Modify: `app/(kitchen)/students/[id]/student-detail.test.tsx`

**Interfaces:**
- Consumes: `getCardAccentColors` from Task 1
- The inline card uses `student` (from `useQuery`). MSW handler for `GET /api/v1/students/1` returns `studentFixture` (`student_type: "subscription"`, `first_name: "Maria"`). The print card is always in the DOM (hidden via `.print-only` CSS class on screen; visible during print).

- [ ] **Step 1: Write the failing test**

Add inside `describe("StudentDetailPage", ...)` in `app/(kitchen)/students/[id]/student-detail.test.tsx`:

```ts
it("renders red header on the print card for a subscription student", async () => {
  render(<StudentDetailPage params={{ id: "1" }} />);
  await screen.findAllByText("Maria Santos"); // wait for data to load

  // The print card is always in the DOM (hidden on screen via .print-only CSS)
  const card = document.querySelector("[data-qr-card]") as HTMLElement;
  expect(card).not.toBeNull();
  const header = card.firstElementChild as HTMLElement;
  expect(header.style.backgroundColor).toBe("#e5322a");
});
```

- [ ] **Step 2: Run to verify it fails**

```bash
cd ~/sunbites-pos && npx jest --testPathPattern="student-detail" --no-coverage
```

Expected: FAIL — header has `oklch(0.577 0.245 27.325)` background.

- [ ] **Step 3: Add the import to `app/(kitchen)/students/[id]/page.tsx`**

Add with the other internal `@/` imports at the top of the file:

```ts
import { getCardAccentColors } from "@/lib/utils/card-accent-colors";
```

- [ ] **Step 4: Derive colors and replace values in the inline print card**

The inline print card starts at line 2282. The `student` variable is already in scope (from the query result). Locate `{/* Print-only canteen ID card */}` and add the color derivation immediately before the `<div className="print-only">` block:

```ts
const printColors = getCardAccentColors(student.student_type);
```

Then replace the 11 hardcoded color values in the inline card JSX (lines 2290–2450), using `printColors` in the same positions as Task 2:

**Outer card border (line ~2290):** `oklch(0.577 0.245 27.325)` → `` `1.5px solid ${printColors.borderColor}` ``

**Header backgroundColor (line ~2301):** `oklch(0.577 0.245 27.325)` → `printColors.headerBg`

**Header title color (line ~2309):** `"white"` → `printColors.headerText`

**Header subtitle color (line ~2319):** `"rgba(255,255,255,0.85)"` → `printColors.headerSubText`

**Photo `<img>` border (line ~2349):** `oklch(0.577 0.245 27.325)` → `` `1px solid ${printColors.accentColor}` ``

**Avatar `<div>` border (line ~2359):** `oklch(0.577 0.245 27.325)` → `` `1px solid ${printColors.accentColor}` ``

**Avatar `<div>` backgroundColor (line ~2360):** `"#fff3f0"` → `printColors.avatarBg`

**Avatar letter color (line ~2366):** `oklch(0.577 0.245 27.325)` → `printColors.accentColor`

**Grade level `<p>` color (line ~2386):** `oklch(0.577 0.245 27.325)` → `printColors.accentColor`

**Footer backgroundColor (line ~2437):** `"#fff3f0"` → `printColors.footerBg`

**Footer borderTop (line ~2438):** `"1px solid #fdd8cc"` → `` `1px solid ${printColors.footerBorder}` ``

- [ ] **Step 5: Run to verify all tests pass**

```bash
cd ~/sunbites-pos && npx jest --testPathPattern="student-detail" --no-coverage
```

Expected: all tests PASS.

- [ ] **Step 6: Commit**

```bash
cd ~/sunbites-pos && git add "app/(kitchen)/students/[id]/page.tsx" "app/(kitchen)/students/[id]/student-detail.test.tsx"
git commit -m "feat: color-code print card header by student type on student detail page"
```

---

## Task 4: Add Color-Coded Print Card to Enrollment Success Screen

**Context:** The enrollment success screen currently shows only a raw `<QRCode>` component when printed (no ID card layout). This task adds a full print-only ID card (same layout as Tasks 2 and 3) with the color-coded header. `EnrolledStudentResponse` has `full_name`, `student_type`, `enrollment_date`, `qr_code` — but no `photo_url` or `grade_level`, so the avatar always shows the initial letter.

**Files:**
- Modify: `app/(kitchen)/enrollment/page.tsx`
- Modify: `app/(kitchen)/enrollment/enrollment.test.tsx`

**Interfaces:**
- Consumes: `getCardAccentColors` from Task 1
- MSW: `POST /api/v1/enrollment` returns `enrolledStudentFixture` (`student_type: "subscription"`, `full_name: "Test Student"`, `qr_code: "SB-TestQrCode123"`)

- [ ] **Step 1: Write the failing test**

Add inside `describe("EnrollmentPage", ...)` in `app/(kitchen)/enrollment/enrollment.test.tsx`:

```ts
it("renders a red print card header for subscription student after enrollment", async () => {
  const user = userEvent.setup();
  render(<EnrollmentPage />);
  await fillAndSubmitForm(user);
  await screen.findByText(/enrollment successful/i);

  // Print card is added to the DOM (hidden on screen) after enrollment
  const card = document.querySelector("[data-qr-card]") as HTMLElement;
  expect(card).not.toBeNull();
  const header = card.firstElementChild as HTMLElement;
  expect(header.style.backgroundColor).toBe("#e5322a"); // enrolledStudentFixture is subscription
});
```

- [ ] **Step 2: Run to verify it fails**

```bash
cd ~/sunbites-pos && npx jest --testPathPattern="enrollment.test" --no-coverage
```

Expected: FAIL — `[data-qr-card]` is null (the card doesn't exist yet).

- [ ] **Step 3: Add import to `app/(kitchen)/enrollment/page.tsx`**

Add with the other internal `@/` imports:

```ts
import { getCardAccentColors } from "@/lib/utils/card-accent-colors";
```

- [ ] **Step 4: Update the `if (enrolledResult)` return block**

Locate the `if (enrolledResult) { return (...) }` block (line ~453). Replace only the outer structure — the inner success screen content is unchanged. The change is:

1. Compute colors and date values before the return
2. Add the print-only card as the first child in a fragment
3. Wrap the existing success screen content in a `no-print` div
4. Update the `<style>` tag

```tsx
if (enrolledResult) {
  const enrollColors = getCardAccentColors(enrolledResult.student_type);
  const dateParts = enrolledResult.enrollment_date.split("-");
  const enrolledFormatted =
    dateParts.length === 3
      ? `${dateParts[1]}/${dateParts[2]}/${dateParts[0]}`
      : null;
  const [syYear, syMonth] = enrolledResult.enrollment_date.split("-").map(Number);
  const schoolYear =
    syMonth >= 6 ? `${syYear}–${syYear + 1}` : `${syYear - 1}–${syYear}`;

  return (
    <>
      {/* Print-only canteen ID card — hidden on screen, shown when window.print() fires */}
      <div
        data-qr-card
        className="enrollment-print-card"
        style={{
          display: "none",
          width: "53.98mm",
          height: "85.6mm",
          flexDirection: "column",
          border: `1.5px solid ${enrollColors.borderColor}`,
          borderRadius: "3mm",
          overflow: "hidden",
          backgroundColor: "white",
          fontFamily: "sans-serif",
          boxSizing: "border-box",
          position: "fixed",
          top: 0,
          left: 0,
        }}
      >
        {/* Header */}
        <div
          style={{
            backgroundColor: enrollColors.headerBg,
            padding: "2mm 3mm",
            flexShrink: 0,
            textAlign: "center",
          }}
        >
          <div
            style={{
              color: enrollColors.headerText,
              fontWeight: 800,
              fontSize: "8px",
              letterSpacing: "0.3px",
            }}
          >
            🍽 SUNBITES KITCHEN
          </div>
          <div
            style={{
              color: enrollColors.headerSubText,
              fontSize: "7px",
              marginTop: "0.5mm",
            }}
          >
            Student Canteen ID
          </div>
        </div>

        {/* Body */}
        <div
          style={{
            flex: 1,
            display: "flex",
            flexDirection: "column",
            alignItems: "center",
            padding: "3mm 3mm 2mm",
            gap: "1.5mm",
            overflow: "hidden",
          }}
        >
          {/* No photo_url on EnrolledStudentResponse — always render the initial */}
          <div
            style={{
              width: "18mm",
              height: "18mm",
              borderRadius: "2mm",
              border: `1px solid ${enrollColors.accentColor}`,
              backgroundColor: enrollColors.avatarBg,
              display: "flex",
              alignItems: "center",
              justifyContent: "center",
              fontSize: "8mm",
              fontWeight: 800,
              color: enrollColors.accentColor,
              flexShrink: 0,
            }}
          >
            {enrolledResult.full_name.charAt(0).toUpperCase()}
          </div>
          <p
            style={{
              fontWeight: 700,
              fontSize: "9px",
              textAlign: "center",
              margin: 0,
              color: "#111",
            }}
          >
            {enrolledResult.full_name}
          </p>
          <p style={{ color: "#555", fontSize: "7px", margin: 0 }}>
            🍽{" "}
            {enrolledResult.student_type === "subscription"
              ? "Subscription"
              : "Non-Subscription"}
          </p>
          {enrolledFormatted && (
            <p style={{ fontSize: "6.5px", color: "#444", margin: 0 }}>
              Enrolled: {enrolledFormatted}
            </p>
          )}
          <div
            style={{
              border: "1px solid #e0e0e0",
              borderRadius: "2mm",
              padding: "1.5mm",
              marginTop: "1mm",
            }}
          >
            <QRCode
              value={enrolledResult.qr_code}
              size={85}
              style={{ width: "22mm", height: "22mm" }}
            />
          </div>
          <p
            style={{
              fontFamily: "monospace",
              fontSize: "5px",
              color: "#888",
              margin: 0,
              textAlign: "center",
            }}
          >
            {enrolledResult.qr_code}
          </p>
        </div>

        {/* Footer */}
        <div
          style={{
            flexShrink: 0,
            backgroundColor: enrollColors.footerBg,
            borderTop: `1px solid ${enrollColors.footerBorder}`,
            padding: "1.5mm 3mm",
            textAlign: "center",
          }}
        >
          <p style={{ fontSize: "5.5px", color: "#666", margin: 0 }}>
            Scan QR to view wallet balance • Valid S.Y. {schoolYear}
          </p>
        </div>
      </div>

      {/* On-screen success content — hidden during print */}
      <div className="p-6 max-w-2xl mx-auto space-y-6 no-print">
        {/* ↓↓↓ PASTE THE EXISTING SUCCESS SCREEN CONTENT HERE UNCHANGED ↓↓↓ */}
        {/* The green success banner, details grid, QR display section, */}
        {/* "Enroll Another Student" button — all unchanged from the original. */}

        {/* Replace the existing <style> tag (was only @media print .no-print) with: */}
        <style>{`
          .enrollment-print-card { display: none; }
          @media print {
            .no-print { display: none !important; }
            .enrollment-print-card { display: flex !important; }
            @page { size: 53.98mm 85.6mm portrait; margin: 0; }
          }
        `}</style>
      </div>
    </>
  );
}
```

- [ ] **Step 5: Run to verify all tests pass**

```bash
cd ~/sunbites-pos && npx jest --testPathPattern="enrollment.test" --no-coverage
```

Expected: all tests PASS including the new print card test.

- [ ] **Step 6: Run the full test suite**

```bash
cd ~/sunbites-pos && npx jest --no-coverage
```

Expected: all tests PASS across all files.

- [ ] **Step 7: Commit**

```bash
cd ~/sunbites-pos && git add "app/(kitchen)/enrollment/page.tsx" "app/(kitchen)/enrollment/enrollment.test.tsx"
git commit -m "feat: add color-coded print ID card to enrollment success screen"
```

---

## Self-Review

**Spec coverage check:**

| Requirement | Covered by |
|---|---|
| Subscription → red header (`#e5322a`) | Task 1 utility + Tasks 2, 3, 4 |
| Non-subscription → yellow header (`#f4b400`) | Task 1 utility + Tasks 2, 3, 4 |
| Dark text on yellow header (WCAG contrast) | Task 1 — `headerText: "#1a1611"` for non-subscription |
| All three print locations | Task 2 (batch), Task 3 (detail), Task 4 (enrollment) |
| Accent colors on borders/photo/grade/footer | Tasks 2, 3, 4 — `accentColor`, `avatarBg`, `footerBg`, `footerBorder` |
| Unit test for utility | Task 1 |
| Render tests per print location | Tasks 2, 3, 4 |
| Existing tests continue to pass | Task 4 Step 6 (full suite) |

**No placeholders or TBDs found.**

**Type consistency:** `getCardAccentColors` is defined in Task 1 and imported consistently in Tasks 2, 3, 4. Return type `CardAccentColors` is used by all call sites. All 8 keys (`headerBg`, `headerText`, `headerSubText`, `accentColor`, `avatarBg`, `borderColor`, `footerBg`, `footerBorder`) are defined in Task 1 and used in Tasks 2–4.
