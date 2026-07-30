---
phase: 4
slug: model-sync
# status lifecycle: draft (seeded by plan-phase) → validated (set by validate-phase §6)
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-07-30
---

# Phase 4 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.
> Seeded from `04-RESEARCH.md` § Validation Architecture. The per-task map fills in once
> `04-NN-PLAN.md` files exist; the infrastructure and Wave 0 rows below are already settled.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 4 on PHPUnit — `pestphp/pest: ^4.0`, with `pest-plugin-arch` and `pest-plugin-laravel` |
| **Config file** | `phpunit.xml.dist` — testsuites `Feature`, `Unit`, `Ci`, `Arch`; `tests/Pest.php` binds `TestCase::class` to Feature + Unit |
| **Quick run command** | `vendor/bin/pest tests/Unit/Sync tests/Feature/Sync` |
| **Full suite command** | `vendor/bin/pest` |
| **Coverage gate** | `vendor/bin/pest --coverage --min=95` (matches `ci.yml:57`) |
| **Mutation gate** | `vendor/bin/pest --mutate --min=80` (matches `quality.yml:126`) |
| **Baseline before this phase** | 641 tests, 2506 assertions, 100.0% coverage, MSI 99.38% |

**This project does not use Sail or Docker.** Run the binaries directly. Never convert this suite to
PHPUnit — Pest is locked (D-08 in `PROJECT.md`), and `apps/laravel`'s conversion rule is app-scoped.

---

## Sampling Rate

- **After every task commit:** `vendor/bin/pest --filter=Sync` (or the specific new test file)
- **After every plan wave:** `vendor/bin/pest --coverage --min=95` and `vendor/bin/pest --mutate --min=80`
- **Before `/gsd-verify-work`:** full suite green, plus `scripts/ci/verify-arch-rules-fire.sh` and
  `scripts/ci/verify-quality-gates-fire.sh` — both must still pass with R3's Illuminate widening and
  D-04's new source-hygiene check in place
- **Max feedback latency:** filtered run seconds; full suite is the wave-level gate, not the task-level one

**TDD ordering is a hard project rule, not a sampling preference.** The RED commit precedes the GREEN
commit in git history on every task (`CLAUDE.md` → Working method; D-13/D-25).

---

## Per-Task Verification Map

*Seeded at plan time; the planner fills `Task ID` / `Plan` / `Wave` once plans exist.*

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| TBD | 04-01 | 1 | — (housekeeping, D-19/D-20) | — | Undeclared production require cannot reach `main` | Ci | `vendor/bin/pest tests/Ci/ComposerManifestTest.php` | ✅ exists, **edit** | ⬜ pending |
| TBD | 04-01 | 1 | — (D-04) | — | An Illuminate root named in `src/` without a backing require fails the build | Ci | `scripts/ci/verify-quality-gates-fire.sh` | ❌ W0 | ⬜ pending |
| TBD | TBD | TBD | SYNC-01 | — | — | Feature | `vendor/bin/pest tests/Feature/Sync/ModelBindingTest.php` | ❌ W0 | ⬜ pending |
| TBD | TBD | TBD | SYNC-02 | — | — | Unit | `vendor/bin/pest tests/Unit/Sync/PropertyMapperTest.php` | ❌ W0 | ⬜ pending |
| TBD | TBD | TBD | SYNC-03 | — | No API call in a request lifecycle | Feature | `vendor/bin/pest tests/Feature/Sync/AutoSyncBootTest.php` | ❌ W0 | ⬜ pending |
| TBD | TBD | TBD | SYNC-04 | — | A delete cannot surprise anyone | Feature | `vendor/bin/pest tests/Feature/Sync/DeletePolicyTest.php` | ❌ W0 | ⬜ pending |
| TBD | TBD | TBD | SYNC-05 | — | `migrate:fresh --seed` fires zero API calls | Feature | `vendor/bin/pest tests/Feature/Sync/SyncSuppressionTest.php` | ❌ W0 | ⬜ pending |
| TBD | TBD | TBD | REG-01b | — | — | Unit | `vendor/bin/pest tests/Unit/Sync/SyncsToHubspotTraitTest.php` | ❌ W0 | ⬜ pending |
| TBD | TBD | TBD | REG-04b | — | — | Feature | `vendor/bin/pest tests/Feature/Registry/DoctorCommandTest.php` | ✅ exists, **edit** | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

**New test files:**

- [ ] `tests/Unit/Sync/PropertyMapperTest.php` — SYNC-02, all three `$hubspotMap` forms plus the
      null-relation case (dot-notation across a null relation resolves to null / omits the key,
      never a fatal)
- [ ] `tests/Unit/Sync/SyncsToHubspotTraitTest.php` — REG-01b and D-06's relation + scopes
- [ ] `tests/Feature/Sync/ModelBindingTest.php` — SYNC-01a, including three models bound to
      `contacts` simultaneously
- [ ] `tests/Feature/Sync/AutoSyncBootTest.php` — SYNC-03, observer attaches at boot with nothing in
      the consumer's `AppServiceProvider`
- [ ] `tests/Feature/Sync/DeletePolicyTest.php` — SYNC-04, including D-17's restore suppression
- [ ] `tests/Feature/Sync/SyncSuppressionTest.php` — SYNC-05

**Existing files that must be EDITED, not created:**

- [ ] `tests/Feature/Registry/DoctorCommandTest.php` — the absent-section test
      (`test_it_names_the_bound_model_section_as_not_built_rather_than_omitting_it`, line 162) must be
      replaced with assertions against the real bound-model report. **It must change in the same plan
      that makes REG-04b real**, or the suite asserts the opposite of what ships.
- [ ] `tests/Ci/ComposerManifestTest.php` — `it('has exactly seven production requires')` (line 61)
      becomes D-03's vendor allow-list; the four-illuminate loop widens to eight
- [ ] `tests/Arch/LayerBoundariesTest.php` — R3's `toOnlyUse()` array and its rationale block (mirror
      the merged R2 2026-07-29 comment block as the template)
- [ ] `tests/Arch/rules.json` — R3's `description` field

**New non-test artifacts:**

- [ ] `database/migrations/sync/..._create_hubspot_object_links_table.php` — its own gated group,
      following `ServiceProvider::migrationGroups()`'s `path => active` pattern
- [ ] A violation fixture for D-04's new source-hygiene check, in **both** directions — every gate in
      this repo is proven to fire against a committed violation
- [ ] `composer require illuminate/queue illuminate/bus illuminate/collections illuminate/console`
      at `^12.0|^13.0`

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Real-portal upsert convergence after a lost response | SYNC-03 / D-11 | The default suite does zero network I/O (D-12) and integration tests are opt-in, secret-gated, and never required to merge | Extend `scripts/probes/smoke/` — the existing probe already demonstrated the lost-response failure live on a real portal, which is what D-11 addresses |

Everything else has automated verification.

---

## Validation Sign-Off

- [ ] All tasks have an automated verify command or a Wave 0 dependency
- [ ] Sampling continuity: no 3 consecutive tasks without an automated verify
- [ ] Wave 0 covers every ❌ reference above
- [ ] No watch-mode flags
- [ ] RED commit precedes GREEN commit for every task
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
