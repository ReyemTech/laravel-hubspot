# Deferred Items — Phase 2

Out-of-scope discoveries logged during execution, per the executor's scope-boundary rule
(only auto-fix issues directly caused by the current task's changes).

## 02-01

- **`composer validate --strict` reports a stale `composer.lock`** ("The lock file is not up to
  date with the latest changes in composer.json"). Confirmed pre-existing on `main` before any
  02-01 change (reproduced with `git stash` back to `65a7819`) — not caused by this plan, which
  makes zero `composer.json` changes. Out of scope for 02-01; needs a `composer update` (or
  equivalent) run as its own maintenance change, not mixed into a feature PR (STANDARDS §12c).
