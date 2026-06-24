# Kiosk Visual Refresh Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Refresh the `/kiosk` page in `sunbites-pos` with the Sunbites logo always visible, an animated scan guide, a springy card pop-in, a balance count-up animation, per-tier emojis/messages, confetti on green balance, and a shake animation on errors.

**Architecture:** All changes are in a single Next.js Client Component (`page.tsx`). Two CSS keyframes are added to `globals.css`. `canvas-confetti` is the only new npm dependency. No backend or route changes.

**Tech Stack:** Next.js 16 App Router, React 19, Tailwind v4 + `tw-animate-css` (already installed), `canvas-confetti` (new), `requestAnimationFrame` (built-in).

## Global Constraints

- Repo: `~/sunbites-pos`
- All commands run via `vendor/bin/sail` in `~/sunbites-api` for Laravel; for Next.js run `npx` directly in `~/sunbites-pos`
- Run tests with: `cd ~/sunbites-pos && npx jest "app/\(kiosk\)/kiosk/kiosk.test.tsx" --no-coverage`
- No default exports for components — Next.js page/layout files are the only exception (`export default`)
- No `any` types
- No inline styles except for `animationTimingFunction` and named `animation` overrides (where Tailwind cannot express the value)
- Tailwind utility classes only for layout/color — no CSS modules, no `style={{}}` for layout
- `tw-animate-css` (imported as `@import "tw-animate-css"` in `globals.css`) provides `animate-in`, `zoom-in-75`, `fade-in`, `duration-*` — do NOT install `tailwindcss-animate` separately
- Balance tiers (verbatim from spec): Green ≥ ₱150 | Orange ₱80–₱149 | Red ≤ ₱79
- Orange message (verbatim): "Your balance is running low. Please top up soon!"
- Red message (verbatim): "Insufficient balance. Please top up before ordering."
- First name: `student.name.split(' ')[0]`

---

## File Structure

| File | Action | Responsibility |
|---|---|---|
| `app/globals.css` | Modify | Add `@keyframes scanLine` and `@keyframes shake` |
| `package.json` | Modify (via npm install) | Add `canvas-confetti` + `@types/canvas-confetti` |
| `app/(kiosk)/kiosk/page.tsx` | Modify | Full visual refresh — logo, animations, tiers, count-up, confetti |
| `app/(kiosk)/kiosk/kiosk.test.tsx` | Modify | Mock confetti, update 2 fixtures, add 3 tier tests |

---

### Task 1: CSS Keyframes + canvas-confetti Install

**Files:**
- Modify: `app/globals.css` (append after the `@media print` block)
- Shell: `npm install canvas-confetti @types/canvas-confetti --save` in `~/sunbites-pos`

**Interfaces:**
- Produces: `scanLine` keyframe (used by scan guide bar in Task 2), `shake` keyframe (used by ErrorCard in Task 2), `canvas-confetti` package importable as `import confetti from 'canvas-confetti'`

- [ ] **Step 1: Install canvas-confetti**

```bash
cd ~/sunbites-pos && npm install canvas-confetti && npm install -D @types/canvas-confetti
```

Expected output: `added N packages` with no errors.

- [ ] **Step 2: Verify the import works**

```bash
cd ~/sunbites-pos && node -e "require('canvas-confetti'); console.log('ok')"
```

Expected: `ok`

- [ ] **Step 3: Add keyframes to globals.css**

Open `~/sunbites-pos/app/globals.css`. Append these two keyframe blocks **after** the closing `}` of the `@media print` block, at the very end of the file:

```css
@keyframes scanLine {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(220px); }
}

@keyframes shake {
  0%, 100% { transform: translateX(0); }
  20% { transform: translateX(-8px); }
  40% { transform: translateX(8px); }
  60% { transform: translateX(-6px); }
  80% { transform: translateX(6px); }
}
```

- [ ] **Step 4: Commit**

```bash
cd ~/sunbites-pos && git add app/globals.css package.json package-lock.json && git commit -m "feat(kiosk): install canvas-confetti and add scanLine/shake keyframes"
```

---

### Task 2: Kiosk Page Visual Refresh + Tests

**Files:**
- Modify: `app/(kiosk)/kiosk/page.tsx`
- Modify: `app/(kiosk)/kiosk/kiosk.test.tsx`

**Interfaces:**
- Consumes from Task 1: `@keyframes scanLine` (via `style={{ animation: 'scanLine 2s ease-in-out infinite' }}`), `@keyframes shake` (via `style={{ animation: 'shake 0.4s ease-in-out' }}`), `canvas-confetti` package
- Consumes from existing hooks: `useKioskLookup()` → `{ state, student, handleScan, reset }`, `useKioskScanner({ videoRef, onScan, onCameraError, isEnabled })`
- Consumes from existing types: `KioskStudent` from `@/types/kiosk`
- Consumes from existing components: `AppLogo` from `@/components/app-logo` — `variant="full"` renders a red circle "S" + "Sunbites / Your Healthy Kitchen" text

- [ ] **Step 1: Update the test file — add confetti mock + update fixtures + add 3 tier tests**

Replace the contents of `~/sunbites-pos/app/(kiosk)/kiosk/kiosk.test.tsx` with:

```typescript
import { act, render, screen } from "@/__tests__/test-utils";
import { http, HttpResponse } from "msw";
import { server } from "@/__tests__/mocks/server";
import KioskPage from "./page";

// Mock canvas-confetti — no real canvas in jsdom
jest.mock("canvas-confetti", () => jest.fn());

// Mock @zxing/browser — camera does not work in jsdom
let capturedScanCallback:
  | ((result: { getText: () => string } | null) => void)
  | null = null;

// BrowserMultiFormatReader is an instance-based class in @zxing/browser v0.2.x.
// The constructor mock returns an object whose decodeFromVideoDevice captures
// the scan callback so tests can simulate QR scans via simulateScan().
jest.mock("@zxing/browser", () => ({
  BrowserMultiFormatReader: jest.fn().mockImplementation(() => ({
    decodeFromVideoDevice: jest.fn(
      (
        _deviceId: unknown,
        _video: unknown,
        callback: (result: { getText: () => string } | null) => void,
      ) => {
        capturedScanCallback = callback;
        return Promise.resolve({ stop: jest.fn() });
      },
    ),
  })),
}));

const simulateScan = (qrCode: string) => {
  act(() => {
    capturedScanCallback?.({ getText: () => qrCode });
  });
};

describe("KioskPage", () => {
  beforeEach(() => {
    capturedScanCallback = null;
  });

  it("shows the scan prompt on initial load", () => {
    render(<KioskPage />);
    expect(screen.getByText(/scan your id card/i)).toBeInTheDocument();
  });

  it("shows the student result card after a successful scan", async () => {
    render(<KioskPage />);

    simulateScan("SB-testqrcode1234");

    expect(await screen.findByText("Juan Dela Cruz")).toBeInTheDocument();
    expect(screen.getByText("Grade 3")).toBeInTheDocument();
    expect(screen.getByText("JD")).toBeInTheDocument();
    // balance: "245.00" — count-up ends at ₱245.00
    expect(await screen.findByText("₱245.00")).toBeInTheDocument();
    expect(screen.getByText("Rice Meal, Water")).toBeInTheDocument();
  });

  it("shows green balance color for amount >= 150", async () => {
    render(<KioskPage />);
    simulateScan("SB-testqrcode1234");

    // Default mock returns balance: "245.00" which is green (>= 150)
    const balance = await screen.findByText("₱245.00");
    expect(balance).toHaveClass("text-green-600");
  });

  it("shows orange balance for amount between 80 and 149", async () => {
    server.use(
      http.post(
        `${process.env.NEXT_PUBLIC_API_URL}/public/kiosk/lookup`,
        () =>
          HttpResponse.json({
            name: "Juan Dela Cruz",
            initials: "JD",
            grade_level: "Grade 3",
            student_type: "subscription",
            balance: "100.00",
            last_orders: [],
          }),
      ),
    );

    render(<KioskPage />);
    simulateScan("SB-testqrcode1234");

    const balance = await screen.findByText("₱100.00");
    expect(balance).toHaveClass("text-orange-500");
  });

  it("shows red balance for amount <= 79", async () => {
    server.use(
      http.post(
        `${process.env.NEXT_PUBLIC_API_URL}/public/kiosk/lookup`,
        () =>
          HttpResponse.json({
            name: "Juan Dela Cruz",
            initials: "JD",
            grade_level: "Grade 3",
            student_type: "subscription",
            balance: "0.00",
            last_orders: [],
          }),
      ),
    );

    render(<KioskPage />);
    simulateScan("SB-testqrcode1234");

    const balance = await screen.findByText("₱0.00");
    expect(balance).toHaveClass("text-red-600");
  });

  it("shows the same error card for 404", async () => {
    server.use(
      http.post(
        `${process.env.NEXT_PUBLIC_API_URL}/public/kiosk/lookup`,
        () =>
          HttpResponse.json({ message: "Student not found." }, { status: 404 }),
      ),
    );

    render(<KioskPage />);
    simulateScan("SB-testqrcode1234");

    expect(await screen.findByText(/please see a cashier/i)).toBeInTheDocument();
  });

  it("shows the same error card for 403 (restricted student)", async () => {
    server.use(
      http.post(
        `${process.env.NEXT_PUBLIC_API_URL}/public/kiosk/lookup`,
        () =>
          HttpResponse.json(
            { message: "Student is not eligible." },
            { status: 403 },
          ),
      ),
    );

    render(<KioskPage />);
    simulateScan("SB-testqrcode1234");

    expect(await screen.findByText(/please see a cashier/i)).toBeInTheDocument();
  });

  it("auto-resets to scan state after 10 seconds on result", async () => {
    jest.useFakeTimers({ doNotFake: ["nextTick", "setImmediate", "queueMicrotask", "Promise"] });

    try {
      render(<KioskPage />);
      simulateScan("SB-testqrcode1234");

      await screen.findByText("Juan Dela Cruz");

      act(() => {
        jest.advanceTimersByTime(10000);
      });

      expect(screen.getByText(/scan your id card/i)).toBeInTheDocument();
      expect(screen.queryByText("Juan Dela Cruz")).not.toBeInTheDocument();
    } finally {
      jest.useRealTimers();
    }
  });

  it("auto-resets to scan state after 5 seconds on error", async () => {
    jest.useFakeTimers({ doNotFake: ["nextTick", "setImmediate", "queueMicrotask", "Promise"] });

    server.use(
      http.post(
        `${process.env.NEXT_PUBLIC_API_URL}/public/kiosk/lookup`,
        () =>
          HttpResponse.json({ message: "Student not found." }, { status: 404 }),
      ),
    );

    try {
      render(<KioskPage />);
      simulateScan("SB-testqrcode1234");

      await screen.findByText(/please see a cashier/i);

      act(() => {
        jest.advanceTimersByTime(5000);
      });

      expect(screen.getByText(/scan your id card/i)).toBeInTheDocument();
    } finally {
      jest.useRealTimers();
    }
  });

  it("shows camera access required message when camera is denied", async () => {
    const { BrowserMultiFormatReader } = await import("@zxing/browser");
    // Override the constructor for this test to return an instance whose
    // decodeFromVideoDevice rejects with NotAllowedError (camera denied).
    (BrowserMultiFormatReader as jest.Mock).mockImplementationOnce(() => ({
      decodeFromVideoDevice: jest
        .fn()
        .mockRejectedValueOnce(
          new DOMException("Permission denied", "NotAllowedError"),
        ),
    }));

    render(<KioskPage />);

    expect(
      await screen.findByText(/camera access required/i),
    ).toBeInTheDocument();
  });

  it("ignores QR codes that do not start with SB-", () => {
    render(<KioskPage />);
    simulateScan("INVALID-123");

    expect(screen.getByText(/scan your id card/i)).toBeInTheDocument();
    expect(screen.queryByText(/please see a cashier/i)).not.toBeInTheDocument();
  });

  // ── Tier-specific emoji and message tests ───────────────────────────────

  it("shows no tier message for green balance (>= 150)", async () => {
    render(<KioskPage />);
    simulateScan("SB-testqrcode1234");

    await screen.findByText("Juan Dela Cruz");

    expect(
      screen.queryByText(/running low/i),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByText(/insufficient balance/i),
    ).not.toBeInTheDocument();
  });

  it("shows sad emoji and running low message for orange balance (80-149)", async () => {
    server.use(
      http.post(
        `${process.env.NEXT_PUBLIC_API_URL}/public/kiosk/lookup`,
        () =>
          HttpResponse.json({
            name: "Juan Dela Cruz",
            initials: "JD",
            grade_level: "Grade 3",
            student_type: "subscription",
            balance: "100.00",
            last_orders: [],
          }),
      ),
    );

    render(<KioskPage />);
    simulateScan("SB-testqrcode1234");

    await screen.findByText("Juan Dela Cruz");

    expect(screen.getByText("😢")).toBeInTheDocument();
    expect(
      screen.getByText("Your balance is running low. Please top up soon!"),
    ).toBeInTheDocument();
  });

  it("shows worried emoji and insufficient balance message for red balance (<= 79)", async () => {
    server.use(
      http.post(
        `${process.env.NEXT_PUBLIC_API_URL}/public/kiosk/lookup`,
        () =>
          HttpResponse.json({
            name: "Juan Dela Cruz",
            initials: "JD",
            grade_level: "Grade 3",
            student_type: "subscription",
            balance: "30.00",
            last_orders: [],
          }),
      ),
    );

    render(<KioskPage />);
    simulateScan("SB-testqrcode1234");

    await screen.findByText("Juan Dela Cruz");

    expect(screen.getByText("😰")).toBeInTheDocument();
    expect(
      screen.getByText("Insufficient balance. Please top up before ordering."),
    ).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run the tests — expect failures**

```bash
cd ~/sunbites-pos && npx jest "app/\(kiosk\)/kiosk/kiosk.test.tsx" --no-coverage
```

Expected: Several tests fail because `page.tsx` still has old tier logic and no new UI elements. Confirm the failures make sense (wrong balance color thresholds, missing emoji/message, etc.) before proceeding.

- [ ] **Step 3: Rewrite page.tsx**

Replace the entire contents of `~/sunbites-pos/app/(kiosk)/kiosk/page.tsx` with:

```typescript
"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import confetti from "canvas-confetti";

import { cn } from "@/lib/utils";
import { useKioskLookup } from "@/hooks/use-kiosk-lookup";
import { useKioskScanner } from "@/hooks/use-kiosk-scanner";
import { AppLogo } from "@/components/app-logo";
import type { KioskStudent } from "@/types/kiosk";

// Animates a number from 0 to `target` over `duration` ms with easeOut cubic.
// File-private — only used by StudentCard.
function useCountUp(target: number, duration = 900): number {
  const [value, setValue] = useState(0);

  useEffect(() => {
    if (target === 0) {
      setValue(0);
      return;
    }
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

// Balance tiers — exact thresholds from spec
function getBalanceTier(balance: number): "green" | "orange" | "red" {
  if (balance >= 150) return "green";
  if (balance >= 80) return "orange";
  return "red";
}

export default function KioskPage() {
  const videoRef = useRef<HTMLVideoElement>(null);
  const { state, student, handleScan, reset } = useKioskLookup();
  const [cameraBlocked, setCameraBlocked] = useState(false);

  const handleCameraError = useCallback(() => setCameraBlocked(true), []);

  const isScanningState = state === "scanning" || state === "loading";

  useKioskScanner({
    videoRef,
    onScan: handleScan,
    onCameraError: handleCameraError,
    isEnabled: state === "scanning" && !cameraBlocked,
  });

  // Auto-reset: 10 seconds on result, 5 seconds on error
  useEffect(() => {
    if (state !== "result" && state !== "error") return;
    const delay = state === "result" ? 10000 : 5000;
    const timer = setTimeout(reset, delay);
    return () => clearTimeout(timer);
  }, [state, reset]);

  return (
    <div className="relative flex min-h-screen flex-col items-center justify-center">
      {/* Camera viewfinder — always mounted, hidden when not in scanning/loading state */}
      <video
        ref={videoRef}
        className={cn(
          "absolute inset-0 h-full w-full object-cover",
          !isScanningState && "hidden",
        )}
        muted
        playsInline
      />

      {/* Logo — always visible at top, inverted (white) over the dark camera overlay */}
      <div
        className={cn(
          "absolute top-8 left-1/2 z-20 -translate-x-1/2",
          isScanningState && !cameraBlocked && "invert",
        )}
      >
        <AppLogo variant="full" />
      </div>

      {/* Scanning state overlay */}
      {isScanningState && !cameraBlocked && (
        <div className="relative z-10 flex flex-col items-center gap-6 text-white">
          {/* Scan guide frame */}
          <div className="relative h-64 w-64 overflow-hidden rounded-2xl border-4 border-white/80 shadow-[0_0_0_9999px_rgba(0,0,0,0.5)]">
            {/* Corner bracket accents */}
            <div className="pointer-events-none absolute inset-0">
              <div className="absolute left-0 top-0 h-6 w-6 border-l-4 border-t-4 border-white" />
              <div className="absolute right-0 top-0 h-6 w-6 border-r-4 border-t-4 border-white" />
              <div className="absolute bottom-0 left-0 h-6 w-6 border-b-4 border-l-4 border-white" />
              <div className="absolute bottom-0 right-0 h-6 w-6 border-b-4 border-r-4 border-white" />
            </div>

            {/* Animated scan line — only during scanning, not loading */}
            {state === "scanning" && (
              <div
                className="absolute left-0 h-0.5 w-full bg-primary opacity-90"
                style={{ animation: "scanLine 2s ease-in-out infinite" }}
              />
            )}

            {/* Loading spinner */}
            {state === "loading" && (
              <div className="absolute inset-0 flex items-center justify-center rounded-2xl bg-black/60">
                <div className="h-10 w-10 animate-spin rounded-full border-4 border-white border-t-transparent" />
              </div>
            )}
          </div>

          <p className={cn("text-xl font-medium tracking-wide", state === "scanning" && "animate-pulse")}>
            {state === "loading" ? "Checking..." : "Scan your ID card"}
          </p>
        </div>
      )}

      {/* Result state */}
      {state === "result" && student && (
        <StudentCard student={student} onReset={reset} />
      )}

      {/* Error state */}
      {state === "error" && <ErrorCard />}

      {/* Camera blocked state */}
      {cameraBlocked && (
        <div className="z-10 flex flex-col items-center gap-3 text-center">
          <p className="text-xl font-semibold">Camera access required.</p>
          <p className="text-muted-foreground">Please allow camera and refresh.</p>
        </div>
      )}
    </div>
  );
}

function StudentCard({
  student,
  onReset,
}: {
  student: KioskStudent;
  onReset: () => void;
}) {
  const balanceNum = parseFloat(student.balance);
  const tier = getBalanceTier(balanceNum);
  const displayValue = useCountUp(balanceNum);
  const firstName = student.name.split(" ")[0];

  // Fire confetti once when this card mounts — green tier only
  useEffect(() => {
    if (tier === "green") {
      confetti({ particleCount: 120, spread: 80, origin: { y: 0.3 } });
    }
  }, [tier]);

  const balanceColor =
    tier === "green"
      ? "text-green-600"
      : tier === "orange"
        ? "text-orange-500"
        : "text-red-600";

  const cardRing =
    tier === "orange"
      ? "ring-2 ring-orange-400 shadow-orange-100"
      : tier === "red"
        ? "ring-2 ring-red-400 shadow-red-100"
        : "";

  const tierEmoji = tier === "orange" ? "😢" : tier === "red" ? "😰" : null;

  const tierMessage =
    tier === "orange"
      ? "Your balance is running low. Please top up soon!"
      : tier === "red"
        ? "Insufficient balance. Please top up before ordering."
        : null;

  return (
    <div
      className={cn(
        "animate-in zoom-in-75 fade-in z-10 flex w-full max-w-sm flex-col items-center gap-5 rounded-2xl bg-card p-8 shadow-2xl duration-300",
        cardRing,
      )}
      style={{ animationTimingFunction: "cubic-bezier(0.34, 1.56, 0.64, 1)" }}
    >
      {/* Friendly greeting */}
      <p className="text-lg font-medium text-muted-foreground">
        Hi, {firstName}! 👋
      </p>

      {/* Avatar */}
      <div className="flex h-24 w-24 items-center justify-center rounded-full bg-primary text-3xl font-bold text-primary-foreground">
        {student.initials}
      </div>

      {/* Name + grade */}
      <div className="text-center">
        <p className="text-2xl font-bold">{student.name}</p>
        <p className="text-muted-foreground">{student.grade_level}</p>
      </div>

      {/* Subscription badge */}
      <span className="rounded-full bg-secondary px-3 py-1 text-sm font-medium capitalize">
        {student.student_type === "subscription" ? "Subscription" : "Non-Subscription"}
      </span>

      {/* Balance with tier emoji (emoji only for orange/red) */}
      <div className="flex items-center gap-2">
        {tierEmoji && <span className="text-4xl">{tierEmoji}</span>}
        <p className={cn("text-5xl font-extrabold", balanceColor)}>
          ₱{displayValue.toFixed(2)}
        </p>
      </div>

      {/* Tier message (orange/red only) */}
      {tierMessage && (
        <p className="text-center text-sm font-medium text-muted-foreground">
          {tierMessage}
        </p>
      )}

      {/* Last orders */}
      {student.last_orders.length > 0 && (
        <div className="w-full">
          <p className="mb-2 text-sm font-semibold uppercase tracking-wide text-muted-foreground">
            Last Orders
          </p>
          <ul className="divide-y divide-border">
            {student.last_orders.map((order, i) => (
              <li key={i} className="flex justify-between py-2 text-sm">
                <span className="text-foreground">{order.items}</span>
                <span className="ml-4 shrink-0 text-muted-foreground">
                  {order.date} · ₱{order.total}
                </span>
              </li>
            ))}
          </ul>
        </div>
      )}

      <button
        onClick={onReset}
        className="mt-2 text-sm text-muted-foreground underline-offset-2 hover:underline"
      >
        Scan another
      </button>
    </div>
  );
}

function ErrorCard() {
  return (
    <div
      className="z-10 flex w-full max-w-sm flex-col items-center gap-4 rounded-2xl bg-card p-8 text-center shadow-2xl"
      style={{ animation: "shake 0.4s ease-in-out" }}
    >
      <div className="flex h-16 w-16 items-center justify-center rounded-full bg-destructive/10 text-3xl">
        ✕
      </div>
      <p className="text-xl font-semibold">QR not recognized.</p>
      <p className="text-muted-foreground">Please see a cashier.</p>
    </div>
  );
}
```

- [ ] **Step 4: Run the tests — expect all to pass**

```bash
cd ~/sunbites-pos && npx jest "app/\(kiosk\)/kiosk/kiosk.test.tsx" --no-coverage
```

Expected: `14 passed, 14 total` (11 original + 3 new tier tests). If any test fails, read the failure message carefully — the most likely causes are:
- Emoji text matching: use `screen.getByText("😢")` (exact string)
- Balance text with count-up: use `await screen.findByText("₱245.00")` (async) not `getByText`
- Missing `jest.mock("canvas-confetti", () => jest.fn())` at the top of the test file

- [ ] **Step 5: Run the full test suite to check for regressions**

```bash
cd ~/sunbites-pos && npx jest --no-coverage 2>&1 | tail -10
```

Expected: All suites pass (should be 213+ tests passing, 0 failing).

- [ ] **Step 6: Commit**

```bash
cd ~/sunbites-pos && git add app/\(kiosk\)/kiosk/page.tsx app/\(kiosk\)/kiosk/kiosk.test.tsx && git commit -m "feat(kiosk): visual refresh — logo, animations, tier emojis, confetti, count-up"
```

---

## Self-Review Checklist

**Spec coverage:**
- ✅ Logo always visible (absolute top, z-20, inverted in scanning state)
- ✅ Logo `variant="full"` used
- ✅ Scanning state: scan guide with animated scan line (`scanLine` keyframe) + corner brackets + prompt text with `animate-pulse`
- ✅ Result: spring pop-in (`zoom-in-75 fade-in` + bounce `cubic-bezier`)
- ✅ Result: balance count-up (`useCountUp` with `requestAnimationFrame`)
- ✅ Result: friendly greeting `"Hi, {firstName}! 👋"` with `student.name.split(' ')[0]`
- ✅ Green (≥150): confetti burst, no ring, no emoji, no message
- ✅ Orange (80–149): 😢, orange ring, "Your balance is running low. Please top up soon!"
- ✅ Red (≤79): 😰, red ring, "Insufficient balance. Please top up before ordering."
- ✅ Error state: shake animation (`shake` keyframe via inline `style`)
- ✅ `canvas-confetti` mocked in tests
- ✅ 3 new tier tests added; 2 fixture updates (orange test fixture `"30.00"` → `"100.00"`, test name updated to "80–149")
- ✅ No backend changes, no new routes

**Potential gotcha — `ErrorCard` animation conflict:**
The `ErrorCard` uses `style={{ animation: "shake 0.4s ease-in-out" }}` instead of `animate-in fade-in`. This is intentional: the `style` prop sets `animation` directly, and mixing it with `animate-in` (which also sets `animation` via CSS) would cause one to override the other. The shake animation makes the card visually appear and shake simultaneously — no separate fade-in is needed.
