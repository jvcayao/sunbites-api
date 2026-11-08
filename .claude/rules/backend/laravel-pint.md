# Laravel Pint: mandatory after every backend task

Applies to every AI agent and subagent (including `clean-code-architect`, `laravel-simplifier` and spec implementers) working in `~/sunbites-api`. It covers any change to a PHP file: `app/`, `bootstrap/`, `config/`, `database/`, `routes/`, `tests/`.

This rule is **non-negotiable**. A backend task is not done until Pint has run.

## When to run

1. **After completing every backend task**, before reporting it as done and before committing:

   ```bash
   vendor/bin/sail bin pint --dirty --format agent
   ```

2. **Before pushing a branch** (or at the end of multi-task work), format anything `--dirty` may have missed. CI runs `pint --test` on the whole repo, so this must leave nothing to change:

   ```bash
   vendor/bin/sail bin pint --format agent
   ```

## How to run

- Always run Pint **through Sail** (`vendor/bin/sail bin pint …`). Never use `./vendor/bin/pint` or `php vendor/bin/pint` from the host. If Sail is not running, start it with `vendor/bin/sail up -d`.
- Pint uses the project's `pint.json` (`"preset": "laravel"`). Do not change `pint.json`, add ignore paths, or disable rules without the user's approval.
- Include any files Pint changes in the **same commit** as the task.
- Pint only changes formatting. If it touched files, re-run the tests for those files anyway, to confirm nothing else changed.

## Reporting

In the task summary (and the commit body for Spec 16 work), state the result: `Pint: ran — N file(s) fixed` or `Pint: ran — no changes`.

## Relationship to other rules

- Pint enforces **style only**. It does not replace the `laravel-best-practices` skill, the Laravel 13 standards in `.kiro/specs/16-backend-code-quality/design.md` §14, or tests.
- `.claude/agents/git-workflow.md` shows `vendor/bin/pint --dirty --format agent` without Sail. Follow this rule and run it through Sail.
