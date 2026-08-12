---
phase: 6
slug: signals-core
# status lifecycle: draft (seeded by plan-phase) → validated (set by validate-phase §6)
# audit-milestone §5.5 distinguishes NOT-VALIDATED (draft) from PARTIAL (validated + nyquist_compliant: false) (#2117)
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-08-11
---

# Phase 6 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest `^4.0` (verified in `composer.json`), running on PHPUnit via `phpunit.xml.dist` |
| **Config file** | `phpunit.xml.dist` — Feature, Unit, Ci and Arch suites already defined; no Signals-specific suite needed |
| **Quick run command** | `vendor/bin/pest --filter=Signals` |
| **Full suite command** | `vendor/bin/pest --coverage --min=100` |
| **Estimated runtime** | ~5s filtered, ~90s full suite with coverage |

`phpunit.xml.dist` carries `<ini name="memory_limit">`, which is why `vendor/bin/pest` needs no
memory flag while `vendor/bin/phpstan` does (`--memory-limit=512M`).

---

## Sampling Rate

- **After every task commit:** `vendor/bin/pest --filter=Signals`
- **After every plan wave:** `vendor/bin/pest --coverage --min=100`
- **Before `/gsd-verify-work`:** full suite green, plus `vendor/bin/pint`,
  `vendor/bin/phpstan analyse --memory-limit=512M`, `vendor/bin/phpcs`,
  `bash scripts/ci/verify-arch-rules-fire.sh`, and one scoped mutation run at `--min=80`
- **Max feedback latency:** ~5 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 6-01-01 | 01 | 1 | SIG-01, SIG-02 | T-06-01 / T-06-03 / T-06-04 | Byte-bounded `visitor_id`/`signal_name` refused rather than truncated; anonymous rows unflushable; `upsertMany()` never the singular `upsert()` | feature (tracer) | `vendor/bin/pest tests/Feature/Signals/SignalTracerTest.php tests/Arch/LayerBoundariesTest.php` | ❌ W0 | ⬜ pending |
| 6-01-02 | 01 | 1 | SIG-01 | — | Widened R5 still rejects `HubSpot\*` from `Signals` | arch | `vendor/bin/pest tests/Arch/ResolverSeamTest.php && bash scripts/ci/verify-arch-rules-fire.sh` | ✅ (extends existing) | ⬜ pending |
| 6-01-03 | 01 | 1 | SIG-01 | T-06-02 | Missing table is a directed error naming the fix, not a raw `QueryException`; message carries no visitor data | feature | `vendor/bin/pest tests/Feature/Signals/MigrationGateTest.php tests/Feature/PackageSkeletonTest.php` | ❌ W0 | ⬜ pending |
| 6-02-01 | 02 | 2 | SIG-03 | T-06-06 | Class-string validated with `class_exists` + `is_a(..., true)`, never instantiated at validation | unit | `vendor/bin/pest tests/Unit/Signals/MergeRuleTest.php` | ❌ W0 | ⬜ pending |
| 6-02-02 | 02 | 2 | SIG-03 | T-06-07 | Exception messages carry config-shaped values only | unit | `vendor/bin/pest tests/Unit/Signals/SignalMapTest.php` | ❌ W0 | ⬜ pending |
| 6-02-03 | 02 | 2 | SIG-03 | T-06-08 / T-06-09 | Strict `=== true` guard; validation costs nothing when signals are off | feature | `vendor/bin/pest tests/Feature/Signals/SignalMapBootTest.php tests/Feature/Signals/SignalRecorderTest.php` | ❌ W0 | ⬜ pending |
| 6-03-01 | 03 | 2 | SIG-05 | T-06-12 | No factory signature accepts a buffered payload — pinned by reflection | unit | `vendor/bin/pest tests/Unit/Exceptions/SignalExceptionTest.php` | ❌ W0 | ⬜ pending |
| 6-03-02 | 03 | 2 | SIG-05 | T-06-10 / T-06-11 / T-06-14 | Rebinding refused; backfill is one conditional UPDATE; zero HTTP on the path | feature | `vendor/bin/pest tests/Feature/Signals/IdentityResolverTest.php` | ❌ W0 | ⬜ pending |
| 6-03-03 | 03 | 2 | SIG-05 | T-06-13 | Shared-device merge documented and observable | feature | `vendor/bin/pest tests/Feature/Signals/IdentityResolverTest.php tests/Feature/FacadeContractTest.php` | ❌ W0 | ⬜ pending |
| 6-04-CP | 04 | 3 | SIG-04 | — | Blocking decision checkpoint — tie-break rule (one-way with D-10) | manual | n/a — `checkpoint:decision`, gate `blocking` | n/a | ⬜ pending |
| 6-04-01 | 04 | 3 | SIG-04 | T-06-15 / T-06-16 / T-06-18 / T-06-19 | `sum` throws on non-numeric rather than coercing; `flushed_at` never an input; escape hatch sees one subject's matching signals only | unit | `vendor/bin/pest tests/Unit/Signals/RollUpCalculatorTest.php` | ❌ W0 | ⬜ pending |
| 6-04-02 | 04 | 3 | SIG-04 | T-06-17 | Plain-decimal rendering; loss-bearing totals refused | unit + mutation | `vendor/bin/pest tests/Unit/Signals/RollUpCalculatorTest.php && vendor/bin/pest --mutate --parallel --min=80 --class="ReyemTech\Hubspot\Signals\RollUpCalculator"` | ❌ W0 | ⬜ pending |
| 6-05-CP | 05 | 3 | SIG-07 | T-06-21 | Blocking decision checkpoint — trail column shape (one-way per D-06) | manual | n/a — `checkpoint:decision`, gate `blocking` | n/a | ⬜ pending |
| 6-05-01 | 05 | 3 | SIG-07 | T-06-20 / T-06-23 | Unique key on source row id; `isReady()` caches nothing | feature | `vendor/bin/pest tests/Feature/Signals/LocalSignalStoreTest.php` | ❌ W0 | ⬜ pending |
| 6-05-02 | 05 | 3 | SIG-07 | T-06-22 | Unknown driver throws; never falls back to `local` | feature | `vendor/bin/pest tests/Feature/Signals/SignalStoreResolutionTest.php` | ❌ W0 | ⬜ pending |
| 6-05-03 | 05 | 3 | SIG-07 | T-06-24 | Constrained columns behave identically on SQLite, MySQL and PostgreSQL | feature + mutation | `vendor/bin/pest tests/Feature/Signals && vendor/bin/pest --mutate --parallel --min=80 --class="ReyemTech\Hubspot\Signals\Stores\LocalSignalStore"` | ❌ W0 | ⬜ pending |
| 6-06-01 | 06 | 4 | SIG-06 | T-06-25 / T-06-26 / T-06-27 / T-06-30 / T-06-31 | One write per flush; duplicate `id_property` refused pre-request; identifiers-only queue payload | feature | `vendor/bin/pest tests/Feature/Signals/FlushSignalsJobTest.php` | ❌ W0 | ⬜ pending |
| 6-06-02 | 06 | 4 | SIG-06 | T-06-28 / T-06-29 | `reconcile` gated on the persisted column, at most one read per subject ever | feature | `vendor/bin/pest tests/Feature/Signals/FlushReconcileTest.php` | ❌ W0 | ⬜ pending |
| 6-06-03 | 06 | 4 | SIG-06 | T-06-31 | Command bounds selection at 100; package registers no schedule | feature | `vendor/bin/pest tests/Feature/Signals/FlushSignalsCommandTest.php` | ❌ W0 | ⬜ pending |
| 6-07-01 | 07 | 5 | SIG-08 | T-06-33 / T-06-35 | One record must carry the asserted property; failure messages name the subject, not the log | unit | `vendor/bin/pest tests/Unit/Signals/SignalReceiptLogTest.php` | ❌ W0 | ⬜ pending |
| 6-07-02 | 07 | 5 | SIG-08 | T-06-32 / T-06-34 / T-06-36 | Records only while faked; reset at Octane boundaries; constructor signature stays a strict prefix | feature | `vendor/bin/pest tests/Feature/Signals/FakeAssertionsTest.php tests/Feature/HubspotFakeTest.php` | ❌ W0 | ⬜ pending |
| 6-07-03 | 07 | 5 | SIG-08 | T-06-37 | Suite green with the token config key explicitly unset | feature | `vendor/bin/pest tests/Feature/Signals/SignalDeterminismTest.php tests/Feature/FakeDeterminismTest.php` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

No three consecutive tasks lack an `<automated>` verify: every non-checkpoint task in every plan
carries one, and the two checkpoints are separated by implementation tasks that do.

---

## Wave 0 Requirements

Created by plan 06-01 Task 1, which is the leading tracer — no separate Wave 0 plan is needed,
because the tracer's own `<verify>` is the first automated command in the phase and it creates the
directories every later plan writes into.

- [ ] `tests/Feature/Signals/` — does not exist; created by 06-01 Task 1
- [ ] `tests/Unit/Signals/` — does not exist; created by 06-02 Task 1
- [ ] `tests/Support/Signals/` — does not exist; `SignalSubject.php` and `SignalsTestCase.php` created by 06-01 Task 1
- [ ] `database/migrations/signals/` — does not exist; created by 06-01 Task 1
- [ ] `src/Signals/` — a `.gitkeep` placeholder today; genuinely greenfield
- [x] Framework install — none needed. Pest 4, `pest-plugin-arch` and `pest --mutate` are all installed.

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| The `first_wins` tie-break rule | SIG-04 | A `checkpoint:decision` — the rule is one-way once installs compute under it, and `06-CONTEXT.md` delegates the choice rather than locking it | 06-04's checkpoint presents three options with the recommendation named; reply with the option id |
| The `hubspot_signal_trail` column shape and payload default | SIG-07 | A `checkpoint:decision` — D-06 rates the trail's unique key one-way, and the payload default is a data-retention decision made on a consumer's behalf | 06-05's checkpoint presents three options with the recommendation named; reply with the option id |
| CI gate results on GitHub | all | Local green is not evidence — Phase 1 shipped four gate failures that passed locally and none were reachable without pushing | Push the branch, watch the required checks, report what GitHub says rather than what the local run said |

Everything else in this phase has automated verification.

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Wave 0 covers all MISSING references
- [x] No watch-mode flags
- [x] Feedback latency < 6s (`vendor/bin/pest --filter=Signals`)
- [ ] `nyquist_compliant: true` set in frontmatter — set by `/gsd-validate-phase` after execution

**Approval:** pending
