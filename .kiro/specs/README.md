# Sunbites — Kiro Specs

## Spec Index

| # | Spec | Focus |
|---|---|---|
| 01 | [Project Foundation](./01-project-foundation/requirements.md) | Architecture, design system, layout shells, API client, auth, toast system |
| 02 | [Roles & Permissions](./02-roles-and-permissions/requirements.md) | Sanctum auth, Spatie roles, user management, staff profiles |
| 03 | [Branch & Tenant](./03-branch-tenant/requirements.md) | Branch scoping, active branch state, branch management |
| 04 | [Menu & Products](./04-menu-and-products/requirements.md) | POS menu items, weekly meal planner, inventory management |
| 05 | [Student Management](./05-student-management/requirements.md) | Enrollment, student profiles, QR codes, wallet top-up, subscription payments |
| 06 | [POS & Checkout](./06-pos-and-checkout/requirements.md) | Cart, QR scan, payment methods, wallet deduction, receipts, void |
| 07 | [Parent Portal](./07-parent-portal/requirements.md) | Parent auth, student linking, spending dashboard, wallet alerts, feedback |
| 08 | [Reports & Dashboard](./08-reports-and-dashboard/requirements.md) | Kitchen dashboard, sales/student/wallet/inventory reports, Excel exports |

---

## Build Order (Recommended)

```
01 → 02 → 03 → 04 → 05 → 06 → 07 → 08
```

Each spec depends on the previous. Do not begin a spec until the prior one's requirements are confirmed.

---

## Projects

| Project | Path | Purpose |
|---|---|---|
| Laravel API | `~/sunbites-api` | REST API, business logic, auth, activity logging |
| POS & Admin App | `~/sunbites-pos` | Next.js staff-facing application |
| Parent Portal | `~/sunbites-portal` | Next.js read-only portal for parents |

---

## Local Development Domains

| Service | Local | Staging | Production |
|---|---|---|---|
| Laravel API | `api.sunbites.test` (Sail/Docker, port 80) | `api-staging.sunbites.com.ph` | `api.sunbites.com.ph` |
| POS & Admin App | `localhost:3000` | — | `pos.sunbites.com.ph` |
| Parent Portal | `localhost:3001` | — | `portal.sunbites.com.ph` |

All API routes are prefixed `/api/v1/`. The Next.js apps communicate with the API over HTTP/HTTPS and authenticate via Sanctum Bearer tokens.

---

## Key Packages

| Package | Purpose |
|---|---|
| `laravel/sanctum` | Token-based API authentication |
| `spatie/laravel-permission` | Roles and permissions |
| `bavix/laravel-wallet` | Student digital wallet (balance, deposits, charges) |
| `maatwebsite/excel` | Excel/CSV report exports |
| `spatie/laravel-activitylog` | Audit trail for all kitchen actions |

---

## Design References

| Reference | Use |
|---|---|
| Design tokens | See Spec 01 design.md for all Tailwind v4 color tokens and typography scale |
| POS app layouts | `KitchenLayout` (collapsible sidebar) + `AuthLayout` (centered card) — see Spec 01 |
| Portal app layouts | `PortalLayout` (top nav) + `AuthLayout` — see Spec 01 |

**Color scheme:** Tomato red primary (`oklch(0.577 0.245 27.325)`). Full token set in Spec 01.  
**Logo:** `AppLogo` React component with `full` and `icon` variants. Defined in each Next.js app.  
**Font:** Poppins (weights 400, 600, 700, 800) loaded from Google Fonts.
