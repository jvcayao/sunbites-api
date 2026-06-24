# Kiosk Camera-Optional Design Spec

**Date:** 2026-06-24
**Project:** sunbites-pos
**Status:** Approved
**Files to modify:** `hooks/use-kiosk-scanner.ts`, `app/(kiosk)/kiosk/page.tsx`

---

## Problem

The kiosk page currently treats camera failure as a fatal error. When the camera is unavailable or permission is denied, `cameraBlocked` becomes `true`, which disables the `isEnabled` prop passed to `useKioskScanner`. Both the camera effect and the keyboard scanner effect check `if (!isEnabled) return` — so the hardware QR scanner is silenced too, leaving the kiosk completely non-functional even though the hardware scanner would work fine.

The hardware QR device (USB/Bluetooth, keyboard-emulation mode) is the **primary** scan input. Camera is **optional** — useful for students who have their QR code on a phone, but never required for kiosk operation.

---

## Solution Overview

Two targeted changes, no backend work:

1. **`useKioskScanner`** — split the single `isEnabled` prop into two independent props: `isCameraEnabled` (gated on camera state) and `isKeyboardEnabled` (always active while scanning). Camera failure no longer affects the keyboard scanner.

2. **`KioskPage`** — remove the blocking "Camera access required" error UI. Replace it with a small "Use your camera to scan QR" button that appears when the camera is not active. Clicking it retries camera access. The scan guide frame stays visible on a white background (no video feed) in camera-blocked mode.

---

## Interface Changes

### `useKioskScanner` props (before → after)

**Before:**
```typescript
interface UseKioskScannerProps {
  videoRef: React.RefObject<HTMLVideoElement | null>;
  onScan: (code: string) => void;
  onCameraError: () => void;
  isEnabled: boolean;  // gates both camera and keyboard
}
```

**After:**
```typescript
interface UseKioskScannerProps {
  videoRef: React.RefObject<HTMLVideoElement | null>;
  onScan: (code: string) => void;
  onCameraError: () => void;
  isCameraEnabled: boolean;   // gates camera effect only
  isKeyboardEnabled: boolean; // gates keyboard scanner effect only
}
```

### `useKioskScanner` call site (in `KioskPage`)

**Before:**
```tsx
useKioskScanner({
  videoRef,
  onScan: handleScan,
  onCameraError: handleCameraError,
  isEnabled: state === "scanning" && !cameraBlocked,
});
```

**After:**
```tsx
useKioskScanner({
  videoRef,
  onScan: handleScan,
  onCameraError: handleCameraError,
  isCameraEnabled: state === "scanning" && !cameraBlocked,
  isKeyboardEnabled: state === "scanning",
});
```

---

## UI States

### Scanning — camera active (unchanged)
- Full-screen video background
- Scan guide frame with animated scan line
- "Scan your ID card" prompt with `animate-pulse`
- Logo inverted (white) over dark camera overlay
- No camera button shown

### Scanning — camera blocked (new)
- White/default background (`bg-background`); video element hidden
- Overlay text color: `text-foreground` (not `text-white` — light background)
- Scan guide frame — **no vignette shadow**, `border-border`, corner brackets `border-border`
- Scan guide is static — **no scan line** (condition: `state === "scanning" && !cameraBlocked`)
- "Scan your ID card" prompt (same text, same `animate-pulse`)
- Logo in brand colors (not inverted — light background)
- Small ghost button below prompt: **"📷 Use your camera to scan QR"** (visible only when `state === "scanning"`)
- Hardware QR scanner is fully active in this state

### Loading — camera blocked
- Same as camera-blocked scanning, **but**:
  - Spinner shown inside scan guide frame (unchanged from camera-active loading)
  - Prompt shows "Checking..." (not "Scan your ID card")
  - **"Use camera" button is hidden** — user is mid-scan, no action needed
- Hardware scanner lock (`isLockedRef`) prevents double-fire during loading

### "Use your camera" button behavior
- Clicking calls `handleCameraRetry()`: sets `cameraBlocked = false`
- This makes `isCameraEnabled` true → camera effect re-runs
- If camera permission is granted → UI transitions to camera-active state
- If camera is still denied → `onCameraError` fires again → `cameraBlocked` returns to `true` → button reappears silently
- No error message shown on retry failure — kiosk stays functional with hardware scanner

### All other states (loading, result, error, camera-blocked-but-result/error)
- Unchanged — camera state does not affect result/error card display

---

## Layout: Camera-Blocked Scanning State

```
┌──────────────────────────────────┐
│   🔴 Sunbites (brand colors)     │  ← AppLogo variant="full", no invert
│   Your Healthy Kitchen           │
│                                  │
│  ┌──────────────────────────┐    │
│  │ ·                      · │    │  ← scan guide frame (static, white bg inside)
│  │                          │    │     no scan line, corner brackets still visible
│  │                          │    │
│  │ ·                      · │    │
│  └──────────────────────────┘    │
│                                  │
│   Scan your ID card              │  ← animate-pulse, same as camera mode
│                                  │
│  [ 📷 Use your camera ]          │  ← ghost/outline button, muted color
└──────────────────────────────────┘
```

Styling differences from camera-active mode:
- **Border:** `border-border` (not `border-white/80`)
- **Corner brackets:** `border-border` (not `border-white`)
- **Shadow/vignette:** none (not `shadow-[0_0_0_9999px_rgba(0,0,0,0.5)]` — that shadow dims the background, which would look broken on white)
- **Text color:** `text-foreground` (not `text-white` — text is invisible on a white background)
- **No scan line inside the frame**
- Inside the frame: plain white/transparent — no camera stream

---

## Scan Guide — Camera vs No Camera

| Element | Camera active | Camera blocked |
|---|---|---|
| Video `<video>` | Visible, full-screen | `hidden` (condition: `!isScanningState \|\| cameraBlocked`) |
| Overlay text color | `text-white` (dark vignette bg) | `text-foreground` (light bg) |
| Page background | Dark (vignette from video) | Default (`bg-background`) |
| Scan guide shadow | `shadow-[0_0_0_9999px_rgba(0,0,0,0.5)]` | **Removed** — looks broken on white bg |
| Scan guide border | `border-white/80` | `border-border` |
| Corner brackets | `border-white` | `border-border` |
| Logo | `invert` (white) | Brand colors (no invert) |
| Scan line condition | `state === "scanning"` | Hidden — condition: `state === "scanning" && !cameraBlocked` |
| Loading spinner | Yes | Yes — still shows during API call |
| "Checking..." text | Yes | Yes — still shows during API call |
| "Use camera" button | Hidden | Visible only when `state === "scanning"` — hidden during loading |
| Prompt pulse | Yes | Yes |

---

## Video Element Hidden Condition

**Before:**
```tsx
className={cn("absolute inset-0 h-full w-full object-cover", !isScanningState && "hidden")}
```

**After:**
```tsx
className={cn("absolute inset-0 h-full w-full object-cover", (!isScanningState || cameraBlocked) && "hidden")}
```

When camera is blocked, the empty `<video>` element is hidden (it's transparent but still overlays the white background). When the user clicks "Use camera" and `cameraBlocked` resets to `false`, the video becomes visible again immediately as the camera stream attaches.

---

## Removed UI

The current "Camera access required" blocking state is removed entirely:

```tsx
// REMOVED:
{cameraBlocked && (
  <div className="z-10 flex flex-col items-center gap-3 text-center">
    <p className="text-xl font-semibold">Camera access required.</p>
    <p className="text-muted-foreground">Please allow camera and refresh.</p>
  </div>
)}
```

This is replaced by the subtle "Use your camera" button embedded in the normal scanning UI.

---

## `handleCameraRetry` logic

```tsx
const handleCameraRetry = useCallback(() => {
  setCameraBlocked(false);
}, []);
```

Resetting `cameraBlocked` to `false` makes `isCameraEnabled` true again, which triggers the camera `useEffect` to re-run (it depends on `isCameraEnabled`). If the camera fails again, `onCameraError` fires and `cameraBlocked` returns to `true`.

---

## Files Changed

| File | Change |
|---|---|
| `hooks/use-kiosk-scanner.ts` | Replace `isEnabled` with `isCameraEnabled` + `isKeyboardEnabled` |
| `app/(kiosk)/kiosk/page.tsx` | Update call site, remove blocking error UI, add "Use camera" button, adjust scan guide styling for camera-blocked mode |

---

## Testing

Existing 14 tests in `kiosk.test.tsx` remain passing with prop rename (update mock accordingly).

New tests to add:
1. **Hardware scanner works when camera is blocked** — mock camera to fail, simulate hardware scan → should show student card (the critical regression test)
2. **"Use camera" button appears when camera is blocked** — mock camera to fail → assert button is in the DOM
3. **"Use camera" button retries camera access** — mock camera fail → click button → assert camera effect re-runs (mock shows 2 constructor calls)
4. **Scan guide shows without video in camera-blocked mode** — camera fails → scan frame is visible, video element is hidden

---

## Out of Scope

- Detecting camera absence before attempting (navigator.mediaDevices enumeration) — overkill; the current try/fail approach is simpler and works
- Persistent "scanner only" mode via URL param or localStorage — YAGNI
- Any backend changes
