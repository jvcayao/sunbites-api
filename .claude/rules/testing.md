# Testing Standards

Covers all three Sunbites projects:

| Project | Path | Test Stack |
|---|---|---|
| Laravel API | `~/sunbites` | PHPUnit 12, real database |
| Parent portal | `~/sunbites-portal` | Jest 30, React Testing Library, MSW 2 |
| POS / admin app | `~/sunbites-pos` | Jest 30, React Testing Library, MSW 2 |

---

## Shared Principles

1. **Test behavior, not implementation** — assert what users see and what the system does, not how it does it internally
2. **Arrange-Act-Assert** — every test follows this structure without exception
3. **One concept per test** — each test proves one thing; if a test fails you know exactly what broke
4. **Happy path + failure path + edge case** — every feature needs all three covered
5. **Tests are not temporary** — never delete or skip a test without an explicit approval; skipped tests must have a reason in a comment
6. **No verification scripts** — write a proper test instead; tinker only when tests cannot cover it

---

## Backend — Laravel API (PHPUnit 12)

### Creating Tests
```bash
php artisan make:test --phpunit StudentEnrollmentTest   # Feature test (default)
php artisan make:test --phpunit --unit MonthlyAmountTest  # Unit test
```

Always create **Feature tests** unless the logic is pure, stateless, and has no database dependency.

### Running Tests
```bash
# Run a single file
php artisan test --compact tests/Feature/EnrollmentTest.php

# Run by filter (fastest during development)
php artisan test --compact --filter=testSubscriptionStudentGetsMonthlyPaymentRecords

# Full suite (run before finalising any feature)
php artisan test --compact
```

Run the **minimum number of tests needed** while developing. Run the full suite before marking a task done.

### Database
- Tests hit a **real database** — never mock Eloquent or the database layer
- Use `RefreshDatabase` or `LazilyRefreshDatabase` trait on every Feature test class
- Use model factories for setup — check existing factory states before setting attributes manually

```php
// Correct — use factory
$student = Student::factory()->subscription()->create(['branch_id' => $branch->id]);

// Wrong — never instantiate models manually in tests
$student = new Student(['first_name' => 'Juan', ...]);
$student->save();
```

### Feature Test Structure

```php
<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnrollmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_enroll_a_subscription_student(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();
        $payload = Student::factory()->subscriptionPayload()->make()->toArray();

        // Act
        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/enrollment', $payload);

        // Assert
        $response->assertCreated();
        $this->assertDatabaseHas('students', ['student_number' => $payload['student_number']]);
        $this->assertDatabaseCount('student_monthly_payments', 10);
    }
}
```

### What to Test (Backend)
- Successful creation/update/deletion with the correct response shape
- Validation rejects invalid input (wrong type, missing required field, duplicate unique value)
- Authorization: unauthenticated returns 401; wrong role returns 403
- Business rules: QR uniqueness retry, credit settlement atomicity, monthly payment seeding
- Scoping: branch-scoped endpoints cannot return another branch's data
- Soft delete: deleted records do not appear in list endpoints

### What Not to Test (Backend)
- Laravel framework internals (routing, Eloquent conventions)
- Third-party package behaviour (bavix wallet internals, spatie activity log storage)
- Trivial getters or accessors with no logic
- Exact JSON key order

---

## Frontend — Next.js Apps (Jest 30 + RTL + MSW 2)

Applies to both `~/sunbites-portal` and `~/sunbites-pos`.

### Stack
- **Jest 30** — test runner, jsdom environment
- **React Testing Library 16** — component rendering and DOM querying
- **MSW 2** — API mocking at the network boundary (not manual fetch mocks)
- **`@testing-library/user-event`** — realistic interaction simulation (type, click, tab)

### File Organisation
```
__tests__/
  setup.ts              ← jest-dom matchers, global MSW setup
  test-utils.tsx        ← custom render with QueryClient + Auth providers
  mocks/
    handlers.ts         ← MSW request handlers for the Laravel API
components/
  student-card/
    student-card.tsx
    student-card.test.tsx   ← co-located with the component
```

### Custom Render — Always Use It
Wrap every render call in the custom `render` from `__tests__/test-utils.tsx`. This provides a fresh `QueryClient`, auth context, and any other providers the app needs.

```typescript
// __tests__/test-utils.tsx
import { render, type RenderOptions } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

function AllProviders({ children }: { children: React.ReactNode }) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return (
    <QueryClientProvider client={queryClient}>
      {children}
    </QueryClientProvider>
  );
}

const customRender = (ui: React.ReactElement, options?: RenderOptions) =>
  render(ui, { wrapper: AllProviders, ...options });

export * from "@testing-library/react";
export { customRender as render };
```

```typescript
// In a test file — always import from test-utils, not from @testing-library/react directly
import { render, screen } from "@/__tests__/test-utils";
```

### Querying — Role and Label First
```typescript
// Correct — accessible queries
screen.getByRole("button", { name: "Record Payment" });
screen.getByLabelText("Student Number");
screen.getByText("Enrollment successful");

// Wrong — never query by class or test ID
screen.getByTestId("submit-btn");
document.querySelector(".student-card");
```

Query priority order: `getByRole` → `getByLabelText` → `getByPlaceholderText` → `getByText` → `getByDisplayValue`.

### MSW — Mock at the Network Boundary
```typescript
// __tests__/mocks/handlers.ts
import { http, HttpResponse } from "msw";

export const handlers = [
  http.get("/api/students", () =>
    HttpResponse.json({ data: [studentFixture], meta: { total: 1 } })
  ),
  http.post("/api/enrollment", () =>
    HttpResponse.json(enrolledStudentFixture, { status: 201 })
  ),
];
```

Never mock `fetch`, `axios`, or the `lib/api/` service layer directly. MSW intercepts at the network level so the real service layer code is exercised.

### Component Test Structure

```typescript
import { render, screen } from "@/__tests__/test-utils";
import userEvent from "@testing-library/user-event";
import { http, HttpResponse } from "msw";
import { server } from "@/__tests__/mocks/server";
import { StudentCard } from "./student-card";

describe("StudentCard", () => {
  it("displays the student name and grade", () => {
    // Arrange
    const student = { fullName: "Juan dela Cruz", gradeLevel: "Grade 3" };

    // Act
    render(<StudentCard student={student} />);

    // Assert
    expect(screen.getByText("Juan dela Cruz")).toBeInTheDocument();
    expect(screen.getByText("Grade 3")).toBeInTheDocument();
  });

  it("shows a loading skeleton while payments are fetching", () => {
    render(<StudentCard student={student} />);
    expect(screen.getByRole("status", { name: /loading/i })).toBeInTheDocument();
  });

  it("shows an error message when the API fails", async () => {
    server.use(
      http.get("/api/students/:id/payments", () =>
        HttpResponse.json({ message: "Server error" }, { status: 500 })
      )
    );

    render(<StudentCard student={student} />);

    expect(await screen.findByText(/failed to load/i)).toBeInTheDocument();
  });
});
```

### What to Test (Frontend)
- Component renders correctly with given props (default, loading, error, empty states)
- User interactions: click, type, form submission, modal open/close
- Form validation: invalid input shows field-level error messages; valid input calls the API
- Conditional rendering based on role or student type (subscription vs non-subscription)
- Successful mutation: optimistic UI update or refetch shows new data
- Failed mutation: error toast or inline error is shown; form is not reset

### What Not to Test (Frontend)
- Implementation details: internal state variable names, private functions
- Third-party library internals (TanStack Query retry logic, Zod internals)
- Exact Tailwind class names
- Snapshot tests — prefer explicit `expect` assertions
- Next.js routing internals (`useRouter` behaviour)

### Coverage Threshold
Both Next.js apps target **80% coverage** for branches, functions, lines, and statements. Configure in `jest.config.ts`:

```typescript
coverageThreshold: {
  global: {
    branches: 80,
    functions: 80,
    lines: 80,
    statements: 80,
  },
},
```

Run with `npm run test:coverage`.

---

## Cross-Cutting Rules

- **Every feature change must have a test** — no exceptions; no "I'll add tests later"
- **API contract tests**: when a Laravel API Resource changes shape, update the MSW handler fixtures in both frontend apps to match; mismatched fixtures are silent failures
- **Auth in every backend test**: always use `actingAs($user, 'sanctum')`; never test endpoints without auth unless the endpoint is explicitly public
- **No Inertia test patterns**: no `$this->get('/route')->assertInertia()` — the backend no longer uses Inertia; all assertions are on JSON responses
