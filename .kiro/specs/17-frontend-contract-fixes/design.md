# Design Document

Every fact below was checked against the code on 2026-10-17. The API shapes come from the Spec 16 contract fixtures (`tests/Fixtures/contracts/`, local only) and the controllers named.

## 1. F-1: POS inventory report (`~/sunbites-pos`)

### 1.1 What the API sends

`Kitchen\InventoryReportController::index` (`~/sunbites-api`, L67-105) returns:

```json
{
  "summary": { "out_of_stock": 0, "below_threshold": 1, "over_stock": 0, "total_items": 2 },
  "items": [
    { "id": 1, "name": "Rice", "unit": "kg", "quantity": 4.5, "restock_threshold": 5,
      "overstock_threshold": null, "cost_per_unit": 52, "status": "LOW",
      "updated_at": "2026-10-15 09:30:00" }
  ],
  "logs": { "data": [ … ], "meta": { … } },
  "discrepancy": [ … ]
}
```

- `status` comes from `InventoryItem::status` (`app/Models/InventoryItem.php` L79-82): `'OUT'`, `'LOW'`, `'OVER'` or `'OK'`, always uppercase.
- `summary`, `items`, `logs` and `discrepancy` are always returned. The endpoint accepts only `from`, `to`, `type` (`restock`, `waste`, `manual`, `sale`) and `item_id` (L21-26). It ignores any other parameter, including `include_logs` and `include_discrepancy`.
- `logs` is paginated with Laravel's `paginate(25)` (L63), which reads the standard **`page`** query parameter. `logs.meta` has `current_page`, `last_page`, `per_page` and `total`.

### 1.2 What the page does wrong

| File:line | Today | Problem |
|---|---|---|
| `lib/api/reports.ts:163` | `InventoryReportStatus = "out" \| "low" \| "ok" \| "over"` | lowercase; the API sends uppercase |
| `lib/api/reports.ts:381` | `data: InventoryReportRow[]` | the API key is `items` |
| `lib/api/reports.ts:382-386` | `summary` without `total_items` | incomplete (harmless) |
| `app/(kitchen)/reports/inventory/page.tsx:236` | `const rows = data?.data ?? [];` | always `[]`, so the table is always empty |
| `page.tsx:56-68` | `STATUS_BADGE_MAP` / `STATUS_LABEL_MAP` keyed `out`/`low`/`ok`/`over` | no match for uppercase, so the badge is blank |
| `page.tsx:176-182` | `STATUS_FILTERS` values `ok`/`low`/`out`/`over` | the filter never matches |
| `page.tsx:395-396` | `row.status === "out"` / `"low"` | the colour never applies |
| `page.tsx:228` | history query sends `log_type` | the API reads `type`, so the History type filter is ignored and always shows every type |
| `page.tsx:230` | history query sends `history_page` | the API reads `page`, so Next/Previous always returns page 1 while the "Showing x–y of z" text moves |

The summary cards already work, because their keys match.

### 1.3 Change (exactly these edits; nothing else in the file)

1. `lib/api/reports.ts`:
   - Replace the union with the app's existing uppercase type, keeping the exported name the page imports:
     ```ts
     import type { InventoryStatus } from "@/types/inventory";

     export type InventoryReportStatus = InventoryStatus;
     ```
     `types/inventory.ts:1` already defines `InventoryStatus = "OK" | "LOW" | "OUT" | "OVER"`. Place the `import type` with the file's other type imports, following the import-order rule in `.claude/rules/frontend/frontend-standards.md`.
   - In `reportApi.inventory`'s response type, rename `data: InventoryReportRow[]` to `items: InventoryReportRow[]`, and add `total_items: number;` to `summary`.
2. `app/(kitchen)/reports/inventory/page.tsx`:
   - L236: `const rows = data?.items ?? [];`
   - `STATUS_BADGE_MAP` and `STATUS_LABEL_MAP`: rename the keys to `OUT`, `LOW`, `OK`, `OVER`. The class strings and labels stay the same.
   - `STATUS_FILTERS`: change the values to `"OK"`, `"LOW"`, `"OUT"`, `"OVER"`. The labels ("OK", "Low", "Out", "Over") and the `""` "All Status" entry stay the same.
   - L395-396: `row.status === "OUT"` and `row.status === "LOW"`.
   - History query (L224-233): rename `log_type:` to `type:` and `history_page:` to `page:`. The values stay the same. Leave `include_logs` and `include_discrepancy` alone (the API ignores them). The unused `InventoryReportFilters` interface (`lib/api/reports.ts:198-205`) already uses `type` and `page`, which confirms the names.
3. **Do not change** `app/(kitchen)/dashboard/page.tsx:236-242`. That widget reads `GET /dashboard`, where `DashboardController` L68 sends lowercase `'out'`/`'low'`.
4. Run `npm run type-check`. The compiler then flags any leftover lowercase key or comparison. There must be none.

### 1.4 Test (new file `app/(kitchen)/reports/inventory/page.test.tsx`)

Model it on `app/(kitchen)/reports/credits/page.test.tsx`: the same `next/navigation` mock, the same `@/components/ui/select` mock (a native `<select>`), `useAuthStore` set to a manager, and the custom `render`.

- MSW handler `http.get(\`${API}/reports/inventory\`, …)` returns the §1.1 shape with three items: `Rice` `LOW`, `Eggs` `OUT` and `Oil` `OK`. Include `logs: { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } }` and `discrepancy: []`, so the history section renders too.
- Tests:
  1. The snapshot lists `Rice`, `Eggs` and `Oil`.
  2. The badges show `LOW ⚠` and `OUT ✕`.
  3. Choosing "Low" in the status filter leaves only `Rice`; "All Status" brings all three back.
  4. `items: []` shows "No inventory data found."
  5. A 500 response shows "Failed to load inventory data. Please try again."
  6. Choosing the "Waste" history type and applying the filter sends a request with `type=waste` (read `new URL(request.url).searchParams` in the MSW handler) and no `log_type`.
  7. With `logs.meta.last_page: 2`, clicking the history Next button sends a request with `page=2` and no `history_page`.
- Tests 1, 2, 6 and 7 fail on the current code, which proves the fix.

## 2. F-2: Portal wallet amounts (`~/sunbites-portal`)

### 2.1 What the API sends

`GET /api/v1/portal/students/{student}/wallet` returns `data[].amount` as a decimal string: bavix `amountFloat`, e.g. `"1500.00"` for a top-up and `"-150.00"` for a purchase. `balance`, `credit_balance` and `wallet_alert_threshold` are numbers (contract fixture `get__api_v1_portal_students_{student}_wallet@ok.json`).

### 2.2 What the page does wrong

- `types/portal.ts:85` types `Transaction.amount` as `number`.
- `app/(portal)/students/[id]/_components/wallet-tab.tsx:51` calls `formatPHP(tx.amount)`. `formatPHP` (`lib/format.ts:5-10`) calls `amount.toLocaleString("en-PH", …)`. On a string, `String.prototype.toLocaleString` ignores the options and returns the string unchanged, so the result is `PHP 1500.00`.
- `wallet-tab.tsx:37` (`tx.amount >= 0`) works only through JavaScript's string-to-number coercion.
- The existing test (`wallet-tab.test.tsx:17`) mocks `amount: 500`, a number, so it never saw the real shape. Its assertion `+PHP 500.00` cannot show the bug, because 500 has no thousands separator.

### 2.3 Change

1. `types/portal.ts`, `Transaction`:
   ```ts
   /** Decimal string from the wallet, e.g. "1500.00" or "-150.00". Convert with Number() before maths or formatting. */
   amount: string;
   ```
2. `wallet-tab.tsx`, `TransactionRow`:
   ```ts
   const amount = Number(tx.amount);
   const isCredit = amount >= 0;
   ```
   and render `{formatPHP(amount)}` instead of `{formatPHP(tx.amount)}`.
3. **Do not change** `formatPHP` or any other caller. The other eight callers get numbers (`wallet_balance`, `credit_balance`, `balance`, `wallet_alert_threshold`, order `total`, `spending_total`), as the T-1 fixtures show.

### 2.4 Test (update `app/(portal)/students/[id]/_components/wallet-tab.test.tsx`)

- `mockWallet.data[0].amount`: change `500` to `"1500.00"` (the real API shape), and add a second transaction `{ id: 2, type: "withdraw", amount: "-150.00", meta: null, created_at: "2026-06-02T10:00:00Z" }`. Keep the `meta` block consistent (`total: 2`).
- Change the existing assertion `/\+PHP 500\.00/i` to `/\+PHP 1,500\.00/`.
- Add: the withdrawal row shows `PHP -150.00`.
- The updated assertion fails on the current code (`+PHP 1500.00`), which proves the fix.
- Leave the other tests in the file unchanged. They exercise filters, not amounts.

## 3. Smoke test on staging

- **POS:** Reports → Inventory. The Stock Snapshot lists items with coloured badges, and the status filter narrows the list. In History, the type filter narrows the log, and with more than 25 log rows, Next shows different rows.
- **Portal:** a student's Wallet tab with a top-up of ₱1,000 or more shows `+PHP 1,000.00` style amounts, with purchases in red.
