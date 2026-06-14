---
inclusion: always
---

# Tech Steering

## Stack

| Layer | Technology | Version |
|---|---|---|
| API backend | Laravel | 13 |
| PHP | PHP | 8.5 |
| POS & admin frontend | Next.js (App Router) | [NEEDS INPUT: confirm Next.js version] |
| Parent portal frontend | Next.js (App Router) | [NEEDS INPUT: confirm Next.js version] |
| React | React | 19 |
| Database | MySQL (via Docker/Sail) | [NEEDS INPUT: MySQL version] |
| Container / local dev | Laravel Sail | 1 |

## Libraries and Tools

### Backend (Laravel API — `~/sunbites-api`)

| Package | Purpose |
|---|---|
| `laravel/sanctum` v4 | Token-based API auth for both staff and parent guards |
| `spatie/laravel-permission` | Role-based access control: admin, manager, supervisor, cashier |
| `bavix/laravel-wallet` | Student digital wallet — all balance mutations go through wallet transactions |
| `spatie/laravel-activitylog` | Audit trail for key model events and kitchen actions |
| `maatwebsite/excel` | Excel/CSV report exports |
| `laravel/reverb` | First-party WebSocket server — real-time broadcasting for notifications |
| `laravel/pint` v1 | PHP code formatter — run `vendor/bin/sail bin pint --dirty --format agent` after every PHP change |
| `phpunit/phpunit` v12 | Test runner |

### Frontend (both Next.js apps)

| Package | Purpose |
|---|---|
| `@tanstack/react-query` | All server state — `useQuery` / `useMutation`; never raw `fetch + useState` |
| `zod` v4 | Form validation schemas in `lib/validation/{domain}.ts` |
| `tailwindcss` v4 | Utility-first styling with `@theme` CSS variables |
| `shadcn/ui` | Base component library — extend via `className`, don't fork source |
| `jest` v30 | Test runner |
| `@testing-library/react` v16 | Component rendering and DOM querying |
| `msw` v2 | API mocking at network boundary in tests |
| `zustand` | Cross-cutting client state (auth session, active branch, theme) |
| `laravel-echo` | WebSocket client — subscribes to Reverb private channels for real-time events |
| `pusher-js` | Required peer dependency of laravel-echo (Reverb uses the Pusher protocol) |

## Architecture Patterns

### Two Auth Guards

Never mix guards:

| Guard | Model | Table | Token ability | Route file |
|---|---|---|---|---|
| `auth:sanctum` | `User` | `users` | `staff` | `routes/kitchen-api.php` |
| `auth:parents` | `ParentUser` | `parents` | `parent` | `routes/portal-api.php` |

### Branch Scoping

Every model that uses `HasBranch` gets a global `BranchScope` applied automatically. Active branch set per-request via `X-Branch-Id` header → `app('active_branch')`. Bypass only when there is a documented cross-branch reason: `Model::withoutBranch()->get()`.

### Student Wallet

Use **only** `bavix/laravel-wallet` API for balance changes. Never write directly to the `wallets` table or update balance columns manually.

### Activity Logging

Key models use `spatie/laravel-activitylog` via `LogsActivity` trait. Check if a model logs automatically before adding manual `activity()` calls.

### API Route Architecture

| File | Prefix | Consumer | Auth |
|---|---|---|---|
| `routes/kitchen-api.php` | `/api/v1/` | POS / admin app | `auth:sanctum` + ability `staff` |
| `routes/portal-api.php` | `/api/v1/portal/` | Parent portal | `auth:parents` + ability `parent` |

Staff role hierarchy for middleware: `admin > manager > supervisor > (regular staff / cashier)`.

### Real-Time Broadcasting (Reverb)

Laravel Reverb is the WebSocket server. Broadcasting uses **private channels** so only the authenticated parent receives their own notifications.

**Backend setup:**
- `routes/channels.php` — `Broadcast::channel('parents.{parentId}', ...)` validates the authenticated parent matches the channel ID
- Notification class implements `ShouldBroadcast`; `broadcastOn()` returns `PrivateChannel("parents.{$parent->id}")`
- Channel auth route: `POST /api/v1/portal/broadcasting/auth` — scoped to `auth:parents`

**Frontend setup (portal only):**
- `EchoProvider` (`components/providers/echo-provider.tsx`) — Client Component; initialises `laravel-echo` once with the Sanctum Bearer token from Zustand store
- Wrapped around the portal layout in `app/(portal)/layout.tsx`
- Components subscribe via `echo.private('parents.{id}').listen('EventName', callback)` then `queryClient.invalidateQueries(...)` on event receipt

**Environment variables (Next.js):**
```
NEXT_PUBLIC_REVERB_APP_KEY
NEXT_PUBLIC_REVERB_HOST
NEXT_PUBLIC_REVERB_PORT
NEXT_PUBLIC_REVERB_SCHEME   ← 'https' in production, 'http' in local dev
```

**Laravel Cloud deployment:** Add `type: reverb` worker in `laravel.cloud` config. Cloud provisions the Reverb server, terminates TLS, and provides the env vars automatically.

### Frontend Data Fetching

- Server state (API data): TanStack Query
- Local UI state: `useState` / `useReducer`
- Cross-cutting client state: Zustand (`lib/store/`)
- All API calls through typed service modules in `lib/api/` — never `fetch` directly in components

### API Service Layer Pattern

```typescript
// lib/api/{domain}.ts
export const domainApi = {
  list: (params?) => apiClient.get<PaginatedResponse<Model>>('/route', { params }),
  show: (id: number) => apiClient.get<Model>(`/route/${id}`),
};
```

`apiClient` in `lib/api/client.ts` handles: Bearer token, base URL, error parsing, 401 logout trigger.

## Standards

### PHP
- PHP 8.5 features: constructor property promotion, readonly properties, enums
- Explicit return type declarations on all methods
- Form Request classes for validation — never inline `$request->validate()`
- `$request->validated()` only — never `$request->all()`

### TypeScript / React
- No `any` — use `unknown` with narrowing or define the proper type
- Named exports only — no default exports for components
- `"use client"` only when needed (hooks, browser APIs, event listeners)
- Push Client Components to leaf nodes

### Testing (backend)
- `RefreshDatabase` on every Feature test
- Always `actingAs($user, 'sanctum')` or `actingAs($parent, 'parents')`
- Real database — never mock Eloquent or the database layer
- Run: `vendor/bin/sail artisan test --compact tests/Feature/Foo.php`

### Testing (frontend)
- Import from `__tests__/test-utils.tsx` (not directly from `@testing-library/react`)
- Role and label queries first: `getByRole`, `getByLabelText`, `getByText`
- MSW for API mocking at network boundary — never mock `fetch` directly

## Constraints

- No Inertia.js — this is a pure JSON REST API; only Blade files are transactional emails
- No Wayfinder imports in frontend apps
- No `console.log` in committed code
- No hardcoded API URLs — always `process.env.NEXT_PUBLIC_API_URL`
- No secrets in `NEXT_PUBLIC_` env vars
- No `localStorage` for auth tokens
- Commands must run through Sail: `vendor/bin/sail artisan ...`, `vendor/bin/sail bin pint ...`

## Integration Points

| Service | Local | Staging | Production |
|---|---|---|---|
| Laravel API | `api.sunbites.test` (Sail, port 80) | `api-staging.sunbites.com.ph` | `api.sunbites.com.ph` |
| POS & admin | `localhost:3000` | — | `pos.sunbites.com.ph` |
| Parent portal | `localhost:3001` | — | `portal.sunbites.com.ph` |
