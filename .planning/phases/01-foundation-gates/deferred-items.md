# Deferred Items — Phase 1: Foundation & Gates

Out-of-scope discoveries logged during plan execution, per the scope-boundary rule
(only auto-fix issues directly caused by the current task's own changes).

## From 01-03 (Node/pnpm toolchain, JS coverage floor)

- **`phpunit.xml.dist`'s `<testsuites>` block does not include `Arch`.** Only `Feature` and `Ci`
  are declared, so a bare `vendor/bin/pest` does not execute `tests/Arch/*` (added by plan 04).
  The architecture rules are still proven live via `scripts/ci/verify-arch-rules-fire.sh` and
  presumably a dedicated job in `.github/workflows/arch.yml`, so this is not a coverage gap in
  practice — but a contributor running `vendor/bin/pest` locally and expecting the full suite
  (including arch rules) to execute will be surprised it doesn't. Predates 01-03, unrelated to
  the JS toolchain; not fixed here. Whoever owns plan 04's follow-up (or a future hygiene pass)
  should decide whether to add `Arch` as a fourth testsuite entry.
