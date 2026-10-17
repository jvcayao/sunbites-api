# Spec 17: Frontend Contract Fixes · Implementation Plan

## Ground rules (read first)

- **Frontend only.** Do not change anything in `~/sunbites-api` except ticking boxes in this file.
- Each app's `AGENTS.md` says its Next.js version differs from your training data. These fixes use no Next.js API, but read the relevant guide in `node_modules/next/dist/docs/` if you touch anything Next-specific.
- Follow `~/sunbites-api/.claude/rules/frontend/frontend-standards.md` and `.claude/rules/testing.md`. Load the `react-component-expert` guidance for component edits.
- **Make exactly the edits in `design.md`.** If anything there does not match the code you find, STOP and report to the user. Do not improvise.
- **Branches:**
  - Before you start in a repo, run `git status --porcelain` and `git branch --show-current`. **If the working tree is not clean, STOP and ask the user.** On 2026-10-17 the POS was on `bump-latest-upgrade` with a modified `package-lock.json`. That is the user's work in progress: do not stash, commit, discard or carry it.
  - Once the tree is clean: `git fetch origin && git switch -c <branch> origin/staging`.
- **Verification (each repo, all must pass):** `npm run type-check`, `npm run lint`, `npm test`.
- **Commits:** one per fix, `type(scope): description` under 72 characters. **No `Co-Authored-By`, no "Generated with" line, no AI attribution of any kind.** Never push, open or merge a PR, amend, rebase or force anything. The user pushes and opens each PR to `staging`.
- Stop after each task and report: the branch, the commit hash, the test output (passed/total), the files changed, and the smoke-test steps from design §4.

## Tasks

- [ ] 1. F-1: POS inventory report snapshot *(design §1; Req 1, 3)*
  - Repo `~/sunbites-pos`, branch `fix/spec17-inventory-report-snapshot`.
  - [ ] 1.1 Write `app/(kitchen)/reports/inventory/page.test.tsx` (design §1.4). Run it and confirm tests 1, 2, 6 and 7 fail on the current code.
  - [ ] 1.2 Apply the design §1.3 edits to `lib/api/reports.ts` and `app/(kitchen)/reports/inventory/page.tsx`. Leave `app/(kitchen)/dashboard/page.tsx` unchanged.
  - [ ] 1.3 Run all three verification commands. Commit `fix(reports): F-1 show inventory snapshot rows and statuses`.
  - _Requirements: 1.1–1.7, 3.1–3.4_

- [ ] 2. F-2: Portal wallet amounts *(design §2; Req 2, 3)*
  - Repo `~/sunbites-portal`, branch `fix/spec17-wallet-amount-format`.
  - [ ] 2.1 Update `wallet-tab.test.tsx` (design §2.4). Run it and confirm the `+PHP 1,500.00` assertion fails on the current code.
  - [ ] 2.2 Apply the design §2.3 edits to `types/portal.ts` and `wallet-tab.tsx`. Leave `lib/format.ts` unchanged.
  - [ ] 2.3 Run all three verification commands. Commit `fix(wallet): F-2 format wallet amounts with separators`.
  - _Requirements: 2.1–2.4, 3.1–3.4_

- [ ] 3. F-3: Clear the POS cart on a branch switch *(approved 2026-10-17)* *(design §3; Req 4, 3)*
  - Repo `~/sunbites-pos`, branch `fix/spec17-clear-cart-on-branch-switch`.
  - [ ] 3.1 Extend `components/branch-cache-sync.test.tsx` (design §3.3). Confirm the two clearing tests fail on the current code.
  - [ ] 3.2 Apply the design §3.2 edit to `components/branch-cache-sync.tsx`.
  - [ ] 3.3 Run all three verification commands. Commit `fix(pos): F-3 clear the cart when the branch changes`.
  - _Requirements: 4.1–4.3, 3.1–3.4_

## Coverage check

| Requirement | Task |
|---|---|
| 1.1–1.7 | 1.1–1.3 |
| 2.1–2.4 | 2.1–2.3 |
| 3.1–3.4 | 1.1, 1.3, 2.1, 2.3, 3.1, 3.3 |
| 4.1–4.3 | 3.1–3.3 |
