---
phase: 5
slug: inbound-webhooks
# status lifecycle: draft (seeded by plan-phase) → validated (set by validate-phase §6)
# audit-milestone §5.5 distinguishes NOT-VALIDATED (draft) from PARTIAL (validated + nyquist_compliant: false) (#2117)
status: validated
nyquist_compliant: true
wave_0_complete: true
created: 2026-08-06
validated: 2026-08-06
---

# Phase 5 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.
> Seeded from `05-RESEARCH.md` § Validation Architecture at plan time; audited against the shipped
> implementation after execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest `^4.0` on `orchestra/testbench` `^10.0\|^11.0` (Pest is locked by D-08 — never convert to PHPUnit) |
| **Config file** | `phpunit.xml.dist` |
| **Quick run command** | `php -d memory_limit=1G vendor/bin/pest tests/Feature/Webhooks tests/Unit/Webhooks` |
| **Full suite command** | `php -d memory_limit=1G vendor/bin/pest --coverage --min=100` |
| **Measured runtime** | quick run **0.96s** (131 tests) · full suite **23.5s** (1070 tests) |

> **`-d memory_limit=1G` is required, not optional.** A bare `vendor/bin/pest` dies with
> `Allowed memory size of 134217728 bytes exhausted` inside the `R9: strict_types` arch scan — a
> **false** failure that looks like a broken suite. `phpunit.xml.dist` sets no `memory_limit`, so
> every local runner hits this. CI is believed safe (`shivammathur/setup-php` defaults to unlimited)
> but the project may want an explicit `<ini name="memory_limit">` to remove the footgun.

---

## Sampling Rate

- **After every task commit:** the narrow Pest command for the changed test class
  (e.g. `php -d memory_limit=1G vendor/bin/pest tests/Feature/Webhooks/WebhookDedupeTest.php`)
- **After every plan wave:** `php -d memory_limit=1G vendor/bin/pest --coverage --min=100`, plus
  `vendor/bin/pint`, `vendor/bin/phpstan analyse`, `vendor/bin/phpcs`, and the architecture tests —
  all green, no disabled gates
- **Before `/gsd-verify-work`:** full suite must be green
- **Once per plan (not per commit):** scoped mutation —
  `vendor/bin/pest --mutate --parallel --min=80 --class="$(bash scripts/ci/mutation-scope.sh origin/main)"`
- **Max feedback latency:** ~1s for the webhook slice; 24s for the whole suite

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 05-01-01 | 01 | 1 | HOOK-01 | T-05-12a | R4 admits the framework but still rejects `HubSpot\*` from `Webhooks` | arch | `pest tests/Arch/ResolverSeamTest.php` | ✅ | ✅ green |
| 05-01-02 | 01 | 1 | HOOK-01 | T-05-01, T-05-02 | Signed request verified on the raw URI before parsing; unsorted query accepted | feature | `pest tests/Feature/Webhooks/InboundWebhookTracerTest.php` | ✅ | ✅ green |
| 05-01-03 | 01 | 1 | HOOK-01 | T-05-03, T-05-04, T-05-05 | Invalid/missing signature rejected with no handler run; 401/400/500/204 mapping | feature | `pest tests/Feature/Webhooks/InboundWebhookFailureTest.php` | ✅ | ✅ green |
| 05-02-01 | 02 | 2 | HOOK-03 | — | *(checkpoint:decision — schema and claim model chosen, option A)* | n/a — decision | *(recorded in 05-SECURITY.md and 05-02-SUMMARY.md)* | n/a | ✅ resolved |
| 05-02-02 | 02 | 2 | HOOK-01, HOOK-03 | T-05-06, T-05-07, T-05-11 | Feature-gated store; migration absent until enabled; payload opt-in and null by default | feature + unit | `pest tests/Feature/Webhooks/WebhookEventStoreTest.php tests/Feature/ServiceProviderWebhookStoreTest.php` | ✅ | ✅ green |
| 05-02-03 | 02 | 2 | HOOK-01, HOOK-03 | T-05-08, T-05-09 | Claim→dispatch→complete; stale lease reclaimed with incremented attempts; prune bounds retention | feature | `pest tests/Feature/Webhooks/WebhookDedupeTest.php tests/Feature/Webhooks/PruneWebhookEventsCommandTest.php` | ✅ | ✅ green |
| 05-03-01 | 03 | 3 | HOOK-01 | T-05-13 | Closed recognition table; no FQCN built from payload text | feature | `pest tests/Feature/Webhooks/TypedEventRoutingTest.php` | ✅ | ✅ green |
| 05-03-02 | 03 | 3 | HOOK-01 | T-05-12b, T-05-14 | Handler map incl. `'*'`, validated before anything runs; handlers run on the queue | feature + unit | `pest tests/Feature/Webhooks/HandlerMapTest.php tests/Unit/Webhooks/HandlerMapTest.php` | ✅ | ✅ green |
| 05-03-03 | 03 | 3 | HOOK-01 | T-05-15, T-05-16 | `assertWebhookHandled` on its own in-memory receipt log; recorded only after `complete()` | feature | `pest tests/Feature/Webhooks/AssertWebhookHandledTest.php` | ✅ | ✅ green |
| 05-04-01 | 04 | 4 | HOOK-02 | T-05-18, T-05-20 | Gateway-owned subscription port; no delete method exists; Service Key refused by construction | unit | `pest tests/Unit/Gateway/WebhookSubscriptionGatewayTest.php tests/Unit/Gateway/WebhookSubscriptionTest.php` | ✅ | ✅ green |
| 05-04-02 | 04 | 4 | HOOK-02 | T-05-19 | Desired-state declarations, app-model enum, `developer_api_key` redaction | unit + arch | `pest tests/Unit/Webhooks/AppModelTest.php tests/Feature/Webhooks/SubscriptionDeclarationsTest.php tests/Arch/SecretLoggingTest.php` | ✅ | ✅ green |
| 05-04-03 | 04 | 4 | HOOK-02 | T-05-17, T-05-21, T-05-22 | Non-destructive reconciliation; `--dry-run` mutates nothing; app named before first write | feature | `pest tests/Feature/Webhooks/SyncWebhookSubscriptionsCommandTest.php` | ✅ | ✅ green |
| 05-05-01 | 05 | 5 | HOOK-02 | T-05-23, T-05-24, T-05-25 | Legacy-private manual instructions; zero HTTP requests; "nothing was changed" asserted | feature | `pest tests/Feature/Webhooks/LegacyPrivateAppSetupTest.php` | ✅ | ✅ green |
| 05-05-02 | 05 | 5 | HOOK-02 | T-05-23, T-05-26 | Project webhook component export; zero HTTP requests; `--dry-run` suppresses the write | feature | `pest tests/Feature/Webhooks/ProjectWebhookComponentTest.php` | ✅ | ✅ green |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

**Sampling continuity:** satisfied with room to spare — every implementation task carries its own
automated command, so there is never even one consecutive task without automated verification, let
alone the three the Nyquist floor allows. The single non-automated entry (05-02-01) is a human
decision checkpoint, not a behavior, and has no code to verify.

---

## Wave 0 Requirements

All four Wave 0 gaps identified at plan time are closed:

- [x] `composer install` — `vendor/bin/pest` was absent when this file was seeded; dependencies are
      installed and the suite runs
- [x] `tests/Feature/Webhooks/` and `tests/Unit/Webhooks/` with fixtures — 12 feature files, 3 unit
      files, plus `tests/Support/Webhooks/` (9 fakes, handlers and call logs)
- [x] Inbound-receipt fake/assertion seam on the `HubspotManager`/facade contract — shipped as
      `src/Testing/WebhookReceiptLog.php` behind `Webhooks\Contracts\WebhookReceiptRecorder`, and it
      does **not** reuse the outbound Guzzle history, which was the specific constraint carried
      forward from `.planning/phases/02-gateway-layer/deferred-items.md`
- [x] Database event-store concurrency/retry tests plus feature-gated migration tests —
      `test_a_lost_reclaim_race_answers_held_rather_than_recursing`,
      `test_a_claim_older_than_the_lease_is_reclaimed_with_an_incremented_attempt_count`,
      `test_every_operation_names_the_migration_when_its_table_is_absent`, and
      `tests/Feature/ServiceProviderWebhookStoreTest.php`

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Live HubSpot portal delivery of a real signed webhook | HOOK-01 | Requires a real portal plus a public URL. CLAUDE.md forbids the default suite from ever hitting a live portal. | Opt-in, secret-gated integration suite only — never required to merge |

**Removed at audit time.** The seeded draft also listed *"rendered manual setup instructions for
legacy private apps"* as manual-only. That was wrong once 05-05 shipped: `LegacyPrivateAppSetupTest`
automates all six behaviors, including that the path issues **zero** HTTP requests and that the
"nothing was changed in HubSpot" line is asserted against a hardcoded literal. Only the genuinely
portal-dependent item remains manual.

---

## Validation Audit 2026-08-06

| Metric | Count |
|--------|-------|
| Requirements audited | 3 (HOOK-01, HOOK-02, HOOK-03) |
| Tasks audited | 14 across 5 plans |
| Gaps found | 0 |
| Resolved | 0 (none to resolve) |
| Escalated | 0 |
| Manual-only entries corrected | 1 (legacy-private instructions → automated) |

State A audit against the shipped tree. No `gsd-nyquist-auditor` spawn was required: every
requirement resolved to green automated tests, every Wave 0 gap was already closed by execution, and
no task lacked an automated command — so there was no gap set to hand an auditor.

Evidence sampled directly rather than inferred from the suite being green: the requirement→test
cross-reference above, the Wave 0 closure list, the watch-flag scan (none), and measured latency.

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Wave 0 covers all MISSING references
- [x] No watch-mode flags
- [x] Feedback latency measured and recorded (0.96s quick / 23.5s full)
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** approved 2026-08-06
