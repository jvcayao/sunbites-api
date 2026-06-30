# POS Students Page Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the single-query card-grid students page with a two-query, independently paginated row list and a Map-based selection system that fixes cross-tab QR batch print bugs.

**Architecture:** Two independent TanStack Query calls (subscription + non-subscription), each with their own `page` state and `Pagination` component. Selection state changes from `Set<number>` to `Map<number, Student>` to preserve full student objects across tab switches. A third `deletedQuery` handles the soft-delete view. All component changes are confined to `app/(kitchen)/students/page.tsx`.

**Tech Stack:** Next.js 15 App Router, React 19, TanStack Query v5, Tailwind v4, shadcn/ui, MSW 2, Jest 30, React Testing Library 16

## Global Constraints

- Working directory: `~/sunbites-pos` (Next.js POS app)
- No backend changes — `studentApi.list(params)` already accepts `type`, `page`, and all filter params
- Single-file constraint: all new components (`StudentRow`, `Pagination`) are defined inside `app/(kitchen)/students/page.tsx`
- `PrintCard` and `getCardAccentColors` must NOT be modified — print card colours stay red `#e5322a` / yellow `#f4b400`
- `queryClient.invalidateQueries({ queryKey: ["students"] })` in sub-components (`MonthBadges`, `RemoveStudentDialog`, `DeletedStudentCard`) stays unchanged — TanStack prefix matching handles both split query keys
- Run tests with: `cd ~/sunbites-pos && npx jest --testPathPattern="student-list" --no-coverage`
- Format with: `cd ~/sunbites-pos && npm run format` or `npx prettier --write <file>`

---

### Task 1: Update MSW fixtures and handler to support per-type queries

**Files:**
- Modify: `__tests__/mocks/handlers.ts`

**Interfaces:**
- Produces: `paginatedSubscriptionFixture` (exported) — MSW fixture for `type=subscription` calls
- Produces: `paginatedNonSubFixture` (exported) — MSW fixture for `type=non_subscription` calls

- [ ] **Step 1: Add per-type paginated fixtures after `paginatedStudentsFixture` (around line 403)**

```typescript
export const paginatedSubscriptionFixture: PaginatedStudents = {
  data: [studentFixture],
  links: { first: null, last: null, prev: null, next: null },
  meta: {
    current_page: 1,
    last_page: 1,
    per_page: 8,
    total: 1,
    from: 1,
    to: 1,
  },
};

export const paginatedNonSubFixture: PaginatedStudents = {
  data: [nonSubStudentFixture],
  links: { first: null, last: null, prev: null, next: null },
  meta: {
    current_page: 1,
    last_page: 1,
    per_page: 8,
    total: 1,
    from: 1,
    to: 1,
  },
};
```

- [ ] **Step 2: Update the `GET /students` handler to dispatch by `type` query param**

Find the current handler (around line 591):
```typescript
http.get(`${API}/students`, () =>
  HttpResponse.json(paginatedStudentsFixture),
),
```

Replace with:
```typescript
http.get(`${API}/students`, ({ request }) => {
  const url = new URL(request.url);
  const type = url.searchParams.get("type");
  if (type === "subscription") {
    return HttpResponse.json(paginatedSubscriptionFixture);
  }
  if (type === "non_subscription") {
    return HttpResponse.json(paginatedNonSubFixture);
  }
  return HttpResponse.json(paginatedStudentsFixture);
}),
```

- [ ] **Step 3: Run existing tests to confirm they still pass**

```bash
cd ~/sunbites-pos && npx jest --testPathPattern="student-list" --no-coverage
```

Expected: all currently passing tests pass. The "wallet-only" test will fail — that is expected and will be fixed in Task 2.

- [ ] **Step 4: Commit**

```bash
cd ~/sunbites-pos && git add __tests__/mocks/handlers.ts && git commit -m "test: add per-type MSW fixtures and type-discriminating student handler"
```

---

### Task 2: Update existing tests and add new failing tests (TDD)

**Files:**
- Modify: `app/(kitchen)/students/student-list.test.tsx`

**Interfaces:**
- Consumes: `paginatedSubscriptionFixture`, `paginatedNonSubFixture` from `__tests__/mocks/handlers.ts`
- Consumes: `server` from `__tests__/mocks/server.ts`

- [ ] **Step 1: Add imports at top of test file**

After the existing imports, add:
```typescript
import { http, HttpResponse } from "msw";
import { server } from "@/__tests__/mocks/server";
import {
  paginatedSubscriptionFixture,
  paginatedNonSubFixture,
} from "@/__tests__/mocks/handlers";
```

- [ ] **Step 2: Replace the wallet-only test**

Remove lines 89–92:
```typescript
it("shows wallet-only info box for non-subscription student", async () => {
  render(<StudentsPage />);
  expect(await screen.findByText(/wallet-only/i)).toBeInTheDocument();
});
```

Replace with:
```typescript
it("non-subscription student row renders in the non-subscription section", async () => {
  render(<StudentsPage />);
  await screen.findByText("Carlo Mendoza");
  const nonSubHeadings = screen.getAllByText(/non-subscription students/i);
  expect(nonSubHeadings.length).toBeGreaterThan(0);
});
```

- [ ] **Step 3: Add test — selectedStudents Map persists across tab switches**

Add inside the `describe("StudentsPage")` block after `"shows section headings in All tab"`:

```typescript
it("retains selected students from subscription section when switching tabs", async () => {
  const user = userEvent.setup();
  render(<StudentsPage />);
  await screen.findByText("Maria Santos");

  const checkboxes = screen.getAllByRole("checkbox");
  await user.click(checkboxes[0]);

  expect(screen.getByRole("button", { name: /print qr codes/i })).toBeInTheDocument();
  expect(screen.getByText("1")).toBeInTheDocument();

  const tabs = screen.getAllByRole("button", { name: /non-subscription/i });
  const nonSubTab = tabs.find((t) => t.textContent?.toLowerCase().includes("non-subscription ("));
  if (nonSubTab) await user.click(nonSubTab);

  expect(screen.getByRole("button", { name: /print qr codes/i })).toBeInTheDocument();
  expect(screen.getByText("1")).toBeInTheDocument();
});
```

- [ ] **Step 4: Add test — month and payment filters hidden on Non-subscription tab**

```typescript
it("hides month and payment status filters on non-subscription tab", async () => {
  const user = userEvent.setup();
  render(<StudentsPage />);
  await screen.findByText("Carlo Mendoza");

  const tabs = screen.getAllByRole("button", { name: /non-subscription/i });
  const nonSubTab = tabs.find((t) => t.textContent?.toLowerCase().includes("non-subscription ("));
  if (nonSubTab) await user.click(nonSubTab);

  expect(screen.queryByRole("combobox", { name: /filter by month/i })).not.toBeInTheDocument();
  expect(screen.queryByRole("combobox", { name: /filter by payment status/i })).not.toBeInTheDocument();
});
```

- [ ] **Step 5: Add test — month and payment filters hidden on All tab**

```typescript
it("hides month and payment status filters on all tab by default", async () => {
  render(<StudentsPage />);
  await screen.findByText("Maria Santos");

  expect(screen.queryByRole("combobox", { name: /filter by month/i })).not.toBeInTheDocument();
  expect(screen.queryByRole("combobox", { name: /filter by payment status/i })).not.toBeInTheDocument();
});
```

- [ ] **Step 6: Add test — mixed print batch includes both student types**

```typescript
it("mixed print batch includes students selected from both sections", async () => {
  const API = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";
  const user = userEvent.setup();

  server.use(
    http.get(`${API}/students`, ({ request }) => {
      const url = new URL(request.url);
      const type = url.searchParams.get("type");
      if (type === "subscription") return HttpResponse.json(paginatedSubscriptionFixture);
      if (type === "non_subscription") return HttpResponse.json(paginatedNonSubFixture);
      return HttpResponse.json({ ...paginatedNonSubFixture, data: [] });
    }),
  );

  render(<StudentsPage />);
  await screen.findByText("Maria Santos");

  const checkboxes = screen.getAllByRole("checkbox");
  await user.click(checkboxes[0]);
  expect(screen.getByText("1")).toBeInTheDocument();

  const tabs = screen.getAllByRole("button", { name: /non-subscription/i });
  const nonSubTab = tabs.find((t) => t.textContent?.toLowerCase().includes("non-subscription ("));
  if (nonSubTab) await user.click(nonSubTab);

  await screen.findByText("Carlo Mendoza");
  const newCheckboxes = screen.getAllByRole("checkbox");
  await user.click(newCheckboxes[0]);
  expect(screen.getByText("2")).toBeInTheDocument();

  await user.click(screen.getByRole("button", { name: /print qr codes/i }));

  const cards = document.querySelectorAll("[data-qr-card]");
  expect(cards.length).toBe(2);

  const subCard = cards[0] as HTMLElement;
  expect((subCard.firstElementChild as HTMLElement).style.backgroundColor).toBe("rgb(229, 50, 42)");

  const nonSubCard = cards[1] as HTMLElement;
  expect((nonSubCard.firstElementChild as HTMLElement).style.backgroundColor).toBe("rgb(244, 180, 0)");
});
```

- [ ] **Step 7: Run tests — confirm new tests FAIL**

```bash
cd ~/sunbites-pos && npx jest --testPathPattern="student-list" --no-coverage
```

Expected: the 5 new tests FAIL (features not yet implemented). Existing tests pass. This confirms TDD setup.

- [ ] **Step 8: Commit failing tests**

```bash
cd ~/sunbites-pos && git add "app/(kitchen)/students/student-list.test.tsx" && git commit -m "test(students): add failing TDD tests for Map selection, filter visibility, and mixed print batch"
```

---

### Task 3: Replace selection state Set → Map, update toggleSelect and BatchQrModal

**Files:**
- Modify: `app/(kitchen)/students/page.tsx`

**Interfaces:**
- Produces: `selectedStudents: Map<number, Student>` — replaces `selectedIds: Set<number>`
- Produces: `toggleSelect(student: Student, checked: boolean): void` — new signature accepting full Student object
- Produces: `clearSelection(): void` — unchanged externally

- [ ] **Step 1: Replace `selectedIds` state declaration (line 1286)**

Find:
```typescript
const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set());
```

Replace with:
```typescript
const [selectedStudents, setSelectedStudents] = useState<Map<number, Student>>(new Map());
```

- [ ] **Step 2: Remove the derived `selectedStudents` line (line 1346)**

Remove:
```typescript
const selectedStudents = allStudents.filter((s) => selectedIds.has(s.id));
```

(This entire line is deleted — the Map is now the source of truth.)

- [ ] **Step 3: Update `toggleSelect` to accept a full Student object (lines 1348–1358)**

Replace:
```typescript
function toggleSelect(id: number, checked: boolean) {
  setSelectedIds((prev) => {
    const next = new Set(prev);
    if (checked) {
      next.add(id);
    } else {
      next.delete(id);
    }
    return next;
  });
}
```

With:
```typescript
function toggleSelect(student: Student, checked: boolean) {
  setSelectedStudents((prev) => {
    const next = new Map(prev);
    if (checked) {
      next.set(student.id, student);
    } else {
      next.delete(student.id);
    }
    return next;
  });
}
```

- [ ] **Step 4: Update `clearSelection` (lines 1360–1362)**

Replace:
```typescript
function clearSelection() {
  setSelectedIds(new Set());
}
```

With:
```typescript
function clearSelection() {
  setSelectedStudents(new Map());
}
```

- [ ] **Step 5: Update floating bar to use `selectedStudents.size` (lines 1665–1667)**

Replace:
```typescript
{selectedIds.size >= 1 && (
  <span ...>
    {selectedIds.size}
  </span>
```

With:
```typescript
{selectedStudents.size >= 1 && (
  <span ...>
    {selectedStudents.size}
  </span>
```

- [ ] **Step 6: Update `BatchQrModal` students prop (line 1693)**

Replace:
```typescript
students={selectedStudents}
```

With:
```typescript
students={Array.from(selectedStudents.values())}
```

- [ ] **Step 7: Update `StudentCard` interface and internal call site**

At line 1042, change the `onSelect` prop type in `StudentCardProps`:
```typescript
// Old:
onSelect: (id: number, selected: boolean) => void;
// New:
onSelect: (student: Student, selected: boolean) => void;
```

In the `StudentCard` body at line 1096, change the checkbox handler:
```typescript
// Old:
onCheckedChange={(checked) => onSelect(student.id, !!checked)}
// New:
onCheckedChange={(checked) => onSelect(student, !!checked)}
```

Update all three `StudentCard` render sites (the three occurrences of `selected={selectedIds.has(s.id)}`):
```typescript
// Old:
selected={selectedIds.has(s.id)}
// New (3 occurrences):
selected={selectedStudents.has(s.id)}
```

The `onSelect={toggleSelect}` prop at each call site stays unchanged — the new `toggleSelect` signature matches.

- [ ] **Step 8: Run tests**

```bash
cd ~/sunbites-pos && npx jest --testPathPattern="student-list" --no-coverage
```

Expected: the "retains selected students" test now passes. The mixed-print-batch test still fails (pending `StudentRow`). Existing tests pass.

- [ ] **Step 9: Commit**

```bash
cd ~/sunbites-pos && git add "app/(kitchen)/students/page.tsx" && git commit -m "refactor(students): replace Set<number> selection with Map<number, Student> to fix cross-tab QR print bug"
```

---

### Task 4: Split single query into subQuery + nonSubQuery + deletedQuery, add page state

**Files:**
- Modify: `app/(kitchen)/students/page.tsx`

**Interfaces:**
- Produces: `subPage: number`, `nonSubPage: number` — pagination state per section
- Produces: `subQuery`, `nonSubQuery`, `deletedQuery` — TanStack Query result objects
- Produces: `subStudents`, `nonSubStudents`, `deletedStudents` — arrays of `Student`
- Produces: `subMeta`, `nonSubMeta` — pagination meta objects (or undefined)
- Produces: `isLoading: boolean`, `isError: boolean` — combined from relevant queries

- [ ] **Step 1: Add page state variables after existing filter state (after line 1285)**

```typescript
const [subPage, setSubPage] = useState(1);
const [nonSubPage, setNonSubPage] = useState(1);
```

- [ ] **Step 2: Remove the existing single `useQuery` block (lines 1295–1336)**

Delete the entire block from `const { data, isLoading, isError } = useQuery({` through the closing `});`.

- [ ] **Step 3: Add three replacement queries**

```typescript
const subQuery = useQuery({
  queryKey: [
    "students",
    "subscription",
    {
      subPage,
      search,
      gradeFilter,
      statusFilter,
      monthFilter,
      yearFilter,
      paymentStatusFilter,
    },
  ],
  queryFn: () =>
    studentApi.list({
      type: "subscription",
      page: subPage,
      search: search || undefined,
      grade: gradeFilter || undefined,
      status: statusFilter || undefined,
      month: monthFilter ? (monthFilter as SchoolMonth) : undefined,
      year: yearFilter ? Number(yearFilter) : undefined,
      payment_status: paymentStatusFilter || undefined,
    }),
  enabled: !showDeleted,
});

const nonSubQuery = useQuery({
  queryKey: [
    "students",
    "non_subscription",
    {
      nonSubPage,
      search,
      gradeFilter,
      statusFilter,
    },
  ],
  queryFn: () =>
    studentApi.list({
      type: "non_subscription",
      page: nonSubPage,
      search: search || undefined,
      grade: gradeFilter || undefined,
      status: statusFilter || undefined,
    }),
  enabled: !showDeleted,
});

const deletedQuery = useQuery({
  queryKey: ["students", "deleted", { search, gradeFilter }],
  queryFn: () =>
    studentApi.list({
      deleted: 1,
      search: search || undefined,
      grade: gradeFilter || undefined,
    }),
  enabled: showDeleted,
});
```

- [ ] **Step 4: Remove old derived state, add new derived state (lines 1338–1369)**

Remove all of:
```typescript
const allStudents = data?.data ?? [];
const subscriptionStudents = allStudents.filter(...)
const nonSubStudents = allStudents.filter(...)
// (selectedStudents line already removed in Task 3)
// (toggleSelect and clearSelection already updated in Task 3)
const displayedStudents = ...
```

Replace with:
```typescript
const subStudents = subQuery.data?.data ?? [];
const nonSubStudents = nonSubQuery.data?.data ?? [];
const deletedStudents = deletedQuery.data?.data ?? [];

const subMeta = subQuery.data?.meta;
const nonSubMeta = nonSubQuery.data?.meta;

const isLoading = showDeleted
  ? deletedQuery.isLoading
  : subQuery.isLoading || nonSubQuery.isLoading;

const isError = showDeleted
  ? deletedQuery.isError
  : subQuery.isError || nonSubQuery.isError;
```

- [ ] **Step 5: Update tab count labels**

Find the tab render block (around line 1535). Replace:
```typescript
{
  value: "subscription",
  label: `Subscription (${subscriptionStudents.length})`,
},
{
  value: "non_subscription",
  label: `Non-Subscription (${nonSubStudents.length})`,
},
```

With:
```typescript
{
  value: "subscription",
  label: `Subscription (${subMeta?.total ?? 0})`,
},
{
  value: "non_subscription",
  label: `Non-Subscription (${nonSubMeta?.total ?? 0})`,
},
```

- [ ] **Step 6: Update deleted-mode content to use `deletedStudents`**

Find line 1575 and update:
```typescript
// Old:
!allStudents.length ? (
// New:
!deletedStudents.length ? (
```

Find the `allStudents.map` inside deleted mode and update:
```typescript
// Old:
{allStudents.map((s) => (
  <DeletedStudentCard key={s.id} student={s} />
))}
// New:
{deletedStudents.map((s) => (
  <DeletedStudentCard key={s.id} student={s} />
))}
```

- [ ] **Step 7: Remove the outer `!displayedStudents.length` empty state condition**

Find and remove this branch from the content conditional:
```typescript
) : !displayedStudents.length ? (
  <div className="rounded-xl border border-border bg-card px-6 py-10 text-center text-sm text-muted-foreground">
    No students found.
  </div>
```

The new conditional chain is:
```
isError ? (error UI)
: isLoading ? (skeleton)
: showDeleted ? (deleted content)
: activeTab === "all" ? (all tab — two section boxes, each handles own empty state)
: activeTab === "subscription" ? (sub tab — section box handles own empty state)
: (non-sub tab — section box handles own empty state)
```

- [ ] **Step 8: Run tests**

```bash
cd ~/sunbites-pos && npx jest --testPathPattern="student-list" --no-coverage
```

Expected: filter visibility tests ("hides month and payment status filters…") now pass. Maria Santos and Carlo Mendoza tests pass. Investigate any unexpected failures.

- [ ] **Step 9: Commit**

```bash
cd ~/sunbites-pos && git add "app/(kitchen)/students/page.tsx" && git commit -m "refactor(students): split single useQuery into independent subQuery/nonSubQuery/deletedQuery with page state"
```

---

### Task 5: Add StudentRow + Pagination components and update render logic

**Files:**
- Modify: `app/(kitchen)/students/page.tsx`

**Interfaces:**
- Consumes: `subQuery`, `nonSubQuery`, `subStudents`, `nonSubStudents`, `subMeta`, `nonSubMeta`, `subPage`, `nonSubPage` from Task 4
- Consumes: `selectedStudents: Map<number, Student>` and `toggleSelect(student, checked)` from Task 3
- Produces: `StudentRow` function component — horizontal row layout replacing `StudentCard`
- Produces: `Pagination` function component — page controls with ellipsis and "Showing X–Y of Z"

- [ ] **Step 1: Add the `Pagination` component before `StudentsPage` (around line 1269)**

```typescript
// ---------------------------------------------------------------------------
// Pagination
// ---------------------------------------------------------------------------

interface PaginationProps {
  currentPage: number;
  lastPage: number;
  from: number;
  to: number;
  total: number;
  onPageChange: (page: number) => void;
}

function Pagination({
  currentPage,
  lastPage,
  from,
  to,
  total,
  onPageChange,
}: PaginationProps) {
  if (lastPage <= 1) return null;

  const pages: (number | "…")[] = [];
  for (let i = 1; i <= lastPage; i++) {
    if (
      i === 1 ||
      i === lastPage ||
      (i >= currentPage - 1 && i <= currentPage + 1)
    ) {
      pages.push(i);
    } else if (
      (i === currentPage - 2 && currentPage > 3) ||
      (i === currentPage + 2 && currentPage < lastPage - 2)
    ) {
      pages.push("…");
    }
  }

  return (
    <div className="flex items-center justify-between pt-3 border-t border-border">
      <p className="text-xs text-muted-foreground">
        Showing {from}–{to} of {total}
      </p>
      <div className="flex items-center gap-1">
        <button
          type="button"
          onClick={() => onPageChange(currentPage - 1)}
          disabled={currentPage === 1}
          className="rounded px-2 py-1 text-sm text-muted-foreground hover:bg-muted/40 disabled:opacity-40"
          aria-label="Previous page"
        >
          ←
        </button>
        {pages.map((p, idx) =>
          p === "…" ? (
            <span key={`ellipsis-${idx}`} className="px-2 text-muted-foreground text-sm">
              …
            </span>
          ) : (
            <button
              key={p}
              type="button"
              onClick={() => onPageChange(p as number)}
              className={cn(
                "rounded px-2.5 py-1 text-sm transition-colors",
                p === currentPage
                  ? "bg-primary text-primary-foreground font-bold"
                  : "text-muted-foreground hover:bg-muted/40",
              )}
              aria-current={p === currentPage ? "page" : undefined}
            >
              {p}
            </button>
          ),
        )}
        <button
          type="button"
          onClick={() => onPageChange(currentPage + 1)}
          disabled={currentPage === lastPage}
          className="rounded px-2 py-1 text-sm text-muted-foreground hover:bg-muted/40 disabled:opacity-40"
          aria-label="Next page"
        >
          →
        </button>
      </div>
    </div>
  );
}
```

- [ ] **Step 2: Add the `StudentRow` component before `Pagination` (replacing `StudentCard` for the active list)**

```typescript
// ---------------------------------------------------------------------------
// StudentRow
// ---------------------------------------------------------------------------

interface StudentRowProps {
  student: Student;
  selected: boolean;
  onSelect: (student: Student, selected: boolean) => void;
  onTopUp: (student: Student) => void;
  onStatusClick: (student: Student) => void;
  onRemove: (student: Student) => void;
  canTogglePayment: boolean;
  accentColor: string;
}

function StudentRow({
  student,
  selected,
  onSelect,
  onTopUp,
  onStatusClick,
  onRemove,
  canTogglePayment,
  accentColor,
}: StudentRowProps) {
  const isSubscription = student.student_type === "subscription";
  const statusConfig = ENROLLMENT_STATUS_CONFIG[student.enrollment_status];
  const creditOwed = parseFloat(student.credit_balance) > 0;

  const [photoSrc, setPhotoSrc] = useState<string | null>(null);
  useEffect(() => {
    if (!student.photo_url) return;
    let objectUrl: string | null = null;
    const { token, activeBranch } = useAuthStore.getState();
    const headers: Record<string, string> = { Accept: "image/*" };
    if (token) headers["Authorization"] = `Bearer ${token}`;
    if (activeBranch) headers["X-Branch-Id"] = String(activeBranch.id);
    fetch(student.photo_url, { headers })
      .then((res) => res.blob())
      .then((blob) => {
        objectUrl = URL.createObjectURL(blob);
        setPhotoSrc(objectUrl);
      })
      .catch(() => {});
    return () => {
      if (objectUrl) URL.revokeObjectURL(objectUrl);
    };
  }, [student.photo_url]);

  return (
    <div
      className="rounded-xl border border-border bg-card px-4 py-3 flex items-start gap-3"
      style={{ borderLeftWidth: 4, borderLeftColor: accentColor }}
    >
      <div className="pt-0.5 shrink-0">
        <Checkbox
          checked={selected}
          onCheckedChange={(checked) => onSelect(student, !!checked)}
          aria-label={`Select ${student.full_name}`}
        />
      </div>

      {photoSrc ? (
        <img
          src={photoSrc}
          alt={student.full_name}
          className="h-9 w-9 shrink-0 rounded-full object-cover border border-primary/20"
        />
      ) : (
        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-bold text-primary">
          {student.first_name.charAt(0).toUpperCase()}
        </div>
      )}

      <div className="flex-1 min-w-0">
        <div className="flex flex-wrap items-center gap-2">
          <span className="font-semibold text-foreground truncate">
            {student.full_name}
          </span>
          <button
            type="button"
            onClick={() => onStatusClick(student)}
            className={statusConfig.className}
          >
            {statusConfig.label}
          </button>
          {creditOwed && (
            <span className="text-[11px] font-bold px-2 py-0.5 rounded-full border bg-red-100 text-destructive border-red-300">
              ₱{student.credit_balance} Credit Owed
            </span>
          )}
        </div>
        <p className="text-xs text-muted-foreground mt-0.5">
          {student.grade_level}
          {student.section ? ` · ${student.section}` : ""}
        </p>
        {isSubscription && (
          <div className="mt-2">
            <MonthBadges
              studentId={student.id}
              payments={student.monthly_payments ?? []}
              canToggle={canTogglePayment}
            />
          </div>
        )}
      </div>

      <div className="flex shrink-0 items-center gap-2 ml-auto flex-wrap justify-end">
        <span className="text-sm font-bold text-foreground tabular-nums">
          ₱{(student.wallet_balance ?? 0).toFixed(2)}
        </span>
        <Link
          href={`/students/${student.id}`}
          className="rounded-md border border-border bg-card px-3 py-1.5 text-xs font-medium transition-colors hover:bg-muted/40"
        >
          Edit
        </Link>
        <Button
          type="button"
          size="sm"
          variant="outline"
          onClick={() => onTopUp(student)}
        >
          Wallet
        </Button>
        <Button
          type="button"
          size="sm"
          variant="outline"
          onClick={() => onRemove(student)}
          className="text-destructive border-destructive/40 hover:bg-destructive/10"
        >
          Remove
        </Button>
      </div>
    </div>
  );
}
```

- [ ] **Step 3: Replace the All tab render block**

Find the `activeTab === "all"` branch (around line 1590). Replace the entire block with:

```typescript
) : activeTab === "all" ? (
  <div className="space-y-6">
    <div className="rounded-xl border border-border bg-card p-4 space-y-3">
      <h2 className="text-sm font-extrabold uppercase tracking-wider text-amber-600">
        Subscription Students ({subMeta?.total ?? 0})
      </h2>
      {subQuery.isLoading ? (
        <div className="space-y-2">
          {[1, 2].map((k) => (
            <div key={k} className="h-14 animate-pulse rounded-lg bg-muted" />
          ))}
        </div>
      ) : subStudents.length === 0 ? (
        <p className="text-sm text-muted-foreground">No subscription students found.</p>
      ) : (
        <>
          <div className="space-y-2">
            {subStudents.map((s) => (
              <StudentRow
                key={s.id}
                student={s}
                selected={selectedStudents.has(s.id)}
                onSelect={toggleSelect}
                onTopUp={setTopUpStudent}
                onStatusClick={(st) =>
                  setStatusPickerState({
                    studentId: st.id,
                    current: st.enrollment_status,
                  })
                }
                onRemove={setRemoveStudent}
                canTogglePayment={canTogglePayment}
                accentColor="#F59E0B"
              />
            ))}
          </div>
          {subMeta && (
            <Pagination
              currentPage={subMeta.current_page}
              lastPage={subMeta.last_page}
              from={subMeta.from}
              to={subMeta.to}
              total={subMeta.total}
              onPageChange={setSubPage}
            />
          )}
        </>
      )}
    </div>

    <div className="rounded-xl border border-border bg-card p-4 space-y-3">
      <h2 className="text-sm font-extrabold uppercase tracking-wider text-violet-600">
        Non-Subscription Students ({nonSubMeta?.total ?? 0})
      </h2>
      {nonSubQuery.isLoading ? (
        <div className="space-y-2">
          {[1, 2].map((k) => (
            <div key={k} className="h-14 animate-pulse rounded-lg bg-muted" />
          ))}
        </div>
      ) : nonSubStudents.length === 0 ? (
        <p className="text-sm text-muted-foreground">No non-subscription students found.</p>
      ) : (
        <>
          <div className="space-y-2">
            {nonSubStudents.map((s) => (
              <StudentRow
                key={s.id}
                student={s}
                selected={selectedStudents.has(s.id)}
                onSelect={toggleSelect}
                onTopUp={setTopUpStudent}
                onStatusClick={(st) =>
                  setStatusPickerState({
                    studentId: st.id,
                    current: st.enrollment_status,
                  })
                }
                onRemove={setRemoveStudent}
                canTogglePayment={canTogglePayment}
                accentColor="#8B5CF6"
              />
            ))}
          </div>
          {nonSubMeta && (
            <Pagination
              currentPage={nonSubMeta.current_page}
              lastPage={nonSubMeta.last_page}
              from={nonSubMeta.from}
              to={nonSubMeta.to}
              total={nonSubMeta.total}
              onPageChange={setNonSubPage}
            />
          )}
        </>
      )}
    </div>
  </div>
```

- [ ] **Step 4: Replace the single-tab render block**

Find the trailing `else` branch (around line 1642 — `displayedStudents.map`). Replace it with:

```typescript
) : activeTab === "subscription" ? (
  <div className="rounded-xl border border-border bg-card p-4 space-y-3">
    {subQuery.isLoading ? (
      <div className="space-y-2">
        {[1, 2, 3].map((k) => (
          <div key={k} className="h-14 animate-pulse rounded-lg bg-muted" />
        ))}
      </div>
    ) : subStudents.length === 0 ? (
      <p className="text-sm text-muted-foreground py-6 text-center">No students found.</p>
    ) : (
      <>
        <div className="space-y-2">
          {subStudents.map((s) => (
            <StudentRow
              key={s.id}
              student={s}
              selected={selectedStudents.has(s.id)}
              onSelect={toggleSelect}
              onTopUp={setTopUpStudent}
              onStatusClick={(st) =>
                setStatusPickerState({
                  studentId: st.id,
                  current: st.enrollment_status,
                })
              }
              onRemove={setRemoveStudent}
              canTogglePayment={canTogglePayment}
              accentColor="#F59E0B"
            />
          ))}
        </div>
        {subMeta && (
          <Pagination
            currentPage={subMeta.current_page}
            lastPage={subMeta.last_page}
            from={subMeta.from}
            to={subMeta.to}
            total={subMeta.total}
            onPageChange={setSubPage}
          />
        )}
      </>
    )}
  </div>
) : (
  <div className="rounded-xl border border-border bg-card p-4 space-y-3">
    {nonSubQuery.isLoading ? (
      <div className="space-y-2">
        {[1, 2, 3].map((k) => (
          <div key={k} className="h-14 animate-pulse rounded-lg bg-muted" />
        ))}
      </div>
    ) : nonSubStudents.length === 0 ? (
      <p className="text-sm text-muted-foreground py-6 text-center">No students found.</p>
    ) : (
      <>
        <div className="space-y-2">
          {nonSubStudents.map((s) => (
            <StudentRow
              key={s.id}
              student={s}
              selected={selectedStudents.has(s.id)}
              onSelect={toggleSelect}
              onTopUp={setTopUpStudent}
              onStatusClick={(st) =>
                setStatusPickerState({
                  studentId: st.id,
                  current: st.enrollment_status,
                })
              }
              onRemove={setRemoveStudent}
              canTogglePayment={canTogglePayment}
              accentColor="#8B5CF6"
            />
          ))}
        </div>
        {nonSubMeta && (
          <Pagination
            currentPage={nonSubMeta.current_page}
            lastPage={nonSubMeta.last_page}
            from={nonSubMeta.from}
            to={nonSubMeta.to}
            total={nonSubMeta.total}
            onPageChange={setNonSubPage}
          />
        )}
      </>
    )}
  </div>
)}
```

- [ ] **Step 5: Run all tests**

```bash
cd ~/sunbites-pos && npx jest --testPathPattern="student-list" --no-coverage
```

Expected: all 19+ tests pass, including the 5 new TDD tests.

- [ ] **Step 6: Commit**

```bash
cd ~/sunbites-pos && git add "app/(kitchen)/students/page.tsx" && git commit -m "feat(students): add StudentRow + Pagination, replace StudentCard in active list, wire two-query sections"
```

---

### Task 6: Format, type-check, full test run, and final commit

**Files:**
- `app/(kitchen)/students/page.tsx`
- `__tests__/mocks/handlers.ts`
- `app/(kitchen)/students/student-list.test.tsx`

- [ ] **Step 1: Format changed files**

```bash
cd ~/sunbites-pos && npm run format
```

- [ ] **Step 2: TypeScript type check**

```bash
cd ~/sunbites-pos && npx tsc --noEmit
```

Expected: zero type errors. Fix any that appear before proceeding.

- [ ] **Step 3: Run full Jest test suite**

```bash
cd ~/sunbites-pos && npx jest --no-coverage
```

Expected: all tests pass.

- [ ] **Step 4: Final commit**

```bash
cd ~/sunbites-pos && git add -A && git commit -m "chore(students): format and type-check after page redesign"
```

---

## Self-Review

**Spec coverage:**

| Spec requirement | Task that covers it |
|---|---|
| Row list layout replacing card grid | Task 5 — `StudentRow` replaces `StudentCard` |
| Two stacked independent sections in All tab | Task 5 — two section boxes each with own query |
| Pagination per section (8 rows All, 20 rows single-tab) | Task 4 (per_page in API) + Task 5 (`Pagination` component) |
| Subscription filters hidden on All / Non-sub tabs | Already in current code — tested in Task 2, verified in Task 4 |
| Map-based selection persisting across tab switches | Task 3 |
| `BatchQrModal` receives full `Student` objects from Map | Task 3 (Step 6) |
| Print card colours unchanged (red/yellow) | `PrintCard` + `getCardAccentColors` not touched |
| Tab counts from `meta.total` | Task 4 (Step 5) |
| Deleted mode uses `deletedQuery` | Task 4 (Steps 3 + 6) |
| `invalidateQueries` prefix still works | No changes needed — verified in spec |
| Wallet-only test replaced | Task 2 (Step 2) |
| New cross-tab selection test | Task 2 (Step 3) |
| Filter visibility tests | Task 2 (Steps 4–5) |
| Mixed batch print test | Task 2 (Step 6) |

**Placeholder scan:** None found. All steps contain exact code.

**Type consistency:**
- `toggleSelect(student: Student, checked: boolean)` — defined in Task 3, used in Task 3 and Task 5 (all occurrences match)
- `selectedStudents: Map<number, Student>` — defined Task 3, `has(s.id)` used in Task 5 (correct — Map.has takes a key)
- `subStudents`, `nonSubStudents` — defined Task 4, consumed Task 5
- `subMeta`, `nonSubMeta` — defined Task 4, consumed Task 5
- `setSubPage`, `setNonSubPage` — defined Task 4, consumed Task 5 as `onPageChange`
- `accentColor="#F59E0B"` — subscription rows, `"#8B5CF6"` — non-sub rows (list UI only, not print)
