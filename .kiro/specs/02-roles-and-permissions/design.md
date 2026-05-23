# Design 02 — Roles, Permissions & Authentication

## Screen: Login Page (POS App)

Already covered in Design 01. Same `AuthLayout`. Route: `pos.sunbites.com.ph/login`

**Error States specific to this spec:**
```
┌──────────────────────────────────────────────┐
│  ⚠️  Your account has no branch assigned.    │
│      Contact your administrator.              │
└──────────────────────────────────────────────┘
```
Shown as a red banner (`bg-destructive/10 border border-destructive/30 text-destructive`) above the form — appears when the API returns a no-branch error after valid credentials.

---

## Screen: User Management List

**Route:** `pos.sunbites.com.ph/references/users`
**Role:** Admin only
**Layout:** `KitchenLayout`

```
┌──────────────────────────────────────────────────────────────┐
│ References > User Management              [+ Add New User]   │
├──────────────────────────────────────────────────────────────┤
│  [Search by name or email...]      [Role ▾]  [Status ▾]     │
├──────────────────────────────────────────────────────────────┤
│  Name              Role         Branch(es)    Status  Actions│
├──────────────────────────────────────────────────────────────┤
│  👤 Maria Admin    [ADMIN]      All Branches  Active  [View] │
│  👤 Juan Manager   [MANAGER]    Antipolo      Active  [View] │
│  👤 Ana Cashier    [CASHIER]    Iloilo        Active  [View] │
│  👤 Deac User      [SUPERVISOR] Antipolo     ⚫ Inactive [View]│
└──────────────────────────────────────────────────────────────┘
  [← 1  2  3 →]
```

**Component Notes:**
- `[+ Add New User]`: primary button, top right
- Search input: debounced 300ms
- Role filter dropdown: All / Admin / Manager / Supervisor / Cashier
- Status filter: All / Active / Inactive
- Role badges: color-coded
  - `ADMIN` — `bg-primary/10 text-primary`
  - `MANAGER` — `bg-blue-50 text-blue-700`
  - `SUPERVISOR` — `bg-purple-50 text-purple-700`
  - `CASHIER` — `bg-muted text-foreground`
- Status: Active = green dot, Inactive = gray dot
- Branch(es): comma-joined pill list
- Deactivated rows: slightly muted opacity

---

## Screen: User Detail / Create Form

**Route:** `pos.sunbites.com.ph/references/users/create` and `pos.sunbites.com.ph/references/users/[id]/edit`
**Layout:** `KitchenLayout`, centered card max-width 720px

```
┌─────────────────────────────────────────────────────────┐
│ ← Back to Users           Create New Staff Account      │
├─────────────────────────────────────────────────────────┤
│                                                         │
│  ┌──── PERSONAL INFORMATION ──────────────────────┐    │
│  │  [Photo Upload - 80×80 avatar placeholder]     │    │
│  │                                                  │    │
│  │  First Name *        Last Name *                │    │
│  │  [____________]      [____________]             │    │
│  │  Middle Name         Nickname                   │    │
│  │  [____________]      [____________]             │    │
│  │  Birthday            Gender                     │    │
│  │  [date picker ]      [Select ▾    ]             │    │
│  │  Civil Status                                    │    │
│  │  [Select ▾         ]                            │    │
│  └──────────────────────────────────────────────────┘   │
│                                                         │
│  ┌──── CONTACT INFORMATION ───────────────────────┐    │
│  │  Phone                                          │    │
│  │  [____________]                                  │    │
│  │  Emergency Contact Name                         │    │
│  │  [____________]                                  │    │
│  │  Emergency Contact Phone    Relationship        │    │
│  │  [____________]             [____________]      │    │
│  └──────────────────────────────────────────────────┘   │
│                                                         │
│  ┌──── ADDRESS ───────────────────────────────────┐    │
│  │  Address Line                                   │    │
│  │  [__________________________________________]   │    │
│  │  City               Province       Zip Code    │    │
│  │  [__________]       [__________]   [______]    │    │
│  └──────────────────────────────────────────────────┘   │
│                                                         │
│  ┌──── EMPLOYMENT ────────────────────────────────┐    │
│  │  Position (Job Title)       Employment Type    │    │
│  │  [____________]             [Select ▾    ]     │    │
│  │  Date Hired                 Daily Rate (₱)     │    │
│  │  [date picker ]             [__________]       │    │
│  └──────────────────────────────────────────────────┘   │
│                                                         │
│  ┌──── ACCOUNT ───────────────────────────────────┐    │
│  │  Email Address *                                │    │
│  │  [__________________________________________]   │    │
│  │  Password *        Confirm Password *           │    │
│  │  [____________]    [____________]               │    │
│  │  Role *                                         │    │
│  │  [Select Role ▾  ]                             │    │
│  └──────────────────────────────────────────────────┘   │
│                                                         │
│  ┌──── GOVERNMENT IDs  (all optional) ────────────┐    │
│  │  ℹ️ These fields are optional. Fill in when    │    │
│  │     documents are available.                    │    │
│  │                                                  │    │
│  │  SSS Number           Pag-IBIG Number           │    │
│  │  [____________]       [____________]            │    │
│  │  PhilHealth Number    TIN Number (BIR)          │    │
│  │  [____________]       [____________]            │    │
│  └──────────────────────────────────────────────────┘   │
│                                                         │
│  ┌──── BRANCH ASSIGNMENT ─────────────────────────┐    │
│  │  Assign to Branches:                            │    │
│  │  ☑ Antipolo Branch   ☐ Iloilo Branch           │    │
│  └──────────────────────────────────────────────────┘   │
│                                                         │
│  [Cancel]                      [Create Staff Account]   │
└─────────────────────────────────────────────────────────┘
```

**Component Notes:**
- Section titles: `text-xs font-extrabold text-primary uppercase tracking-wider` with bottom border
- Photo upload: circle avatar placeholder, file input, preview on select
- Government IDs section: subtle info banner at top explaining fields are optional
- Role selector: shadcn `<Select>` — options: Admin, Manager, Supervisor, Cashier
- Branch assignment: shadcn `<Checkbox>` per branch
- Required fields marked with `*`
- Validation: inline error under each field
- Create button: primary, full-width on mobile

**Edit Mode additions:**
- "Deactivate Account" toggle (with confirmation dialog)
- "Send Password Reset Email" button
- Current assigned branches shown as checked checkboxes

---

## Screen: User Detail View (Admin perspective)

**Route:** `pos.sunbites.com.ph/references/users/[id]`

```
┌──────────────────────────────────────────────────────────┐
│ ← Users     Juan Manager                    [Edit] [⋮]  │
├──────────────────────────────────────────────────────────┤
│  ┌───────────────────────────────────────────────────┐   │
│  │  👤 (photo)   Juan dela Cruz                      │   │
│  │               MANAGER  ● Active                  │   │
│  │               Canteen Manager                    │   │
│  │               juan@email.com  · 09171234567      │   │
│  │               Hired: 06/01/2025                   │   │
│  └───────────────────────────────────────────────────┘   │
│                                                          │
│  [Personal Info]  [Employment]  [Gov't IDs]  [Branches]  │
│  ┌───────────────────────────────────────────────────┐   │
│  │  [tab content here]                               │   │
│  └───────────────────────────────────────────────────┘   │
└──────────────────────────────────────────────────────────┘
```

- Header card: photo, name, role badge, status, position, email, phone, hired date
- `[⋮]` menu: Deactivate / Send Reset Email / Delete (with guard)
- Tab navigation: Personal Info | Employment | Gov't IDs | Branches
- Branches tab shows assigned branches with "Remove" option and "Add Branch" button

---

## Role Badge Reference

```
 [ADMIN]       — bg-primary/10, text-primary, border-primary/20
 [MANAGER]     — bg-blue-50,    text-blue-700,  border-blue-200
 [SUPERVISOR]  — bg-purple-50,  text-purple-700, border-purple-200
 [CASHIER]     — bg-muted,      text-foreground, border-border
```

All badges: `text-[11px] font-bold px-3 py-1 rounded-full border`
