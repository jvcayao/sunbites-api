# Kiosk Camera-Optional Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the kiosk work with hardware QR scanners even when camera is unavailable or denied, replacing the blocking camera-error screen with a functional scan guide + optional "Use your camera" button.

**Architecture:** Two targeted changes — split `isEnabled` in `useKioskScanner` into `isCameraEnabled`/`isKeyboardEnabled` so camera failure never silences the hardware scanner (Task 1), then update `KioskPage` to render a white-background scan guide when camera is blocked instead of the blocking error UI (Task 2).

**Tech Stack:** Next.js 15 App Router, React 19, @zxing/browser v0.2.x, Jest 30 + React Testing Library + MSW 2

## Global Constraints

- Project root: `~/sunbites-pos`
- Do NOT install new packages
- No backend changes
- `isKeyboardEnabled` = `state === "scanning"` — never gated on `cameraBlocked`
- `isCameraEnabled` = `state === "scanning" && !cameraBlocked`
- Video hidden condition: `(!isScanningState || cameraBlocked) && "hidden"`
- Scan line condition: `state === "scanning" && !cameraBlocked`
- "Use camera" button text: `📷 Use your camera to scan QR`
- "Use camera" button visible only when: `state === "scanning" && cameraBlocked`
- Camera-blocked scan guide: `border-border`, no vignette shadow, corner brackets `border-border`, outer text `text-foreground`
- Logo invert condition: unchanged — `isScanningState && !cameraBlocked`

---

### Task 1: Split hook prop — hardware scanner independent of camera

**Files:**
- Modify: `hooks/use-kiosk-scanner.ts`
- Modify: `app/(kiosk)/kiosk/page.tsx` (call site only — prop rename)
- Test: `app/(kiosk)/kiosk/kiosk.test.tsx`

**Interfaces:**
- Consumes: nothing from prior tasks
- Produces: `useKioskScanner({ isCameraEnabled: boolean, isKeyboardEnabled: boolean, ... })` — consumed by Task 2

- [ ] **Step 1: Add `simulateKeyboardScan` helper and write the failing test**

  Open `app/(kiosk)/kiosk/kiosk.test.tsx`. Add the `simulateKeyboardScan` helper just below `simulateScan`:

  ```tsx
  const simulateKeyboardScan = (code: string) => {
    act(() => {
      for (const char of code) {
        window.dispatchEvent(new KeyboardEvent("keydown", { key: char }));
      }
      window.dispatchEvent(new KeyboardEvent("keydown", { key: "Enter" }));
    });
  };
  ```

  Then add this test inside the `describe("KioskPage")` block, before the existing "ignores QR codes" test:

  ```tsx
  it("hardware scanner works when camera is blocked", async () => {
    const { BrowserMultiFormatReader } = await import("@zxing/browser");
    (BrowserMultiFormatReader as unknown as jest.Mock).mockImplementationOnce(
      () => ({
        decodeFromVideoDevice: jest
          .fn()
          .mockRejectedValueOnce(
            new DOMException("Permission denied", "NotAllowedError"),
          ),
      }),
    );

    render(<KioskPage />);

    // Wait for camera to fail and cameraBlocked to be set
    await screen.findByText(/camera access required/i);

    // Simulate hardware QR scanner via keyboard input
    simulateKeyboardScan("SB-testqrcode1234");

    // Student card should appear — keyboard scanner was not silenced by camera failure
    expect(await screen.findByText("Juan Dela Cruz")).toBeInTheDocument();
  });
  ```

- [ ] **Step 2: Run the test to confirm it fails**

  ```bash
  cd ~/sunbites-pos && npx jest --testPathPattern="kiosk.test" --no-coverage --testNamePattern="hardware scanner works when camera is blocked"
  ```

  Expected: `FAIL` — the student card does not appear because the keyboard scanner is currently gated on `isEnabled: state === "scanning" && !cameraBlocked`, so it is silenced when `cameraBlocked` is `true`.

- [ ] **Step 3: Update `hooks/use-kiosk-scanner.ts` — split `isEnabled` into two props**

  Replace the entire file with:

  ```typescript
  "use client";

  import { useEffect, useLayoutEffect, useRef } from "react";

  import {
    BrowserMultiFormatReader,
    type IScannerControls,
  } from "@zxing/browser";

  interface UseKioskScannerProps {
    videoRef: React.RefObject<HTMLVideoElement | null>;
    onScan: (code: string) => void;
    onCameraError: () => void;
    isCameraEnabled: boolean;
    isKeyboardEnabled: boolean;
  }

  export function useKioskScanner({
    videoRef,
    onScan,
    onCameraError,
    isCameraEnabled,
    isKeyboardEnabled,
  }: UseKioskScannerProps): void {
    const isLockedRef = useRef(false);

    // Refs keep callbacks fresh without re-triggering scanner effects.
    // useLayoutEffect syncs before camera/keyboard effects read the refs.
    const onScanRef = useRef(onScan);
    const onCameraErrorRef = useRef(onCameraError);
    useLayoutEffect(() => {
      onScanRef.current = onScan;
      onCameraErrorRef.current = onCameraError;
    });

    // Camera-based scanning via @zxing/browser (phone/tablet)
    useEffect(() => {
      if (!isCameraEnabled || !videoRef.current) return;

      let cancelled = false;
      let controls: IScannerControls | null = null;

      const reader = new BrowserMultiFormatReader();

      reader
        .decodeFromVideoDevice(undefined, videoRef.current, (result) => {
          if (!result || isLockedRef.current) return;

          const text = result.getText();
          if (!text.startsWith("SB-")) return;

          isLockedRef.current = true;
          onScanRef.current(text);

          setTimeout(() => {
            isLockedRef.current = false;
          }, 1000);
        })
        .then((ctrl) => {
          if (cancelled) {
            ctrl.stop();
          } else {
            controls = ctrl;
          }
        })
        .catch(() => {
          // The `cancelled` flag filters Strict Mode double-invoke races.
          // Any remaining error is a real device failure — notify the parent.
          if (!cancelled) {
            onCameraErrorRef.current();
          }
        });

      return () => {
        cancelled = true;
        controls?.stop();
      };
    }, [isCameraEnabled, videoRef]);

    // Hardware QR scanner support (USB/Bluetooth scanners that emulate keyboard input).
    // These devices send the QR code as rapid keystrokes followed by Enter.
    // We buffer characters and fire onScan when Enter arrives with a valid SB- code.
    useEffect(() => {
      if (!isKeyboardEnabled) return;

      let buffer = "";

      const handleKeydown = (e: KeyboardEvent) => {
        if (isLockedRef.current) return;

        if (e.key === "Enter") {
          const code = buffer.trim();
          buffer = "";
          if (code.startsWith("SB-")) {
            isLockedRef.current = true;
            onScanRef.current(code);
            setTimeout(() => {
              isLockedRef.current = false;
            }, 1000);
          }
        } else if (e.key.length === 1) {
          buffer += e.key;
        }
      };

      window.addEventListener("keydown", handleKeydown);

      return () => {
        window.removeEventListener("keydown", handleKeydown);
      };
    }, [isKeyboardEnabled]);
  }
  ```

- [ ] **Step 4: Update the call site in `app/(kiosk)/kiosk/page.tsx`**

  Find the existing `useKioskScanner` call (around line 53):

  ```tsx
  useKioskScanner({
    videoRef,
    onScan: handleScan,
    onCameraError: handleCameraError,
    isEnabled: state === "scanning" && !cameraBlocked,
  });
  ```

  Replace it with:

  ```tsx
  useKioskScanner({
    videoRef,
    onScan: handleScan,
    onCameraError: handleCameraError,
    isCameraEnabled: state === "scanning" && !cameraBlocked,
    isKeyboardEnabled: state === "scanning",
  });
  ```

- [ ] **Step 5: Run the tests — all 15 should pass**

  ```bash
  cd ~/sunbites-pos && npx jest --testPathPattern="kiosk.test" --no-coverage
  ```

  Expected output:
  ```
  PASS app/(kiosk)/kiosk/kiosk.test.tsx
    KioskPage
      ✓ shows the scan prompt on initial load
      ✓ shows the student result card after a successful scan
      ✓ shows green balance color for amount >= 150
      ✓ shows orange balance for amount between 80 and 149
      ✓ shows red balance for amount <= 79
      ✓ shows the same error card for 404
      ✓ shows the same error card for 403 (restricted student)
      ✓ auto-resets to scan state after 10 seconds on result
      ✓ auto-resets to scan state after 5 seconds on error
      ✓ shows camera access required message when camera is denied
      ✓ hardware scanner works when camera is blocked
      ✓ ignores QR codes that do not start with SB-
      ✓ shows no tier message for green balance (>= 150)
      ✓ shows sad emoji and running low message for orange balance (80-149)
      ✓ shows worried emoji and insufficient balance message for red balance (<= 79)

  Tests: 15 passed, 15 total
  ```

- [ ] **Step 6: Run type-check**

  ```bash
  cd ~/sunbites-pos && npm run type-check
  ```

  Expected: no errors.

- [ ] **Step 7: Commit**

  ```bash
  cd ~/sunbites-pos && git add hooks/use-kiosk-scanner.ts app/\(kiosk\)/kiosk/page.tsx app/\(kiosk\)/kiosk/kiosk.test.tsx && git commit -m "feat(kiosk): split isEnabled into isCameraEnabled + isKeyboardEnabled so hardware scanner is independent of camera state"
  ```

---

### Task 2: Camera-blocked UI overhaul

**Files:**
- Modify: `app/(kiosk)/kiosk/page.tsx`
- Test: `app/(kiosk)/kiosk/kiosk.test.tsx`

**Interfaces:**
- Consumes: `isCameraEnabled` / `isKeyboardEnabled` interface from Task 1
- Produces: final camera-optional kiosk UI

- [ ] **Step 1: Write the 3 new/updated tests (all will fail)**

  In `app/(kiosk)/kiosk/kiosk.test.tsx`:

  **1a. Update the existing test** "shows camera access required message when camera is denied" — change its name and assertion to match the new UI (button instead of blocking text):

  ```tsx
  it("shows 'Use your camera' button when camera is denied", async () => {
    const { BrowserMultiFormatReader } = await import("@zxing/browser");
    (BrowserMultiFormatReader as unknown as jest.Mock).mockImplementationOnce(
      () => ({
        decodeFromVideoDevice: jest
          .fn()
          .mockRejectedValueOnce(
            new DOMException("Permission denied", "NotAllowedError"),
          ),
      }),
    );

    render(<KioskPage />);

    expect(
      await screen.findByRole("button", { name: /use your camera/i }),
    ).toBeInTheDocument();
    // Scan prompt remains — kiosk is still functional for hardware scanners
    expect(screen.getByText(/scan your id card/i)).toBeInTheDocument();
  });
  ```

  **1b. Add new test** for retry behavior (place after the above):

  ```tsx
  it("'Use your camera' button re-triggers camera access on click", async () => {
    const { BrowserMultiFormatReader } = await import("@zxing/browser");
    const constructorMock = BrowserMultiFormatReader as unknown as jest.Mock;
    constructorMock.mockImplementation(() => ({
      decodeFromVideoDevice: jest
        .fn()
        .mockRejectedValue(
          new DOMException("Permission denied", "NotAllowedError"),
        ),
    }));

    render(<KioskPage />);

    // Wait for first camera failure — button appears
    const retryButton = await screen.findByRole("button", {
      name: /use your camera/i,
    });
    const callCountAfterInit = constructorMock.mock.calls.length;

    // Click retry
    act(() => {
      retryButton.click();
    });

    // Camera effect re-runs — BrowserMultiFormatReader constructor is called again
    await screen.findByRole("button", { name: /use your camera/i });
    expect(constructorMock.mock.calls.length).toBeGreaterThan(callCountAfterInit);
  });
  ```

  **1c. Add new test** for scan guide remaining visible (place after the above):

  ```tsx
  it("scan guide remains visible and video is hidden when camera is blocked", async () => {
    const { BrowserMultiFormatReader } = await import("@zxing/browser");
    (BrowserMultiFormatReader as unknown as jest.Mock).mockImplementationOnce(
      () => ({
        decodeFromVideoDevice: jest
          .fn()
          .mockRejectedValueOnce(
            new DOMException("Permission denied", "NotAllowedError"),
          ),
      }),
    );

    const { container } = render(<KioskPage />);

    // Wait for camera to fail
    await screen.findByRole("button", { name: /use your camera/i });

    // Scan guide is visible — hardware scanner can still be used
    expect(screen.getByText(/scan your id card/i)).toBeInTheDocument();

    // Video element has the hidden class in camera-blocked mode
    const video = container.querySelector("video");
    expect(video).toHaveClass("hidden");
  });
  ```

- [ ] **Step 2: Run the 3 new/updated tests to confirm they fail**

  ```bash
  cd ~/sunbites-pos && npx jest --testPathPattern="kiosk.test" --no-coverage --testNamePattern="Use your camera|retry|scan guide remains"
  ```

  Expected: all 3 `FAIL` — the button doesn't exist yet; "camera access required" text is shown instead; scan guide is hidden when camera blocked.

- [ ] **Step 3: Implement the camera-blocked UI in `app/(kiosk)/kiosk/page.tsx`**

  Replace the entire `KioskPage` function (keep `useCountUp`, `getBalanceTier`, `StudentCard`, and `ErrorCard` unchanged). The new `KioskPage`:

  ```tsx
  export default function KioskPage() {
    const videoRef = useRef<HTMLVideoElement>(null);
    const { state, student, handleScan, reset } = useKioskLookup();
    const [cameraBlocked, setCameraBlocked] = useState(false);

    const handleCameraError = useCallback(() => setCameraBlocked(true), []);
    const handleCameraRetry = useCallback(() => setCameraBlocked(false), []);

    const isScanningState = state === "scanning" || state === "loading";

    useKioskScanner({
      videoRef,
      onScan: handleScan,
      onCameraError: handleCameraError,
      isCameraEnabled: state === "scanning" && !cameraBlocked,
      isKeyboardEnabled: state === "scanning",
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
        {/* Camera viewfinder — hidden when not scanning or camera is blocked */}
        <video
          ref={videoRef}
          className={cn(
            "absolute inset-0 h-full w-full object-cover",
            (!isScanningState || cameraBlocked) && "hidden",
          )}
          muted
          playsInline
        />

        {/* Logo — inverted (white) only when camera is actively streaming */}
        <div
          className={cn(
            "absolute top-8 left-1/2 z-20 -translate-x-1/2",
            isScanningState && !cameraBlocked && "invert",
          )}
        >
          <AppLogo variant="full" />
        </div>

        {/* Scanning state — shown regardless of camera availability */}
        {isScanningState && (
          <div
            className={cn(
              "relative z-10 flex flex-col items-center gap-6",
              cameraBlocked ? "text-foreground" : "text-white",
            )}
          >
            {/* Scan guide frame */}
            <div
              className={cn(
                "relative h-64 w-64 overflow-hidden rounded-2xl border-4",
                cameraBlocked
                  ? "border-border"
                  : "border-white/80 shadow-[0_0_0_9999px_rgba(0,0,0,0.5)]",
              )}
            >
              {/* Corner bracket accents */}
              <div className="pointer-events-none absolute inset-0">
                <div
                  className={cn(
                    "absolute left-0 top-0 h-6 w-6 border-l-4 border-t-4",
                    cameraBlocked ? "border-border" : "border-white",
                  )}
                />
                <div
                  className={cn(
                    "absolute right-0 top-0 h-6 w-6 border-r-4 border-t-4",
                    cameraBlocked ? "border-border" : "border-white",
                  )}
                />
                <div
                  className={cn(
                    "absolute bottom-0 left-0 h-6 w-6 border-b-4 border-l-4",
                    cameraBlocked ? "border-border" : "border-white",
                  )}
                />
                <div
                  className={cn(
                    "absolute bottom-0 right-0 h-6 w-6 border-b-4 border-r-4",
                    cameraBlocked ? "border-border" : "border-white",
                  )}
                />
              </div>

              {/* Animated scan line — only when scanning with active camera */}
              {state === "scanning" && !cameraBlocked && (
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

            <p
              className={cn(
                "text-xl font-medium tracking-wide",
                state === "scanning" && "animate-pulse",
              )}
            >
              {state === "loading" ? "Checking..." : "Scan your ID card"}
            </p>

            {/* Camera retry — visible only when scanning and camera is blocked */}
            {state === "scanning" && cameraBlocked && (
              <button
                onClick={handleCameraRetry}
                className="rounded-lg border border-border px-4 py-2 text-sm text-muted-foreground hover:bg-accent"
              >
                📷 Use your camera to scan QR
              </button>
            )}
          </div>
        )}

        {/* Result state */}
        {state === "result" && student && (
          <StudentCard student={student} onReset={reset} />
        )}

        {/* Error state */}
        {state === "error" && <ErrorCard />}
      </div>
    );
  }
  ```

- [ ] **Step 4: Run all 18 tests to confirm they pass**

  ```bash
  cd ~/sunbites-pos && npx jest --testPathPattern="kiosk.test" --no-coverage
  ```

  Expected output:
  ```
  PASS app/(kiosk)/kiosk/kiosk.test.tsx
    KioskPage
      ✓ shows the scan prompt on initial load
      ✓ shows the student result card after a successful scan
      ✓ shows green balance color for amount >= 150
      ✓ shows orange balance for amount between 80 and 149
      ✓ shows red balance for amount <= 79
      ✓ shows the same error card for 404
      ✓ shows the same error card for 403 (restricted student)
      ✓ auto-resets to scan state after 10 seconds on result
      ✓ auto-resets to scan state after 5 seconds on error
      ✓ shows 'Use your camera' button when camera is denied
      ✓ hardware scanner works when camera is blocked
      ✓ 'Use your camera' button re-triggers camera access on click
      ✓ scan guide remains visible and video is hidden when camera is blocked
      ✓ ignores QR codes that do not start with SB-
      ✓ shows no tier message for green balance (>= 150)
      ✓ shows sad emoji and running low message for orange balance (80-149)
      ✓ shows worried emoji and insufficient balance message for red balance (<= 79)

  Tests: 17 passed, 17 total
  ```

  (The test count increases from 15 to 17: the "camera access required" test was renamed and updated in-place, and 2 new tests were added.)

- [ ] **Step 5: Run lint and type-check**

  ```bash
  cd ~/sunbites-pos && npm run quality:validate
  ```

  Expected: no errors.

- [ ] **Step 6: Run Pint on backend (unchanged — no backend files touched)**

  Skip. No PHP files were modified.

- [ ] **Step 7: Commit**

  ```bash
  cd ~/sunbites-pos && git add app/\(kiosk\)/kiosk/page.tsx app/\(kiosk\)/kiosk/kiosk.test.tsx && git commit -m "feat(kiosk): make camera optional — scan guide shows on white bg when camera blocked, 'Use camera' button for retry"
  ```
