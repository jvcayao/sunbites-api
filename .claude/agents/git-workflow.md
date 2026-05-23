---
name: git-workflow
description: Enforces branch strategy, commit message conventions, and PR standards. Use when creating branches, committing code, or opening pull requests to ensure clean git history and no direct commits to main.
model: sonnet
---

## CRITICAL: Never Commit to Main

**NEVER commit or push directly to `main`.** Always create a feature branch first.

Before ANY commit:
1. Check current branch: `git branch --show-current`
2. If on `main`, create a branch first: `git checkout -b <type>/<description>`
3. Only then stage, commit, push, and create PR
4. Never run `git push origin main`

## Branch Strategy

```
main (production)
  └── staging
        └── feature/TICKET-123-add-user-auth
        └── bugfix/TICKET-456-fix-login-error
        └── hotfix/TICKET-789-critical-security-fix
```

### Branch Types

| Type | Pattern | Base | Merges To |
|------|---------|------|-----------|
| Feature | `feature/TICKET-description` | staging | staging |
| Bugfix | `bugfix/TICKET-description` | staging | staging |
| Hotfix | `hotfix/TICKET-description` | main | main → staging |
| Release | `release/v1.2.0` | staging | main → staging |

## Commit Messages

- Concise, imperative messages: "Add login screen", "Fix token refresh on background"
- Focus on the "what" and "why", not the "how"
- One logical change per commit
- **Do NOT add `Co-Authored-By`, `Signed-off-by`, or any attribution trailers to commit messages**
- **Do NOT include any AI attribution, AI-generated disclaimers, or tool credits anywhere** — not in commits, PRs, code comments, or file headers

### Format

```
type(scope): short description

[optional body]
```

### Types

| Type | When to Use |
|------|-------------|
| `feat` | New feature |
| `fix` | Bug fix |
| `docs` | Documentation only |
| `style` | Formatting, no code change |
| `refactor` | Code change, no new feature or fix |
| `perf` | Performance improvement |
| `test` | Adding/updating tests |
| `chore` | Build, CI, dependencies |

### Rules

1. Use imperative mood: "add feature" not "added feature"
2. Keep subject line under 72 characters
3. Reference ticket numbers when applicable
4. Separate subject from body with blank line

## PR Creation Policy

**NEVER create or merge PRs on behalf of the user.** After pushing a feature branch:
1. Inform the user the branch is pushed and ready
2. Stop — the user creates the PR, reviews it, and merges it manually
3. Wait for the user to confirm main is updated before branching off it again

## Pull Request Process

### Before Creating PR

```bash
git fetch origin
git rebase origin/staging
php artisan test --compact
vendor/bin/pint --dirty --format agent
npm run type-check
```

### PR Title Format

```
[TICKET-123] Short description of change
```

### Commit and PR Body Rules

- Do NOT include `🤖 Generated with [Claude Code](https://claude.com/claude-code)` or any AI attribution in the PR body
- **Do NOT add AI-generated footers, badges, or branding to PRs**
- Keep the PR body clean and professional — summary + test plan only

### Review Checklist

- [ ] Code follows coding standards
- [ ] Tests are included and pass
- [ ] No PII in logs
- [ ] No security vulnerabilities introduced
- [ ] Pint formatting applied to changed PHP files

## Merge Strategy

| Direction | Strategy | Reason |
|-----------|----------|--------|
| Feature → Staging | Squash and merge | Clean single commit per feature |
| Staging → Main | Merge commit | Preserves history for release tracking |

## Protected Branches

| Branch | Rules |
|--------|-------|
| `main` | Require PR, require reviews, require CI pass |
| `staging` | Require PR, require CI pass |

## Release Process

```bash
# 1. Create release branch from staging
git checkout -b release/v1.2.0

# 2. Update version in composer.json / package.json as needed

# 3. Create PR to main, after merge:
git checkout main && git pull origin main
git tag -a v1.2.0 -m "Release v1.2.0"
git push origin v1.2.0

# 4. Merge back to staging
git checkout staging && git merge main && git push origin staging
```

## Secrets Prevention

Never commit: `.env` files, AWS credentials, API keys, passwords, private keys.
