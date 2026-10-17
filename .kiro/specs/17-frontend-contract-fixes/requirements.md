# Requirements Document

## Introduction

Spec 16's contract freeze (task T-1) compared every API response with the TypeScript types the two frontends use (`.kiro/specs/16-backend-code-quality/contract-gaps.md`). Two of the mismatches are frontend bugs: the API is correct, and the screen reads it wrongly. This spec fixes them in the frontends only. **No backend file changes.**

| ID | App | Bug |
|---|---|---|
| F-1 | POS (`~/sunbites-pos`) | Reports → Inventory: the Stock Snapshot table is always empty, and once it has rows, the status filter, badges and colours never match. In History, the type filter and the paging are ignored, because the page sends the wrong parameter names |
| F-2 | Portal (`~/sunbites-portal`) | Student → Wallet tab: amounts of ₱1,000 or more show without the thousands separator (`PHP 1500.00`) |
| F-3 *(approved 2026-10-17)* | POS (`~/sunbites-pos`) | The cart and selected student survive a branch switch, so checkout sends the previous branch's items (500 today; 422 after Spec 16 B-24) |

The other T-1 findings are handled in Spec 16 as backend fixes: B-20 (menu item delete), B-21 (portal dashboard credit badge), B-22 (pre-registration birthday) and B-24 (checkout with another branch's items). None of them needs a frontend change.

**Depends on:** nothing. Both fixes match the API as it is today, and Spec 16 Track A guarantees those responses do not change. The fixes can ship before, during or after Spec 16.

---

## Requirements

### Requirement 1: POS inventory report shows the stock snapshot (F-1)

**User Story:** As a manager, I want the Inventory report's Stock Snapshot table to list every item with its stock status, so that I can see what is low or out without opening each item.

#### Acceptance Criteria

1. WHEN the Inventory report loads THEN the Stock Snapshot table SHALL list the rows the API returns in `items` (`GET /api/v1/reports/inventory`).
2. The status of each row SHALL be read as the API sends it: `"OK"`, `"LOW"`, `"OUT"` or `"OVER"` (uppercase, `InventoryItem::status`).
3. WHEN a row is shown THEN its badge SHALL show the existing label and colour for its status: OK ✓, LOW ⚠, OUT ✕, OVER ▲.
4. WHEN a status filter is chosen (OK, Low, Out, Over) THEN only rows with that status SHALL be shown. "All Status" SHALL show every row.
5. WHEN the API returns no items THEN the existing empty state, "No inventory data found.", SHALL be shown. WHEN the request fails THEN the existing error text, "Failed to load inventory data. Please try again.", SHALL be shown.
6. WHEN a History type is applied THEN the request SHALL send it as `type`. WHEN the History page changes THEN the request SHALL send it as `page`. Those are the names the API reads; today the page sends `log_type` and `history_page`, which the API ignores.
7. The dashboard's low-stock widget SHALL NOT change. It reads a different endpoint (`GET /dashboard`), which sends lowercase `out`/`low`, and it already works.

### Requirement 2: Portal wallet amounts are formatted as money (F-2)

**User Story:** As a parent, I want wallet transactions to show amounts like "PHP 1,500.00", so that they are easy to read and match the rest of the portal.

#### Acceptance Criteria

1. The `Transaction.amount` type SHALL match the API: a decimal string such as `"1500.00"` or `"-150.00"` (`GET /api/v1/portal/students/{student}/wallet`, bavix `amountFloat`).
2. WHEN a transaction is shown THEN its amount SHALL be converted to a number before formatting, so ₱1,000 or more shows with a thousands separator (`+PHP 1,500.00`).
3. A positive amount SHALL keep the `+` prefix and the green style. A negative amount SHALL keep the red style and show the minus sign (`PHP -150.00`), exactly as intended today.
4. `formatPHP` SHALL NOT change. Its other callers already receive numbers (verified against the T-1 fixtures).

### Requirement 4: POS cart is cleared when the branch changes (F-3) *(approved 2026-10-17)*

**User Story:** As a manager who works in more than one branch, I want the POS cart to empty when I switch branch, so that I never check out another branch's items or student.

#### Acceptance Criteria

1. WHEN the active branch changes from one branch to another THEN the cart SHALL be cleared (items, student, walk-in flag, payment method and notes, as `clearCart()` does).
2. WHEN the user logs out (branch becomes null) THEN the cart SHALL be cleared.
3. WHEN a branch is selected for the first time (null to a branch) or the page is hard-refreshed THEN the cart SHALL NOT be touched. That is the same guard `BranchCacheSync` already uses.

### Requirement 3: Tests and delivery

1. Each fix SHALL ship with tests in its app (Jest 30, React Testing Library, MSW 2), following `.claude/rules/testing.md`: the custom `render` from `@/__tests__/test-utils`, queries by role, label or text, and MSW at the network boundary.
2. Test data SHALL use the real API shape (string amounts, `items`, uppercase statuses). That shape is what hid both bugs from the existing tests.
3. Each app SHALL pass `npm run type-check`, `npm run lint` and `npm test`.
4. Each fix SHALL be one commit on its own branch in its own repo, with a PR to `staging`. No AI attribution in commits or PRs.
