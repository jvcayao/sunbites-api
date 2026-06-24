# Kiosk Balance Check Mode Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a public, unauthenticated `/kiosk` page in sunbites-pos where students scan their QR ID card with a phone camera to see their wallet balance and last 5 orders — no login, no manual input.

**Architecture:** Two independent pieces — (1) a new Laravel public API endpoint that looks up a student by QR code globally (no branch context) and returns safe read-only data, and (2) a new Next.js route group in sunbites-pos with a fullscreen camera scanning page that calls that endpoint. The kiosk page uses `@zxing/browser` for real-time camera QR detection and plain `fetch` (not the authenticated apiClient) since there is no staff session.

**Tech Stack:** PHP 8.5 / Laravel 13 / PHPUnit 12 (API) · Next.js App Router / React 19 / @zxing/browser / TanStack Query / Tailwind v4 / shadcn/ui / MSW 2 / Jest 30 (frontend)

## Global Constraints

- All Laravel commands run through Sail: `vendor/bin/sail artisan ...`
- Run `vendor/bin/sail bin pint --dirty --format agent` after every PHP file change
- Run `vendor/bin/sail artisan test --compact` before each PHP commit
- All Next.js commands run in `~/sunbites-pos`
- No auth guard on the kiosk page — it lives outside `(kitchen)` and `(pos)` route groups
- No `apiClient` in `lib/api/kiosk.ts` — use plain `fetch` (the authenticated client attaches a token the kiosk does not have)
- QR codes are globally unique (`SB-` + 12 chars); no branch parameter needed
- Only `EnrollmentStatus::Enrolled` students may retrieve data; `Paused`, `Unenrolled`, `Banned`, and `Graduated` all return 403
- Frontend shows identical "Please see a cashier." card for 403 and 404 — no differentiation
- `Order` model is branch-scoped — use `Order::withoutBranch()` in the controller; do NOT use `$student->orders()` which would apply BranchScope and fail with no active branch

---

## File Map

### sunbites-api (Laravel)
| Action | Path | Responsibility |
|---|---|---|
| Create | `app/Http/Requests/Public/KioskLookupRequest.php` | Validates `qr_code` format |
| Create | `app/Http/Controllers/Public/KioskLookupController.php` | Handles lookup, status check, response |
| Modify | `routes/api.php` | Registers route in public group |
| Create | `tests/Feature/Public/KioskLookupTest.php` | All API tests |

### sunbites-pos (Next.js)
| Action | Path | Responsibility |
|---|---|---|
| Create | `types/kiosk.ts` | `KioskStudent` and `KioskOrder` types |
| Create | `lib/api/kiosk.ts` | Plain fetch wrapper for public kiosk endpoint |
| Create | `hooks/use-kiosk-scanner.ts` | @zxing/browser camera scanning hook |
| Create | `hooks/use-kiosk-lookup.ts` | State machine: scanning → loading → result/error |
| Create | `app/(kiosk)/layout.tsx` | Fullscreen layout, no nav |
| Create | `app/(kiosk)/kiosk/page.tsx` | Kiosk Client Component |
| Create | `app/(kiosk)/kiosk/loading.tsx` | Blank screen during initial load |
| Create | `app/(kiosk)/kiosk/kiosk.test.tsx` | Frontend tests |

---

## Task 1: Laravel API — Public kiosk lookup endpoint

**Files:**
- Create: `app/Http/Requests/Public/KioskLookupRequest.php`
- Create: `app/Http/Controllers/Public/KioskLookupController.php`
- Modify: `routes/api.php`
- Create: `tests/Feature/Public/KioskLookupTest.php`

**Interfaces:**
- Produces: `POST /api/v1/public/kiosk/lookup` accepting `{ qr_code: string }`, returning `KioskStudentResponse` (see response shape below)

- [ ] **Step 1: Create the test file**

```bash
cd ~/sunbites-api && vendor/bin/sail artisan make:test --phpunit tests/Feature/Public/KioskLookupTest
```

- [ ] **Step 2: Write the failing tests**

Replace the contents of `tests/Feature/Public/KioskLookupTest.php`:

```php
<?php

namespace Tests\Feature\Public;

use App\Enums\EnrollmentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KioskLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_enrolled_student_gets_balance_and_orders(): void
    {
        $student = Student::factory()->create([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'grade_level' => 'Grade 3',
            'enrollment_status' => EnrollmentStatus::Enrolled,
            'qr_code' => 'SB-testqrcode1234',
        ]);

        $student->deposit(20000); // ₱200.00

        $order = Order::factory()->create(['student_id' => $student->id]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'name' => 'Rice Meal',
            'line_total' => 5500,
        ]);

        $response = $this->postJson('/api/v1/public/kiosk/lookup', [
            'qr_code' => 'SB-testqrcode1234',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'name', 'initials', 'grade_level', 'student_type', 'balance', 'last_orders',
            ])
            ->assertJson([
                'name' => 'Juan Dela Cruz',
                'initials' => 'JD',
                'grade_level' => 'Grade 3',
                'balance' => '200.00',
            ]);
    }

    public function test_last_orders_are_capped_at_five(): void
    {
        $student = Student::factory()->create([
            'enrollment_status' => EnrollmentStatus::Enrolled,
            'qr_code' => 'SB-captest1234567',
        ]);

        Order::factory(7)->create(['student_id' => $student->id])->each(function ($order) {
            OrderItem::factory()->create(['order_id' => $order->id]);
        });

        $response = $this->postJson('/api/v1/public/kiosk/lookup', [
            'qr_code' => 'SB-captest1234567',
        ]);

        $response->assertOk();
        $this->assertCount(5, $response->json('last_orders'));
    }

    public function test_returns_404_for_unknown_qr_code(): void
    {
        $response = $this->postJson('/api/v1/public/kiosk/lookup', [
            'qr_code' => 'SB-doesnotexist12',
        ]);

        $response->assertNotFound()
            ->assertJson(['message' => 'Student not found.']);
    }

    public function test_paused_student_returns_403(): void
    {
        Student::factory()->create([
            'enrollment_status' => EnrollmentStatus::Paused,
            'qr_code' => 'SB-pausedstudent12',
        ]);

        $this->postJson('/api/v1/public/kiosk/lookup', ['qr_code' => 'SB-pausedstudent12'])
            ->assertForbidden()
            ->assertJson(['message' => 'Student is not eligible.']);
    }

    public function test_unenrolled_student_returns_403(): void
    {
        Student::factory()->create([
            'enrollment_status' => EnrollmentStatus::Unenrolled,
            'qr_code' => 'SB-unenrolledst12',
        ]);

        $this->postJson('/api/v1/public/kiosk/lookup', ['qr_code' => 'SB-unenrolledst12'])
            ->assertForbidden();
    }

    public function test_banned_student_returns_403(): void
    {
        Student::factory()->create([
            'enrollment_status' => EnrollmentStatus::Banned,
            'qr_code' => 'SB-bannedstudent12',
        ]);

        $this->postJson('/api/v1/public/kiosk/lookup', ['qr_code' => 'SB-bannedstudent12'])
            ->assertForbidden();
    }

    public function test_graduated_student_returns_403(): void
    {
        Student::factory()->create([
            'enrollment_status' => EnrollmentStatus::Graduated,
            'qr_code' => 'SB-graduatedst1234',
        ]);

        $this->postJson('/api/v1/public/kiosk/lookup', ['qr_code' => 'SB-graduatedst1234'])
            ->assertForbidden();
    }

    public function test_rejects_qr_without_sb_prefix(): void
    {
        $this->postJson('/api/v1/public/kiosk/lookup', ['qr_code' => 'INVALID-123'])
            ->assertUnprocessable();
    }

    public function test_rejects_missing_qr_code(): void
    {
        $this->postJson('/api/v1/public/kiosk/lookup', [])
            ->assertUnprocessable();
    }

    public function test_sensitive_fields_are_excluded_from_response(): void
    {
        $student = Student::factory()->create([
            'enrollment_status' => EnrollmentStatus::Enrolled,
            'qr_code' => 'SB-sensitivetest12',
        ]);

        $response = $this->postJson('/api/v1/public/kiosk/lookup', [
            'qr_code' => 'SB-sensitivetest12',
        ]);

        $response->assertOk();

        $json = $response->json();
        $this->assertArrayNotHasKey('id', $json);
        $this->assertArrayNotHasKey('qr_code', $json);
        $this->assertArrayNotHasKey('student_number', $json);
        $this->assertArrayNotHasKey('photo_url', $json);
        $this->assertArrayNotHasKey('photo_path', $json);
    }
}
```

- [ ] **Step 3: Run tests to confirm they fail (not-found errors are expected)**

```bash
cd ~/sunbites-api && vendor/bin/sail artisan test --compact tests/Feature/Public/KioskLookupTest.php
```

Expected: all tests FAIL (route does not exist yet)

- [ ] **Step 4: Create the Form Request**

```bash
vendor/bin/sail artisan make:request Public/KioskLookupRequest --no-interaction
```

Replace the contents of `app/Http/Requests/Public/KioskLookupRequest.php`:

```php
<?php

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

class KioskLookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'qr_code' => ['required', 'string', 'starts_with:SB-'],
        ];
    }
}
```

- [ ] **Step 5: Create the Controller**

```bash
vendor/bin/sail artisan make:class app/Http/Controllers/Public/KioskLookupController --no-interaction
```

Replace the contents of `app/Http/Controllers/Public/KioskLookupController.php`:

```php
<?php

namespace App\Http\Controllers\Public;

use App\Enums\EnrollmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\KioskLookupRequest;
use App\Models\Order;
use App\Models\Student;
use Illuminate\Http\JsonResponse;

class KioskLookupController extends Controller
{
    public function lookup(KioskLookupRequest $request): JsonResponse
    {
        $student = Student::withoutBranch()
            ->with('wallet')
            ->where('qr_code', $request->validated('qr_code'))
            ->first();

        if (! $student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        if ($student->enrollment_status !== EnrollmentStatus::Enrolled) {
            return response()->json(['message' => 'Student is not eligible.'], 403);
        }

        $lastOrders = Order::withoutBranch()
            ->where('student_id', $student->id)
            ->with('items')
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (Order $order) => [
                'items' => $order->items->pluck('name')->join(', '),
                'total' => number_format($order->items->sum('line_total'), 2),
                'date' => $order->created_at->format('M j, Y'),
            ]);

        return response()->json([
            'name' => $student->first_name.' '.$student->last_name,
            'initials' => mb_strtoupper(mb_substr($student->first_name, 0, 1).mb_substr($student->last_name, 0, 1)),
            'grade_level' => $student->grade_level,
            'student_type' => $student->student_type->value,
            'balance' => number_format($student->wallet?->balanceFloatNum ?? 0.0, 2),
            'last_orders' => $lastOrders,
        ]);
    }
}
```

- [ ] **Step 6: Register the route**

Open `routes/api.php` and add the kiosk route inside the existing `public` prefix group:

```php
// Find this existing block:
Route::prefix('public')->group(function () {
    Route::get('branches', [BranchController::class, 'index']);
    Route::post('pre-registrations', [PreRegistrationController::class, 'store'])
        ->middleware('throttle:3,60');

    // Add this line:
    Route::post('kiosk/lookup', [\App\Http\Controllers\Public\KioskLookupController::class, 'lookup'])
        ->middleware('throttle:10,1');
});
```

- [ ] **Step 7: Run Pint formatter**

```bash
vendor/bin/sail bin pint --dirty --format agent
```

- [ ] **Step 8: Run the tests and confirm they pass**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Public/KioskLookupTest.php
```

Expected: all 9 tests PASS

- [ ] **Step 9: Commit**

```bash
git add app/Http/Requests/Public/KioskLookupRequest.php \
        app/Http/Controllers/Public/KioskLookupController.php \
        routes/api.php \
        tests/Feature/Public/KioskLookupTest.php
git commit -m "feat(api): add public kiosk student lookup endpoint"
```

---

## Task 2: Frontend — Install package, types, and API service

**Files:**
- Modify: `package.json` (via npm install)
- Create: `types/kiosk.ts`
- Create: `lib/api/kiosk.ts`

**Interfaces:**
- Produces: `KioskStudent` type and `kioskApi.lookup(qrCode)` function consumed by Tasks 3–5

- [ ] **Step 1: Install @zxing/browser**

```bash
cd ~/sunbites-pos && npm install @zxing/browser
```

Expected: package added to `node_modules` and `package.json`

- [ ] **Step 2: Create the type file**

Create `types/kiosk.ts`:

```typescript
export interface KioskOrder {
  items: string;
  total: string;
  date: string;
}

export interface KioskStudent {
  name: string;
  initials: string;
  grade_level: string;
  student_type: "subscription" | "non_subscription";
  balance: string;
  last_orders: KioskOrder[];
}
```

- [ ] **Step 3: Create the API service**

Create `lib/api/kiosk.ts`. This uses plain `fetch` — NOT the authenticated `apiClient` which attaches a Bearer token and Branch-Id header that the kiosk does not have:

```typescript
import type { KioskStudent } from "@/types/kiosk";

export const kioskApi = {
  lookup: async (qrCode: string): Promise<KioskStudent> => {
    const response = await fetch(
      `${process.env.NEXT_PUBLIC_API_URL}/api/v1/public/kiosk/lookup`,
      {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
        },
        body: JSON.stringify({ qr_code: qrCode }),
      },
    );

    if (!response.ok) {
      throw new Error(String(response.status));
    }

    return response.json();
  },
};
```

- [ ] **Step 4: Commit**

```bash
git add types/kiosk.ts lib/api/kiosk.ts package.json package-lock.json
git commit -m "feat(kiosk): install zxing, add types and public API service"
```

---

## Task 3: Frontend — Camera scanner hook

**Files:**
- Create: `hooks/use-kiosk-scanner.ts`

**Interfaces:**
- Consumes: `@zxing/browser` `BrowserMultiFormatReader`
- Produces: `useKioskScanner({ videoRef, onScan, onCameraError, isEnabled })` — starts/stops camera scanning; calls `onScan(code)` when a valid `SB-` QR is detected; calls `onCameraError()` if camera permission is denied or unavailable

- [ ] **Step 1: Create the hook**

Create `hooks/use-kiosk-scanner.ts`:

```typescript
"use client";

import { useCallback, useEffect, useRef } from "react";
import { BrowserMultiFormatReader, type IScannerControls } from "@zxing/browser";

interface UseKioskScannerProps {
  videoRef: React.RefObject<HTMLVideoElement | null>;
  onScan: (code: string) => void;
  onCameraError: () => void;
  isEnabled: boolean;
}

export function useKioskScanner({
  videoRef,
  onScan,
  onCameraError,
  isEnabled,
}: UseKioskScannerProps) {
  const controlsRef = useRef<IScannerControls | null>(null);
  const isLockedRef = useRef(false);

  const start = useCallback(async () => {
    if (!videoRef.current) return;

    try {
      const controls = await BrowserMultiFormatReader.decodeFromVideoDevice(
        undefined,
        videoRef.current,
        (result) => {
          if (!result || isLockedRef.current) return;

          const text = result.getText();
          if (!text.startsWith("SB-")) return;

          isLockedRef.current = true;
          onScan(text);

          setTimeout(() => {
            isLockedRef.current = false;
          }, 1000);
        },
      );

      controlsRef.current = controls;
    } catch {
      onCameraError();
    }
  }, [videoRef, onScan, onCameraError]);

  const stop = useCallback(() => {
    controlsRef.current?.stop();
    controlsRef.current = null;
  }, []);

  useEffect(() => {
    if (isEnabled) {
      start();
    } else {
      stop();
    }

    return () => stop();
  }, [isEnabled, start, stop]);
}
```

- [ ] **Step 2: Commit**

```bash
git add hooks/use-kiosk-scanner.ts
git commit -m "feat(kiosk): add camera QR scanner hook"
```

---

## Task 4: Frontend — Kiosk state hook

**Files:**
- Create: `hooks/use-kiosk-lookup.ts`

**Interfaces:**
- Consumes: `kioskApi.lookup(qrCode)` from `lib/api/kiosk.ts`; `KioskStudent` from `types/kiosk.ts`
- Produces: `useKioskLookup()` returning `{ state, student, handleScan, reset }`
  - `state: "scanning" | "loading" | "result" | "error"`
  - `student: KioskStudent | null`
  - `handleScan(qrCode: string): void` — triggers the lookup
  - `reset(): void` — returns to scanning state

- [ ] **Step 1: Create the hook**

Create `hooks/use-kiosk-lookup.ts`:

```typescript
"use client";

import { useCallback, useState } from "react";
import { kioskApi } from "@/lib/api/kiosk";
import type { KioskStudent } from "@/types/kiosk";

type KioskState = "scanning" | "loading" | "result" | "error";

export function useKioskLookup() {
  const [state, setState] = useState<KioskState>("scanning");
  const [student, setStudent] = useState<KioskStudent | null>(null);

  const handleScan = useCallback(async (qrCode: string) => {
    setState("loading");
    try {
      const data = await kioskApi.lookup(qrCode);
      setStudent(data);
      setState("result");
    } catch {
      setStudent(null);
      setState("error");
    }
  }, []);

  const reset = useCallback(() => {
    setState("scanning");
    setStudent(null);
  }, []);

  return { state, student, handleScan, reset };
}
```

- [ ] **Step 2: Commit**

```bash
git add hooks/use-kiosk-lookup.ts
git commit -m "feat(kiosk): add kiosk state machine hook"
```

---

## Task 5: Frontend — Kiosk page, layout, and tests

**Files:**
- Create: `app/(kiosk)/layout.tsx`
- Create: `app/(kiosk)/kiosk/page.tsx`
- Create: `app/(kiosk)/kiosk/loading.tsx`
- Create: `app/(kiosk)/kiosk/kiosk.test.tsx`

**Interfaces:**
- Consumes: `useKioskScanner` from Task 3, `useKioskLookup` from Task 4, `KioskStudent` from Task 2

- [ ] **Step 1: Add the MSW handler for the public kiosk endpoint**

Open `__tests__/mocks/handlers.ts` (the existing MSW handlers file) and add the kiosk handler to the array:

```typescript
// Add this import at the top alongside existing http imports:
import { http, HttpResponse } from "msw";

// Add to the handlers array:
http.post(
  `${process.env.NEXT_PUBLIC_API_URL}/api/v1/public/kiosk/lookup`,
  () =>
    HttpResponse.json({
      name: "Juan Dela Cruz",
      initials: "JD",
      grade_level: "Grade 3",
      student_type: "subscription",
      balance: "245.00",
      last_orders: [
        { items: "Rice Meal, Water", total: "55.00", date: "Jun 23, 2026" },
        { items: "Snack Pack", total: "25.00", date: "Jun 22, 2026" },
      ],
    }),
),
```

- [ ] **Step 2: Write the failing tests**

Create `app/(kiosk)/kiosk/kiosk.test.tsx`:

```typescript
import { act, render, screen } from "@/__tests__/test-utils";
import userEvent from "@testing-library/user-event";
import { http, HttpResponse } from "msw";
import { server } from "@/__tests__/mocks/server";
import KioskPage from "./page";

// Mock @zxing/browser — camera does not work in jsdom
let capturedScanCallback:
  | ((result: { getText: () => string } | null) => void)
  | null = null;

jest.mock("@zxing/browser", () => ({
  BrowserMultiFormatReader: {
    decodeFromVideoDevice: jest.fn(
      (_deviceId: unknown, _video: unknown, callback: (result: { getText: () => string } | null) => void) => {
        capturedScanCallback = callback;
        return Promise.resolve({ stop: jest.fn() });
      },
    ),
  },
}));

const simulateScan = (qrCode: string) => {
  act(() => {
    capturedScanCallback?.({ getText: () => qrCode });
  });
};

describe("KioskPage", () => {
  beforeEach(() => {
    capturedScanCallback = null;
    jest.useFakeTimers();
  });

  afterEach(() => {
    jest.useRealTimers();
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
    expect(screen.getByText("₱245.00")).toBeInTheDocument();
    expect(screen.getByText("Rice Meal, Water")).toBeInTheDocument();
  });

  it("shows green balance for amount >= 50", async () => {
    render(<KioskPage />);
    simulateScan("SB-testqrcode1234");

    const balance = await screen.findByText("₱245.00");
    expect(balance).toHaveClass("text-green-600");
  });

  it("shows orange balance for amount between 0 and 50", async () => {
    server.use(
      http.post(
        `${process.env.NEXT_PUBLIC_API_URL}/api/v1/public/kiosk/lookup`,
        () => HttpResponse.json({ name: "Juan Dela Cruz", initials: "JD", grade_level: "Grade 3", student_type: "subscription", balance: "30.00", last_orders: [] }),
      ),
    );

    render(<KioskPage />);
    simulateScan("SB-testqrcode1234");

    const balance = await screen.findByText("₱30.00");
    expect(balance).toHaveClass("text-orange-500");
  });

  it("shows red balance for zero balance", async () => {
    server.use(
      http.post(
        `${process.env.NEXT_PUBLIC_API_URL}/api/v1/public/kiosk/lookup`,
        () => HttpResponse.json({ name: "Juan Dela Cruz", initials: "JD", grade_level: "Grade 3", student_type: "subscription", balance: "0.00", last_orders: [] }),
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
        `${process.env.NEXT_PUBLIC_API_URL}/api/v1/public/kiosk/lookup`,
        () => HttpResponse.json({ message: "Student not found." }, { status: 404 }),
      ),
    );

    render(<KioskPage />);
    simulateScan("SB-testqrcode1234");

    expect(await screen.findByText(/please see a cashier/i)).toBeInTheDocument();
  });

  it("shows the same error card for 403 (restricted student)", async () => {
    server.use(
      http.post(
        `${process.env.NEXT_PUBLIC_API_URL}/api/v1/public/kiosk/lookup`,
        () => HttpResponse.json({ message: "Student is not eligible." }, { status: 403 }),
      ),
    );

    render(<KioskPage />);
    simulateScan("SB-testqrcode1234");

    expect(await screen.findByText(/please see a cashier/i)).toBeInTheDocument();
  });

  it("auto-resets to scan state after 10 seconds on result", async () => {
    render(<KioskPage />);
    simulateScan("SB-testqrcode1234");

    await screen.findByText("Juan Dela Cruz");

    act(() => {
      jest.advanceTimersByTime(10000);
    });

    expect(screen.getByText(/scan your id card/i)).toBeInTheDocument();
    expect(screen.queryByText("Juan Dela Cruz")).not.toBeInTheDocument();
  });

  it("auto-resets to scan state after 5 seconds on error", async () => {
    server.use(
      http.post(
        `${process.env.NEXT_PUBLIC_API_URL}/api/v1/public/kiosk/lookup`,
        () => HttpResponse.json({ message: "Student not found." }, { status: 404 }),
      ),
    );

    render(<KioskPage />);
    simulateScan("SB-testqrcode1234");

    await screen.findByText(/please see a cashier/i);

    act(() => {
      jest.advanceTimersByTime(5000);
    });

    expect(screen.getByText(/scan your id card/i)).toBeInTheDocument();
  });

  it("shows camera access required message when camera is denied", async () => {
    const { BrowserMultiFormatReader } = await import("@zxing/browser");
    (BrowserMultiFormatReader.decodeFromVideoDevice as jest.Mock).mockRejectedValueOnce(
      new DOMException("Permission denied", "NotAllowedError"),
    );

    render(<KioskPage />);

    expect(
      await screen.findByText(/camera access required/i),
    ).toBeInTheDocument();
  });

  it("ignores QR codes that do not start with SB-", () => {
    render(<KioskPage />);
    simulateScan("INVALID-123");

    // Should stay on scan screen — no loading or result
    expect(screen.getByText(/scan your id card/i)).toBeInTheDocument();
    expect(screen.queryByText(/please see a cashier/i)).not.toBeInTheDocument();
  });
});
```

- [ ] **Step 3: Run tests to confirm they fail**

```bash
cd ~/sunbites-pos && npx jest app/\(kiosk\)/kiosk/kiosk.test.tsx --no-coverage
```

Expected: FAIL — `(kiosk)/kiosk/page.tsx` does not exist yet

- [ ] **Step 4: Create the fullscreen layout**

Create `app/(kiosk)/layout.tsx`:

```typescript
import type { ReactNode } from "react";

export default function KioskLayout({ children }: { children: ReactNode }) {
  return (
    <div className="min-h-screen w-full overflow-hidden bg-background">
      {children}
    </div>
  );
}
```

- [ ] **Step 5: Create the loading screen**

Create `app/(kiosk)/kiosk/loading.tsx`:

```typescript
export default function KioskLoading() {
  return <div className="min-h-screen w-full bg-background" />;
}
```

- [ ] **Step 6: Create the kiosk page**

Create `app/(kiosk)/kiosk/page.tsx`:

```typescript
"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { cn } from "@/lib/utils";
import { useKioskLookup } from "@/hooks/use-kiosk-lookup";
import { useKioskScanner } from "@/hooks/use-kiosk-scanner";
import { AppLogo } from "@/components/app-logo";
import type { KioskStudent } from "@/types/kiosk";

export default function KioskPage() {
  const videoRef = useRef<HTMLVideoElement>(null);
  const { state, student, handleScan, reset } = useKioskLookup();
  const [cameraBlocked, setCameraBlocked] = useState(false);

  useKioskScanner({
    videoRef,
    onScan: handleScan,
    onCameraError: () => setCameraBlocked(true),
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
      {/* Camera viewfinder — always mounted, hidden when not scanning */}
      <video
        ref={videoRef}
        className={cn(
          "absolute inset-0 h-full w-full object-cover",
          state !== "scanning" && state !== "loading" && "hidden",
        )}
        muted
        playsInline
      />

      {/* Scanning state overlay */}
      {(state === "scanning" || state === "loading") && (
        <div className="relative z-10 flex flex-col items-center gap-6 text-white">
          <AppLogo className="h-12 invert" />

          {/* Scan guide frame */}
          <div className="relative h-64 w-64 rounded-2xl border-4 border-white/80 shadow-[0_0_0_9999px_rgba(0,0,0,0.5)]">
            {state === "loading" && (
              <div className="absolute inset-0 flex items-center justify-center rounded-2xl bg-black/60">
                <div className="h-10 w-10 animate-spin rounded-full border-4 border-white border-t-transparent" />
              </div>
            )}
          </div>

          <p className="text-xl font-medium tracking-wide">
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
  const balanceColor =
    balanceNum >= 50
      ? "text-green-600"
      : balanceNum > 0
        ? "text-orange-500"
        : "text-red-600";

  return (
    <div className="animate-in fade-in z-10 flex w-full max-w-sm flex-col items-center gap-5 rounded-2xl bg-card p-8 shadow-2xl">
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

      {/* Balance */}
      <p className={cn("text-5xl font-extrabold", balanceColor)}>
        ₱{student.balance}
      </p>

      {/* Last orders */}
      {student.last_orders.length > 0 && (
        <div className="w-full">
          <p className="mb-2 text-sm font-semibold text-muted-foreground uppercase tracking-wide">
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
    <div className="animate-in fade-in z-10 flex w-full max-w-sm flex-col items-center gap-4 rounded-2xl bg-card p-8 text-center shadow-2xl">
      <div className="flex h-16 w-16 items-center justify-center rounded-full bg-destructive/10 text-3xl">
        ✕
      </div>
      <p className="text-xl font-semibold">QR not recognized.</p>
      <p className="text-muted-foreground">Please see a cashier.</p>
    </div>
  );
}
```

- [ ] **Step 7: Run the tests and confirm they pass**

```bash
cd ~/sunbites-pos && npx jest app/\(kiosk\)/kiosk/kiosk.test.tsx --no-coverage
```

Expected: all tests PASS

- [ ] **Step 8: Run full test suite to check for regressions**

```bash
npx jest --no-coverage
```

Expected: all existing tests still pass

- [ ] **Step 9: Commit**

```bash
git add app/\(kiosk\)/ hooks/use-kiosk-scanner.ts hooks/use-kiosk-lookup.ts \
        lib/api/kiosk.ts types/kiosk.ts __tests__/mocks/handlers.ts
git commit -m "feat(kiosk): add public kiosk balance check page with camera QR scanning"
```

---

## Post-Implementation Checklist

- [ ] Run the full Laravel test suite: `vendor/bin/sail artisan test --compact`
- [ ] Run the full Next.js test suite: `npx jest --no-coverage`
- [ ] Open `http://localhost:3000/kiosk` in a browser — confirm the camera viewfinder appears and prompts for camera permission
- [ ] Test with a real QR code on a phone — confirm the result card appears with correct data
- [ ] Test with an invalid QR — confirm the error card appears
- [ ] Test a restricted student's QR — confirm the same error card appears (no different message)
