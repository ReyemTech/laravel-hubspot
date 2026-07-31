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
| **Baseline before this phase** | **668 tests, 2567 assertions, full suite 95s** — measured on `main` at `c6fcce5`, 2026-07-30. (`STATE.md` records 641/2506; that predates PRs #34 and #35.) Coverage 100.0% / MSI 99.38% as last recorded. |

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

*Filled 2026-07-30 by `/gsd-plan-phase`, once `04-01-PLAN.md` … `04-09-PLAN.md` existed.*

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 04-01-T1/T2 | 04-01 | 1 | SYNC-01a (split text), D-03/D-19/D-20 | T-04-01, T-04-02, T-04-SC | Undeclared production require cannot reach `main`; requires are gated by vendor, not by count | Ci | `vendor/bin/pest tests/Ci/ComposerManifestTest.php` | ✅ exists, **edit** | ⬜ pending |
| 04-01-T3 | 04-01 | 1 | REG-01b (rewording), D-01/D-04 | T-04-02, T-04-03, T-04-04 | An Illuminate root named in `src/` without a backing require fails the build, and so does a new third-party root | Ci | `bash scripts/ci/check-vendor-namespaces.sh --self-test` | ❌ W0 | ⬜ pending |
| 04-02-T2/T3 | 04-02 | 2 | SYNC-01a, SYNC-03a, REG-01b | T-04-05…T-04-10 | One event, one upsert, one link row; a binding without `id_property` throws at boot | Feature | `vendor/bin/pest tests/Feature/Sync/TracerSyncTest.php` | ❌ W0 | ⬜ pending |
| 04-03-T1/T2 | 04-03 | 3 | SYNC-02 | T-04-11, T-04-13, T-04-14 | A null relation omits its key rather than clearing a CRM property | Unit | `vendor/bin/pest tests/Unit/Sync/PropertyMapperTest.php` | ❌ W0 | ⬜ pending |
| 04-03-T3 | 04-03 | 3 | SYNC-02 | T-04-12 | An update addresses the stored HubSpot id, never a re-derived property | Feature | `vendor/bin/pest tests/Feature/Sync/UpdateSyncTest.php` | ❌ W0 | ⬜ pending |
| 04-04-T1/T2 | 04-04 | 3 | SYNC-01a, REG-01b | T-04-15, T-04-16, T-04-17, T-04-18 | Three models on one object type never share a link row; an unbound model throws | Feature + Unit | `vendor/bin/pest tests/Feature/Sync/ModelBindingTest.php tests/Unit/Sync/SyncsToHubspotTraitTest.php` | ❌ W0 | ⬜ pending |
| 04-05-T1/T2 | 04-05 | 4 | SYNC-03b | T-04-19, T-04-20, T-04-21, T-04-22 | No API call in a request lifecycle; each gate operand flips the outcome alone | Feature | `vendor/bin/pest tests/Feature/Sync/AutoSyncBootTest.php` | ❌ W0 | ⬜ pending |
| 04-06-T2/T3 | 04-06 | 5 | SYNC-04 | T-04-23…T-04-27 | A delete cannot surprise anyone; a force delete archives once, not twice | Unit + Feature | `vendor/bin/pest tests/Unit/Sync/DeletePolicyTest.php tests/Feature/Sync/DeletePolicyTest.php` | ❌ W0 | ⬜ pending |
| 04-07-T1/T2 | 04-07 | 6 | SYNC-05 | T-04-28…T-04-32 | `migrate:fresh --seed` fires zero API calls and queues zero jobs; `config:cache` still works | Feature | `vendor/bin/pest tests/Feature/Sync/SyncSuppressionTest.php` | ❌ W0 | ⬜ pending |
| 04-08-T2/T3 | 04-08 | 7 | SYNC-03c | T-04-33…T-04-36 | One collection, one request; a partial batch keeps what landed | Feature | `vendor/bin/pest tests/Feature/Sync/BatchSyncTest.php` | ❌ W0 | ⬜ pending |
| 04-09-T1/T2 | 04-09 | 7 | REG-04b, REG-01b | T-04-37…T-04-40 | The doctor reports the resolved policy rather than re-deriving it; the test asserting the opposite is deleted in the same change | Feature | `vendor/bin/pest tests/Feature/Registry/DoctorCommandTest.php` | ✅ exists, **edit** | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

**New test files:**

- [ ] `tests/Feature/Sync/TracerSyncTest.php` — **04-02**, the end-to-end slice: SYNC-01a's single
      binding, SYNC-03a's observer-plus-job path, REG-01b's read-back through the trait, and D-12's
      boot-time throw
- [ ] `tests/Unit/Sync/PropertyMapperTest.php` — **04-03**, SYNC-02, all three `$hubspotMap` forms
      plus the null-relation case (a null relation omits the key rather than sending null or
      throwing)
- [ ] `tests/Feature/Sync/UpdateSyncTest.php` — **04-03**, `$hubspotUpdateMap`'s narrowing and the
      update-by-stored-id leg
- [ ] `tests/Unit/Sync/SyncsToHubspotTraitTest.php` — **04-04**, REG-01b and D-06's relation + scopes
- [ ] `tests/Feature/Sync/ModelBindingTest.php` — **04-04**, SYNC-01a, including three models bound to
      `contacts` simultaneously and the API-only path with no binding at all
- [ ] `tests/Feature/Sync/AutoSyncBootTest.php` — **04-05**, SYNC-03b, observer attaches at boot with
      nothing in the consumer's `AppServiceProvider`; includes D-17's restore suppression, which lands
      with the `updated` handler rather than a plan later
- [ ] `tests/Unit/Sync/DeletePolicyTest.php` and `tests/Feature/Sync/DeletePolicyTest.php` —
      **04-06**, SYNC-04's policy table over primitives and the three-event behaviour
- [ ] `tests/Feature/Sync/SyncSuppressionTest.php` — **04-07**, SYNC-05
- [ ] `tests/Feature/Sync/BatchSyncTest.php` — **04-08**, SYNC-03c and SC4's exact
      request count

**Existing files that must be EDITED, not created:**

- [ ] `tests/Feature/Registry/DoctorCommandTest.php` — **04-09**. The absent-section test
      (`test_it_names_the_bound_model_section_as_not_built_rather_than_omitting_it`, line 162) is
      replaced with assertions against the real bound-model report, **in the same plan that makes
      REG-04b real** — 04-09 owns both, so the suite never asserts the opposite of what ships.
- [ ] `tests/Ci/ComposerManifestTest.php` — **04-01**. The count assertion (line 61) becomes D-03's
      vendor allow-list; the four-illuminate loop widens to eight
- [ ] `tests/Arch/LayerBoundariesTest.php` — **04-01**. R3's `toOnlyUse()` array and its rationale
      block (mirror the merged R2 2026-07-29 comment block as the template)
- [ ] `tests/Arch/rules.json` — **04-01**. R3's `description` field
- [ ] `tests/Ci/MigrationPublishingTest.php` — **04-02**. A second migration group exists
- [ ] `src/Testing/HubspotFake.php`, `src/Testing/RequestLog.php` — **04-08**. `assertSynced` resolves
      a bound model as well as an object-type string

**New non-test artifacts:**

- [ ] `database/migrations/sync/0001_01_01_000000_create_hubspot_object_links_table.php` — **04-02**,
      its own gated group, following `ServiceProvider::migrationGroups()`'s `path => active` pattern
- [ ] `scripts/ci/check-vendor-namespaces.sh` plus a violation fixture per direction under
      `tests/Ci/Fixtures/VendorNamespaces/` — **04-01**. Every gate in this repo is proven to fire
      against a committed violation
- [ ] `composer require illuminate/queue illuminate/bus illuminate/collections illuminate/console`
      at `^12.0|^13.0` — **04-01**
- [ ] `tests/Support/Sync/` — the bound test models and their schema: `SyncTestCase.php` and
      `SyncedLead.php` (04-02), `MappedDeal.php` / `MappedStage.php` (04-03),
      `MultiBindingTestCase.php` / `SyncedContact.php` / `SyncedIntake.php` (04-04),
      `SoftDeletingLead.php` (04-05). Each plan creates only its own; none edits another's.

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Real-portal upsert convergence after a lost response | SYNC-03a / D-11 | The default suite does zero network I/O (D-12) and integration tests are opt-in, secret-gated, and never required to merge | Extend `scripts/probes/smoke/` — the existing probe already demonstrated the lost-response failure live on a real portal, which is what D-11 addresses |

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
