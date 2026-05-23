# Frontend Standards — Sunbites Next.js Apps

Applies to both Next.js projects:
- `~/sunbites-portal` — parent portal
- `~/sunbites-pos` — POS & administrative app

These rules govern all frontend code. They work alongside the `clean-code-architect`, `react-expert`, and `appsec-vulnerability-assessor` agents. Important clarification: **`react-expert` applies only to React patterns inside Client Components** — it has no knowledge of Next.js App Router Server Components. When in doubt, defer to these rules for Next.js-specific decisions.

---

## Component Template

```typescript
interface Props {
  // typed props — always explicit, no `any`
  className?: string;
}

export function ComponentName({ className, ...props }: Props) {
  return <div className={cn("base-classes", className)}>{/* content */}</div>;
}
```

- Props interface defined above the component in the same file
- `className?: string` included on all presentational components to allow composition
- `cn()` used for all conditional or merged class strings
- Named exports only — no default exports for components

---

## Server vs Client Components

Next.js App Router default: **every component is a Server Component** unless marked otherwise.

```
// Server Component (default — no directive needed)
export default async function StudentsPage() {
  const students = await studentApi.list(); // server-side fetch
  return <StudentList students={students} />;
}

// Client Component — only when needed
"use client";

export function SearchInput({ onSearch }: Props) {
  const [query, setQuery] = useState("");
  // ...
}
```

Use `"use client"` **only** when the component needs:
- `useState`, `useReducer`, `useEffect`, or other React hooks
- Browser APIs (`window`, `document`, `localStorage`)
- Event listeners or interactive UI

Push Client Components to leaf nodes. Layouts and page shells stay Server Components.

---

## Hook Conventions

- File: `hooks/use-{name}.ts` — kebab-case, prefixed with `use-`
- Function: `useNoun()` or `useVerbNoun()` — e.g. `useStudents()`, `useRecordPayment()`
- Return an **object** (not array) for 3+ return values: `{ data, isLoading, error }`
- TanStack Query hooks wrap all domain API functions — **never** raw `fetch + useState` for server data
- Keep hooks pure — no side effects outside `useEffect`

```typescript
// hooks/use-student-payments.ts
export function useStudentPayments(studentId: number) {
  return useQuery({
    queryKey: ["student-payments", studentId],
    queryFn: () => paymentApi.getByStudent(studentId),
  });
}
```

---

## Page & Route Structure

Every route segment should provide:

```
app/
  (auth)/
    login/
      page.tsx
  (dashboard)/
    students/
      page.tsx          ← required
      loading.tsx       ← skeleton UI while data loads
      error.tsx         ← error boundary UI
      [id]/
        page.tsx
        loading.tsx
```

- `page.tsx` — main page component (Server Component by default)
- `loading.tsx` — shown by Next.js Suspense automatically; use skeleton components
- `error.tsx` — must be a Client Component (`"use client"`); shows user-friendly error UI
- Route groups `(auth)`, `(dashboard)` for layout variations without affecting the URL

---

## Forms

- Controlled components with `useState` for form field state
- **Zod 4** validation schemas in `lib/validation/{domain}.ts`
- Use `schema.safeParse()` — never `.parse()` (throws instead of returning errors)
- Show field-level errors from `result.error.errors`
- Disable submit button while mutation is pending
- Reset form on successful submission

```typescript
// lib/validation/enrollment.ts
import { z } from "zod";

export const enrollStudentSchema = z.object({
  firstName: z.string().min(1, "First name is required"),
  lastName: z.string().min(1, "Last name is required"),
  studentNumber: z.string().min(1, "Student number is required"),
  gradeLevel: z.string(),
  studentType: z.enum(["subscription", "non_subscription"]),
});

export type EnrollStudentData = z.infer<typeof enrollStudentSchema>;
```

```typescript
// In the form component
const result = enrollStudentSchema.safeParse(formData);
if (!result.success) {
  setErrors(result.error.flatten().fieldErrors);
  return;
}
await mutation.mutateAsync(result.data);
```

---

## API Service Layer

All API calls go through typed service modules in `lib/api/`. Never call `fetch` directly in a component or hook.

```
lib/
  api/
    client.ts           ← base fetch wrapper with auth headers + error handling
    students.ts
    payments.ts
    wallet.ts
    enrollment.ts
```

```typescript
// lib/api/students.ts
export const studentApi = {
  list: (params?: StudentListParams) =>
    apiClient.get<PaginatedResponse<Student>>("/students", { params }),

  show: (id: number) =>
    apiClient.get<Student>(`/students/${id}`),
};
```

The `apiClient` in `client.ts` handles:
- Attaching the Sanctum Bearer token to every request
- Base URL from `process.env.NEXT_PUBLIC_API_URL`
- Parsing error responses into a typed `ApiError`
- Triggering auth logout on 401 responses

---

## Styling

- **Tailwind utility classes only** — no CSS modules, no inline styles
- Use `cn()` (from `lib/utils.ts`) for conditional and merged classes
- Responsive-first: `base` → `sm:` → `md:` → `lg:` → `xl:`
- Color tokens are defined as CSS variables in `globals.css` using Tailwind v4 `@theme` — use semantic tokens (`bg-primary`, `text-muted-foreground`, `border-border`) not hardcoded colors
- shadcn/ui components are the base — extend via `className` prop, don't fork the component source

```typescript
// Correct
<div className={cn("rounded-lg border bg-card p-4", isActive && "border-primary")}>

// Wrong — no inline styles, no arbitrary hex values
<div style={{ backgroundColor: "#f97316" }}>
```

---

## Imports

Group order with a blank line between each group:

1. React / Next.js core (`react`, `next/navigation`, `next/image`)
2. External packages (`@tanstack/react-query`, `zod`, `zustand`)
3. Internal aliases (`@/components/...`, `@/hooks/...`, `@/lib/...`)
4. Types — use `import type` keyword

```typescript
import { useState } from "react";
import { useRouter } from "next/navigation";

import { useQuery } from "@tanstack/react-query";
import { z } from "zod";

import { Button } from "@/components/ui/button";
import { useStudents } from "@/hooks/use-students";
import { studentApi } from "@/lib/api/students";

import type { Student } from "@/types/student";
```

---

## State Management

- **Server state** (API data): TanStack Query — `useQuery` / `useMutation`
- **Local UI state** (toggles, form values, modals open/closed): `useState` or `useReducer`
- **Cross-cutting client state** (auth session, active branch, theme): Zustand store in `lib/store/`
- Do **not** use React Context for server data — that's TanStack Query's job

---

## Error & Loading States

Every component that consumes async data must handle all three states:

```typescript
if (isLoading) return <StudentListSkeleton />;
if (error) return <ErrorMessage message="Failed to load students." />;
if (!students?.length) return <EmptyState message="No students found." />;

return <StudentList students={students} />;
```

- Skeleton components are preferred over spinners for list/table data
- `ErrorMessage` and `EmptyState` are shared components from `components/ui/`
- Never show raw error objects or stack traces to users

---

## TypeScript Rules

- **No `any`** — use `unknown` with type narrowing, or define the proper type
- All component props have explicit interfaces or inline types
- All API response shapes have types in `types/{domain}.ts`
- Use `type` for object shapes and unions; `interface` for extensible contracts
- Enable strict mode in `tsconfig.json` — no exceptions

---

## What Not to Do

- No Inertia.js patterns (`usePage`, `router.visit`, Inertia `<Link>`, `useForm` from Inertia)
- No Wayfinder imports (`@/actions/`, `@/routes/`) — these don't exist in the Next.js apps
- No `console.log` in committed code
- No hardcoded API URLs — always `process.env.NEXT_PUBLIC_API_URL`
- No secrets or tokens in `NEXT_PUBLIC_` environment variables
- No `localStorage` for storing auth tokens
- No direct `fetch()` calls in components — use the service layer
