# Password Visibility Toggle — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an Eye/EyeOff toggle button to every `<Input type="password" />` in both Next.js apps by enhancing the shared `Input` component — zero form-file changes required.

**Architecture:** When `type === "password"` is detected inside `Input`, the component manages a local `shown` boolean with `useState`. It renders a `<div class="relative">` wrapper with the input (plus `pr-8` to prevent text overlapping the button) and an absolutely-positioned `<button>` with an Eye or EyeOff icon from lucide-react. All non-password inputs return the existing single-element render path unchanged. The identical logic is applied to both apps; only semicolons and trailing commas differ to match each project's existing style.

**Tech Stack:** React 19 (`useState`), lucide-react (`Eye`, `EyeOff`), `@base-ui/react/input`, Tailwind CSS v4, Jest 30, React Testing Library 16, `@testing-library/user-event`

---

## File Map

| Action | Path |
|--------|------|
| Modify | `~/sunbites-portal/components/ui/input.tsx` |
| Create | `~/sunbites-portal/components/ui/input.test.tsx` |
| Modify | `~/sunbites-pos/components/ui/input.tsx` |
| Create | `~/sunbites-pos/components/ui/input.test.tsx` |

No other files change. All 11 existing `<Input type="password" />` usages across both apps automatically receive the toggle.

---

### Task 1: Portal — write failing tests

**Files:**
- Create: `~/sunbites-portal/components/ui/input.test.tsx`

- [ ] **Step 1: Create the test file**

`~/sunbites-portal/components/ui/input.test.tsx`:

```tsx
import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { Input } from "./input"

describe("Input", () => {
  it("renders without a toggle button for non-password types", () => {
    render(<Input type="text" placeholder="Name" />)
    expect(screen.getByPlaceholderText("Name")).toBeInTheDocument()
    expect(screen.queryByRole("button")).not.toBeInTheDocument()
  })

  it("renders a Show password toggle button for password type", () => {
    render(<Input type="password" placeholder="Password" />)
    expect(screen.getByPlaceholderText("Password")).toBeInTheDocument()
    expect(screen.getByRole("button", { name: "Show password" })).toBeInTheDocument()
  })

  it("shows the password when the toggle is clicked", async () => {
    const user = userEvent.setup()
    render(<Input type="password" placeholder="Password" />)

    expect(screen.getByPlaceholderText("Password")).toHaveAttribute("type", "password")

    await user.click(screen.getByRole("button", { name: "Show password" }))

    expect(screen.getByPlaceholderText("Password")).toHaveAttribute("type", "text")
    expect(screen.getByRole("button", { name: "Hide password" })).toBeInTheDocument()
  })

  it("hides the password when the toggle is clicked a second time", async () => {
    const user = userEvent.setup()
    render(<Input type="password" placeholder="Password" />)

    await user.click(screen.getByRole("button", { name: "Show password" }))
    await user.click(screen.getByRole("button", { name: "Hide password" }))

    expect(screen.getByPlaceholderText("Password")).toHaveAttribute("type", "password")
    expect(screen.getByRole("button", { name: "Show password" })).toBeInTheDocument()
  })
})
```

- [ ] **Step 2: Run tests — expect 3 to fail**

```bash
cd ~/sunbites-portal && npx jest components/ui/input.test.tsx --no-coverage
```

Expected: test 1 passes, tests 2–4 fail. Tests 2–4 fail with `Unable to find an accessible element with the role "button"` because the toggle button doesn't exist yet.

---

### Task 2: Portal — implement the password visibility toggle

**Files:**
- Modify: `~/sunbites-portal/components/ui/input.tsx`

- [ ] **Step 3: Replace the file contents**

`~/sunbites-portal/components/ui/input.tsx`:

```tsx
import * as React from "react"
import { Input as InputPrimitive } from "@base-ui/react/input"
import { Eye, EyeOff } from "lucide-react"

import { cn } from "@/lib/utils"

function Input({ className, type, ...props }: React.ComponentProps<"input">) {
  const [shown, setShown] = React.useState(false)
  const isPassword = type === "password"

  const input = (
    <InputPrimitive
      type={isPassword ? (shown ? "text" : "password") : type}
      data-slot="input"
      className={cn(
        "h-8 w-full min-w-0 rounded-lg border border-input bg-transparent px-2.5 py-1 text-base transition-colors outline-none file:inline-flex file:h-6 file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 disabled:pointer-events-none disabled:cursor-not-allowed disabled:bg-input/50 disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-3 aria-invalid:ring-destructive/20 md:text-sm dark:bg-input/30 dark:disabled:bg-input/80 dark:aria-invalid:border-destructive/50 dark:aria-invalid:ring-destructive/40",
        isPassword && "pr-8",
        className
      )}
      {...props}
    />
  )

  if (!isPassword) return input

  return (
    <div className="relative">
      {input}
      <button
        type="button"
        aria-label={shown ? "Hide password" : "Show password"}
        onClick={() => setShown((s) => !s)}
        className="absolute inset-y-0 right-0 flex items-center pr-2.5 text-muted-foreground hover:text-foreground"
      >
        {shown ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
      </button>
    </div>
  )
}

export { Input }
```

- [ ] **Step 4: Run tests — expect all 4 to pass**

```bash
cd ~/sunbites-portal && npx jest components/ui/input.test.tsx --no-coverage
```

Expected: 4 passed, 0 failed.

- [ ] **Step 5: Commit**

```bash
cd ~/sunbites-portal
git add components/ui/input.tsx components/ui/input.test.tsx
git commit -m "feat: add password visibility toggle to Input component"
```

---

### Task 3: POS — write failing tests

**Files:**
- Create: `~/sunbites-pos/components/ui/input.test.tsx`

- [ ] **Step 6: Create the test file**

`~/sunbites-pos/components/ui/input.test.tsx`:

```tsx
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { Input } from "./input";

describe("Input", () => {
  it("renders without a toggle button for non-password types", () => {
    render(<Input type="text" placeholder="Name" />);
    expect(screen.getByPlaceholderText("Name")).toBeInTheDocument();
    expect(screen.queryByRole("button")).not.toBeInTheDocument();
  });

  it("renders a Show password toggle button for password type", () => {
    render(<Input type="password" placeholder="Password" />);
    expect(screen.getByPlaceholderText("Password")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Show password" })).toBeInTheDocument();
  });

  it("shows the password when the toggle is clicked", async () => {
    const user = userEvent.setup();
    render(<Input type="password" placeholder="Password" />);

    expect(screen.getByPlaceholderText("Password")).toHaveAttribute("type", "password");

    await user.click(screen.getByRole("button", { name: "Show password" }));

    expect(screen.getByPlaceholderText("Password")).toHaveAttribute("type", "text");
    expect(screen.getByRole("button", { name: "Hide password" })).toBeInTheDocument();
  });

  it("hides the password when the toggle is clicked a second time", async () => {
    const user = userEvent.setup();
    render(<Input type="password" placeholder="Password" />);

    await user.click(screen.getByRole("button", { name: "Show password" }));
    await user.click(screen.getByRole("button", { name: "Hide password" }));

    expect(screen.getByPlaceholderText("Password")).toHaveAttribute("type", "password");
    expect(screen.getByRole("button", { name: "Show password" })).toBeInTheDocument();
  });
});
```

- [ ] **Step 7: Run tests — expect 3 to fail**

```bash
cd ~/sunbites-pos && npx jest components/ui/input.test.tsx --no-coverage
```

Expected: test 1 passes, tests 2–4 fail with `Unable to find an accessible element with the role "button"`.

---

### Task 4: POS — implement the password visibility toggle

**Files:**
- Modify: `~/sunbites-pos/components/ui/input.tsx`

- [ ] **Step 8: Replace the file contents**

`~/sunbites-pos/components/ui/input.tsx`:

```tsx
import * as React from "react";
import { Input as InputPrimitive } from "@base-ui/react/input";
import { Eye, EyeOff } from "lucide-react";

import { cn } from "@/lib/utils";

function Input({ className, type, ...props }: React.ComponentProps<"input">) {
  const [shown, setShown] = React.useState(false);
  const isPassword = type === "password";

  const input = (
    <InputPrimitive
      type={isPassword ? (shown ? "text" : "password") : type}
      data-slot="input"
      className={cn(
        "h-8 w-full min-w-0 rounded-lg border border-input bg-transparent px-2.5 py-1 text-base transition-colors outline-none file:inline-flex file:h-6 file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 disabled:pointer-events-none disabled:cursor-not-allowed disabled:bg-input/50 disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-3 aria-invalid:ring-destructive/20 md:text-sm dark:bg-input/30 dark:disabled:bg-input/80 dark:aria-invalid:border-destructive/50 dark:aria-invalid:ring-destructive/40",
        isPassword && "pr-8",
        className,
      )}
      {...props}
    />
  );

  if (!isPassword) return input;

  return (
    <div className="relative">
      {input}
      <button
        type="button"
        aria-label={shown ? "Hide password" : "Show password"}
        onClick={() => setShown((s) => !s)}
        className="absolute inset-y-0 right-0 flex items-center pr-2.5 text-muted-foreground hover:text-foreground"
      >
        {shown ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
      </button>
    </div>
  );
}

export { Input };
```

- [ ] **Step 9: Run tests — expect all 4 to pass**

```bash
cd ~/sunbites-pos && npx jest components/ui/input.test.tsx --no-coverage
```

Expected: 4 passed, 0 failed.

- [ ] **Step 10: Commit**

```bash
cd ~/sunbites-pos
git add components/ui/input.tsx components/ui/input.test.tsx
git commit -m "feat: add password visibility toggle to Input component"
```
