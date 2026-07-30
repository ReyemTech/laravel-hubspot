---
phase: 04-model-sync
plan: 01
subsystem: infra
tags: [composer, ci, phpstan, phpcs, pest-arch, dependency-policy, vendor-allow-list]

requires:
  - phase: 03-registry-and-stores
    provides: the ten-rule architecture manifest (tests/Arch/rules.json), the source-hygiene and
      quality-gates-firing harness conventions this plan's new gate follows
provides:
  - "Eleven production requires (composer.json), vendor-allow-list manifest gate replacing the
    seven-package count (tests/Ci/ComposerManifestTest.php)"
  - "D-04's bidirectional vendor-namespace CI gate (scripts/ci/check-vendor-namespaces.sh) plus its
    two committed violation fixtures"
  - "R3 (tests/Arch/LayerBoundariesTest.php) widened to permit Illuminate, mirroring R2"
  - "Six documents amended to drop the superseded seven-package ceiling and the id_column shape:
    CLAUDE.md, STANDARDS.md, docs/repo/owner-gated-checklist.md, the design spec Sec.4 sample,
    REQUIREMENTS.md REG-01b and SYNC-01 (split into SYNC-01a/SYNC-01b)"
affects: [04-02, 04-03, 04-04, 04-05, 04-06, 04-07, 04-08, 04-09]

tech-stack:
  added: []
  patterns:
    - "Vendor-namespace scanning via PhpToken::tokenize() (T_NAME_QUALIFIED/T_NAME_FULLY_QUALIFIED),
      not a raw-text regex -- a regex draft produced false positives on docblock prose and regex
      string literals in the real tree"
    - "Grandfathered third-party vendor roots enumerated with one written reason each, mirroring
      tests/Ci/RequiredChecksTest.php's requiredChecksAllowlistedWorkflowFiles() convention"

key-files:
  created:
    - scripts/ci/check-vendor-namespaces.sh
    - tests/Ci/Fixtures/VendorNamespaces/UndeclaredIlluminateRoot.php
    - tests/Ci/Fixtures/VendorNamespaces/ThirdPartyVendorInSrc.php
  modified:
    - composer.json
    - .github/workflows/ci.yml
    - .github/workflows/quality.yml
    - tests/Ci/ComposerManifestTest.php
    - tests/Arch/LayerBoundariesTest.php
    - tests/Arch/rules.json
    - phpstan.neon
    - phpcs.xml
    - CLAUDE.md
    - STANDARDS.md
    - docs/repo/owner-gated-checklist.md
    - docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md
    - .planning/REQUIREMENTS.md
    - .github/PULL_REQUEST_TEMPLATE.md

key-decisions:
  - "composer.lock is gitignored for this library and was never committed before this plan; the
    plan's acceptance criteria assumed it would be committed as part of this plan's diff. Verified
    the substance locally (composer require reported \"Nothing to modify in lock file\"; only the
    content-hash changed) but did NOT force-add or un-gitignore it -- see Deviations."
  - "R3's rules.json description was made byte-identical to its arch() call string (including the
    \"R3: \" prefix), which R1/R2/R4/R5 do not carry in rules.json -- a small, deliberate,
    single-rule inconsistency accepted to satisfy the plan's explicit acceptance criterion."

requirements-completed: [SYNC-01a, REG-01b]

coverage:
  - id: D1
    description: "Manifest gate rewritten from a fixed seven-package count to a vendor allow-list (php, hubspot/api-client, illuminate/*, laravel/prompts via enumerated exception)"
    requirement: SYNC-01a
    verification:
      - kind: unit
        ref: "tests/Ci/ComposerManifestTest.php"
        status: pass
    human_judgment: false
  - id: D2
    description: "Eleven production requires declared (four new illuminate/* packages); composer.lock's installed package set unchanged"
    verification:
      - kind: unit
        ref: "tests/Ci/ComposerManifestTest.php"
        status: pass
      - kind: manual_procedural
        ref: "composer require illuminate/queue illuminate/bus illuminate/collections illuminate/console -- reported 'Nothing to modify in lock file'"
        status: pass
    human_judgment: false
  - id: D3
    description: "D-04 bidirectional vendor-namespace CI gate (scripts/ci/check-vendor-namespaces.sh), both directions proven against committed fixtures, plus clean-file acceptance"
    verification:
      - kind: other
        ref: "bash scripts/ci/check-vendor-namespaces.sh && bash scripts/ci/check-vendor-namespaces.sh --self-test"
        status: pass
    human_judgment: false
  - id: D4
    description: "R3 widened to permit Illuminate; all ten architecture rules still fire under their own violation fixtures"
    requirement: REG-01b
    verification:
      - kind: other
        ref: "bash scripts/ci/verify-arch-rules-fire.sh"
        status: pass
    human_judgment: false
  - id: D5
    description: "Six documents amended to drop the superseded seven-package ceiling and id_column shape (CLAUDE.md, STANDARDS.md, owner-gated-checklist.md, design spec Sec.4, REQUIREMENTS.md REG-01b/SYNC-01)"
    verification:
      - kind: manual_procedural
        ref: "grep -n 'SYNC-01a' / 'SYNC-01b' .planning/REQUIREMENTS.md; grep -n id_property docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md"
        status: pass
    human_judgment: false

duration: 1h15m
completed: 2026-07-30
status: complete
---

# Phase 4 Plan 1: Vendor Allow-List and Dependency-Ceiling Housekeeping Summary

**Manifest gate rewritten from a seven-package count to a vendor allow-list, the four illuminate/console/queue/bus/collections requires declared, a new bidirectional D-04 vendor-namespace CI gate, R3 widened for Illuminate, and six stale documents amended -- so no Phase 4 feature plan lands code its own repo rules forbid.**

## Performance

- **Duration:** ~1h15m
- **Completed:** 2026-07-30
- **Tasks:** 3/3
- **Files modified:** 17 (3 created, 14 modified)

## Accomplishments

- Rewrote `tests/Ci/ComposerManifestTest.php`'s manifest-shape assertions from a fixed
  seven-production-require count to D-03's vendor allow-list (`php`, `hubspot/api-client`,
  `illuminate/*` by prefix, `laravel/prompts` via its own enumerated exception) -- committed RED
  first, confirmed red for the D-19 reason (four illuminate packages undeclared), then GREEN.
- Declared `illuminate/queue`, `illuminate/bus`, `illuminate/collections` and `illuminate/console`
  as production requires at `^12.0|^13.0`, after mechanically confirming first-party provenance
  against `vendor/laravel/framework/composer.json`'s own `replace` block (all four present).
  `composer require` reported "Nothing to modify in lock file" -- only the lock's content-hash
  changed, proving zero new packages entered the installed set.
- Built `scripts/ci/check-vendor-namespaces.sh` (D-04): Direction A rejects an `Illuminate\*` root
  referenced under `src/` with no backing require; Direction B rejects any other third-party
  vendor root not on an enumerated grandfather list (`GuzzleHttp`, `Psr`, `PHPUnit`, each with its
  own written reason and file set). Namespace detection uses `PhpToken::tokenize()` rather than a
  raw-text regex, after an earlier regex draft produced false positives against the real tree (see
  Deviations). Both directions are proven to fire against committed fixtures under
  `tests/Ci/Fixtures/VendorNamespaces/`, and `--self-test` also proves a clean file is accepted by
  both.
- Widened R3 (`tests/Arch/LayerBoundariesTest.php`, `tests/Arch/rules.json`) to permit `Illuminate`,
  mirroring R2's 2026-07-29 amendment. `Fixtures/R3/SyncDependsOnWebhooks.php` reused unchanged and
  still drives R3 red under `verify-arch-rules-fire.sh` -- all ten rules still fire.
- Amended six documents to drop the superseded "exactly seven, forever" ceiling and the
  `id_column` shape: `CLAUDE.md`, `STANDARDS.md` Sec.2, `docs/repo/owner-gated-checklist.md`, the
  design spec Sec.4 model-binding sample (`id_property` replaces `id_column`, plus a note that the
  local id lives in the package-owned `hubspot_object_links` table), and `.planning/REQUIREMENTS.md`
  (REG-01b reworded onto the trait's link relation; SYNC-01 split into SYNC-01a/SYNC-01b matching
  the REG-01a/b and REG-04a/b precedent).

## Task Commits

1. **Task 1: RED -- manifest gate becomes a vendor allow-list** - `58f7201` (test)
2. **Task 2: GREEN -- declare the four requires, rename the gate, amend six documents** - `5ddf01a`
   (fix), plus `a8235b9` (docs, `.planning/REQUIREMENTS.md` split into its own commit per the plan's
   `/gsd-pr-branch` filtering instruction)
3. **Task 3: D-04's bidirectional gate and R3's widening** - `ceed6c4` (feat)

_Note: no separate plan-metadata commit exists yet -- this SUMMARY and STATE.md updates land in the
final commit described in `<final_commit>`._

## Files Created/Modified

- `scripts/ci/check-vendor-namespaces.sh` - D-04's bidirectional CI gate
- `tests/Ci/Fixtures/VendorNamespaces/UndeclaredIlluminateRoot.php` - Direction A violation fixture
- `tests/Ci/Fixtures/VendorNamespaces/ThirdPartyVendorInSrc.php` - Direction B violation fixture
- `composer.json` - eleven production requires
- `tests/Ci/ComposerManifestTest.php` - vendor allow-list, widened illuminate constraint loop, D-19
  regression test
- `.github/workflows/ci.yml` - `manifest` job renamed; four new illuminate packages pinned in the
  `tests` job's per-matrix-cell `--with` overrides
- `.github/workflows/quality.yml` - new `vendor-namespaces` job
- `tests/Arch/LayerBoundariesTest.php`, `tests/Arch/rules.json` - R3 widened for Illuminate
- `phpstan.neon`, `phpcs.xml` - `tests/Ci/Fixtures/*` excluded
- `CLAUDE.md`, `STANDARDS.md`, `docs/repo/owner-gated-checklist.md`,
  `docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md`, `.planning/REQUIREMENTS.md` -
  amended per D-02/D-03/D-13/D-15
- `.github/PULL_REQUEST_TEMPLATE.md` - runtime-dependency section reworded (deviation, see below)

## Decisions Made

- **`laravel/prompts` allow-list exception, resolved as the plan anticipated.** D-03's literal
  wording ("php, hubspot/api-client and illuminate/*") would have rejected a shipped require.
  Resolved via a separately enumerated exception (`composerManifestEnumeratedExceptions()`) carrying
  its own written reason -- first-party Laravel, STANDARDS.md Sec.2's optional-installer entry,
  admitted by name rather than a `laravel/`-prefix rule a third-party `laravel/*` package could slip
  through.
- **Three grandfathered third-party roots**, recorded in `scripts/ci/check-vendor-namespaces.sh`
  with their file sets:
  - `GuzzleHttp` -- `src/Gateway/HubspotClientFactory.php` and four files under `src/Testing/`
    (`CannedConnectionFailure.php`, `DefaultResponses.php`, `HubspotFake.php`, transitively via
    `RequestLog.php`/`RecordedRequest.php`'s PSR-7 usage).
  - `Psr` -- `src/Testing/RecordedRequest.php`, `src/Testing/RequestLog.php`, `src/Testing/DefaultResponses.php`.
  - `PHPUnit` -- `src/Testing/RequestLog.php` (`PHPUnit\Framework\Assert`), a collaborator of
    `src/Testing/HubspotFake.php`.
  All three are transitive through `hubspot/api-client` (Guzzle/PSR) or a require-dev package named
  from production code (PHPUnit), and each entry states that listing is a deferral, not an
  approval.
- **`composer.lock` diff shape**: `composer require illuminate/queue illuminate/bus
  illuminate/collections illuminate/console` reported "Nothing to modify in lock file" and only
  `content-hash` changed in a local before/after diff -- zero packages added, removed or
  version-bumped in the `packages` array, confirming `laravel/framework` replaces all four as
  `04-RESEARCH.md` predicted.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Vendor-namespace regex draft produced false positives against the real tree**
- **Found during:** Task 3, first run of `scripts/ci/check-vendor-namespaces.sh` against the
  shipped `src/` tree.
- **Issue:** An initial raw-text-regex implementation of Direction B (`\\?([A-Za-z_][A-Za-z0-9_]*)\\[A-Za-z0-9_\\]+`)
  matched docblock prose (e.g. `` `Gateway\ExceptionTranslator` ``, `` `Registry\HubspotObjectType` ``)
  and a PHP regex string literal (`'/^p\d*_[a-z0-9_]+$/'` in `HubspotObjectType.php`, whose `p\d`
  substring matched the vendor-root shape) as if they were real namespace imports, producing ~35
  false-positive violations across `src/Exceptions/`, `src/Gateway/`, `src/Registry/` and
  `src/ServiceProvider.php`.
- **Fix:** Rewrote the detection primitive to use `PhpToken::tokenize()` and filter for
  `T_NAME_QUALIFIED`/`T_NAME_FULLY_QUALIFIED` tokens, which PHP's own tokenizer only emits for
  genuine code-level qualified names -- never for text inside a comment or a string literal.
  Verified empirically with a standalone `php -r` probe before committing the fix.
- **Files modified:** `scripts/ci/check-vendor-namespaces.sh`.
- **Verification:** `bash scripts/ci/check-vendor-namespaces.sh` passes clean against the shipped
  tree; `--self-test` still proves both directions reject their own fixture and accept a clean
  file.
- **Committed in:** `ceed6c4` (part of Task 3's commit).

**2. [Rule 1 - Bug] PHPStan `method.nonObject` on the D-19 regression test's directory iterator**
- **Found during:** Task 3, running the full quality gate suite before committing.
- **Issue:** `tests/Ci/ComposerManifestTest.php`'s `composerManifestIlluminateRootsUsedInSrc()`
  helper (added in Task 1) iterated `RecursiveIteratorIterator`/`RecursiveDirectoryIterator`
  without narrowing PHPStan's `mixed` inference for the yielded item, producing four
  `method.nonObject`/`argument.type` errors.
- **Fix:** Added an explicit `! $file instanceof SplFileInfo` guard, matching the existing
  convention in `tests/Arch/FiringHarnessTest.php` for the identical iterator shape.
- **Files modified:** `tests/Ci/ComposerManifestTest.php`.
- **Verification:** `vendor/bin/phpstan analyse --no-progress` -- No errors.
- **Committed in:** `ceed6c4` (part of Task 3's commit, since it surfaced while validating Task 3's
  gates).

**3. [Rule 1 - Bug] ci.yml's test matrix did not pin the four new illuminate packages**
- **Found during:** Task 2, after renaming the `manifest` job.
- **Issue:** The `tests` job's per-matrix-cell `--with` overrides pinned
  `illuminate/{contracts,support,database,view}` to `matrix.laravel`, but not the four newly
  declared packages. Left unpinned, a `--prefer-stable`/`--prefer-lowest` full update could resolve
  e.g. `illuminate/queue` to a different Laravel major than the matrix cell's pinned
  `illuminate/support`, silently testing a mismatched split-package combination.
- **Fix:** Added `--with illuminate/{queue,bus,collections,console}:${{ matrix.laravel }}` alongside
  the four already pinned.
- **Files modified:** `.github/workflows/ci.yml`.
- **Verification:** `vendor/bin/pest tests/Ci/MatrixInstallStepTest.php` -- passing (including "it
  constrains illuminate/* and orchestra/testbench per matrix cell").
- **Committed in:** `5ddf01a` (part of Task 2's commit).

**4. [Rule 2 - Missing correctness] `.github/PULL_REQUEST_TEMPLATE.md` still asserted the
superseded seven-package ceiling**
- **Found during:** Task 2, while auditing the repo for other stale "seven"/"eighth" references
  beyond the plan's six named documents.
- **Issue:** The PR template's "Runtime dependency justification" section read: *"Production
  `require` is exactly seven packages (STANDARDS Sec.2); an eighth needs a written reason here."*
  A contributor following this literally would believe an `illuminate/*` addition needs
  justification under D-02, which it does not.
- **Fix:** Reworded to point at the vendor-allow-list gate as authoritative, matching the language
  used in the other six amended documents.
- **Files modified:** `.github/PULL_REQUEST_TEMPLATE.md`.
- **Verification:** `scripts/ci/check-repo-governance.sh` only checks the PR template's
  Definition-of-Done checkbox count, unaffected by this prose change; re-ran the script -- still
  passes.
- **Committed in:** `ceed6c4` (part of Task 3's commit).

---

**Total deviations:** 4 auto-fixed (3 Rule 1, 1 Rule 2).
**Impact on plan:** All four are necessary for correctness (a false-positive-riddled CI gate, a
PHPStan error, an under-pinned test matrix, and a misleading PR template) or scope-adjacent
documentation hygiene directly caused by this plan's own change. No architectural changes, no
scope creep into `src/Sync/`.

### Unresolved: composer.lock is gitignored and was never committed

**Found during:** Task 2, before running `composer require`.

**Issue:** The plan's non-negotiable #8 and Task 2's acceptance criteria (`git diff HEAD~1 --
composer.lock` showing the lock changed with zero package-array churn) assume `composer.lock` is
tracked in git and gets committed as part of this plan's diff. In this repository, `composer.lock`
is explicitly gitignored (`.gitignore` line 5) and has never been committed (`git log --oneline -1
-- composer.lock` returns nothing) -- deliberately, per `.github/workflows/ci.yml`'s own comment:
*"composer.lock is gitignored (correct for a library -- see .gitignore), so each matrix cell must
resolve its own dependency set from scratch."* This is also named in `04-CONTEXT.md`'s Deferred
Ideas as its own owed item ("composer.lock staleness ... still owed their own PR; do not fold into
a feature branch").

**What I did instead:** Verified the *substance* of non-negotiable #8 locally rather than via a git
diff: copied `composer.lock` before running `composer require illuminate/queue illuminate/bus
illuminate/collections illuminate/console`, and diffed the before/after files directly. The command
itself reported `Nothing to modify in lock file`; the local diff showed only the `content-hash` key
changed, with zero `"name":` lines added, removed or altered in the `packages` array -- proving no
new package was installed. I did **not** force-add `composer.lock` to git (`git add -f`) or remove
it from `.gitignore`, since doing so would silently reverse a deliberate, previously-justified repo
convention without owner sign-off -- exactly the kind of routing-around-a-flaw-in-silence CLAUDE.md
forbids. The two literal acceptance-criteria commands referencing `git diff HEAD~1 -- composer.lock`
cannot produce output either way (the file is untracked before and after), so they are reported here
as **not satisfiable as written** rather than silently marked passing.

**Recommendation:** If the owner wants `composer.lock`'s before/after diff to be part of the
committed record for dependency-ceiling changes going forward, that is itself a policy change (un-
ignoring `composer.lock` for this library) and should be a deliberate, separate decision -- not one
this housekeeping plan should make unilaterally.

## Issues Encountered

None beyond the deviations documented above.

## User Setup Required

None -- no external service configuration required.

## Next Phase Readiness

The manifest gate, the D-04 vendor-namespace gate, and R3's widening are all green and proven to
fire against their own fixtures. Every Phase 4 feature plan (04-02 through 04-09) can now declare
`illuminate/queue`, `illuminate/bus`, `illuminate/collections` usage and reference `Illuminate\*`
inside `Sync\` without tripping a CI gate this same phase would otherwise have shipped against
them. No blockers for 04-02.

---
*Phase: 04-model-sync*
*Completed: 2026-07-30*

## Self-Check: PASSED

All created files verified present on disk; all four task commits (58f7201, 5ddf01a, a8235b9,
ceed6c4) verified present in `git log`.
