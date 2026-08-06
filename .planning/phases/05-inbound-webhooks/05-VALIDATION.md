---
phase: 5
slug: inbound-webhooks
# status lifecycle: draft (seeded by plan-phase) → validated (set by validate-phase §6)
# audit-milestone §5.5 distinguishes NOT-VALIDATED (draft) from PARTIAL (validated + nyquist_compliant: false) (#2117)
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-08-06
---

# Phase 5 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.
> Seeded from `05-RESEARCH.md` § Validation Architecture.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest `^4.0` on `orchestra/testbench` `^10.0\|^11.0` (Pest is locked by D-08 — never convert to PHPUnit) |
| **Config file** | `phpunit.xml.dist` |
| **Quick run command** | `vendor/bin/pest tests/Feature/Webhooks tests/Unit/Webhooks -x` |
| **Full suite command** | `vendor/bin/pest --coverage --min=100` |
| **Estimated runtime** | TBD — `vendor/` is not installed in this workspace; measure on first Wave 0 run |

---

## Sampling Rate

- **After every task commit:** Run the narrow Pest command for the changed test class
  (e.g. `vendor/bin/pest tests/Feature/Webhooks/SignatureVerificationTest.php`)
- **After every plan wave:** Run `vendor/bin/pest --coverage --min=100`, plus
  `vendor/bin/pint`, `vendor/bin/phpstan analyse`, `vendor/bin/phpcs`, and the
  architecture tests — all green, no disabled gates
- **Before `/gsd-verify-work`:** Full suite must be green
- **Once per plan (not per commit):** scoped mutation —
  `vendor/bin/pest --mutate --parallel --min=80 --class="$(bash scripts/ci/mutation-scope.sh origin/main)"`
- **Max feedback latency:** TBD — set after the first measured quick run

---

## Per-Task Verification Map

*Populated by the planner/executor as tasks are created. Requirement → behaviour → command
mapping below is lifted from `05-RESEARCH.md` § Validation Architecture.*

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| TBD | 01 | 1 | HOOK-01 | T-05-01 | Invalid/missing signature rejected with no handler run (fails closed) | feature + unit + arch | `vendor/bin/pest tests/Feature/Webhooks tests/Unit/Webhooks -x` | ❌ W0 | ⬜ pending |
| TBD | TBD | TBD | HOOK-02 | — | Desired-state sync create/update/report-extras; dry-run mutates nothing | feature + unit | `vendor/bin/pest tests/Feature/Webhooks/SyncSubscriptionsCommandTest.php -x` | ❌ W0 | ⬜ pending |
| TBD | TBD | TBD | HOOK-03 | — | Audit table off by default; migration absent until enabled | feature + unit | `vendor/bin/pest tests/Feature/Webhooks/EventStoreTest.php -x` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `composer install` — `vendor/bin/pest` is absent in this workspace
- [ ] `tests/Feature/Webhooks/` and `tests/Unit/Webhooks/` directories with fixtures — cover HOOK-01 … HOOK-03
- [ ] Inbound-receipt fake/assertion seam on the `HubspotManager`/facade contract — must NOT reuse the
      outbound Guzzle history (carried forward from `.planning/phases/02-gateway-layer/deferred-items.md`)
- [ ] Database event-store concurrency/retry tests plus feature-gated migration tests

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Live HubSpot portal delivery of a real signed webhook | HOOK-01 | Requires a real portal + public URL; the default suite must never hit a live portal | Opt-in, secret-gated integration suite only — never required to merge |
| Rendered manual setup instructions for legacy private apps | HOOK-02 | Output is guidance a human follows in the HubSpot UI | Run `hubspot:webhooks:sync` against a legacy private-app config; read the rendered instructions |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency measured and recorded
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
