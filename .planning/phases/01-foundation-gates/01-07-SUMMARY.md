---
phase: 01-foundation-gates
plan: 07
subsystem: infra
tags: [release-please, composer-audit, roave-bc-check, github-actions, required-checks, hubspot-associations]

# Dependency graph
requires:
  - phase: 01-foundation-gates
    provides: "composer.json/Pest bootstrap (01-01), governance files (01-02), JS toolchain (01-03), the ten architecture rules (01-04), PHPStan/Pint/PHPCS/mutation gates (01-05), the docs site (01-06) -- every job name this plan's required-checks list and firing proofs depend on being real"
provides:
  - "phpunit.xml.dist's Arch testsuite fix (out-of-scope item logged by 01-03, resolved here first): a bare `vendor/bin/pest` now runs 35 tests instead of 13, proven by a permanent locking test (tests/Ci/PhpunitTestsuitesTest.php)"
  - "release-please-config.json (release-type: simple) + .release-please-manifest.json + version.txt, both at 0.0.0 -- release-please owns versioning/CHANGELOG.md from Conventional Commits on main and never publishes"
  - ".github/workflows/release-please.yml -- push-to-main only, cuts tags/opens the release PR, no publish step"
  - ".github/workflows/supply-chain.yml -- composer-audit (fails on any advisory) and bc-check (roave/backward-compatibility-check, single PHP-8.4 job, guarded on git-tag absence not exit code)"
  - "tests/Ci/RequiredChecksTest.php -- parses every .github/workflows/*.yml with Symfony Yaml and asserts the 17 pull-request-triggered job ids agree exactly, in both directions, with docs/repo/owner-gated-checklist.md's Required status checks list"
  - "docs/repo/owner-gated-checklist.md -- the complete owner-gated handoff register: branch protection + the 17-job required-checks list, Dependabot auto-merge, Packagist registration (blocked twice over), GitHub Pages deploy, the security@reyem.tech mailbox, and FOUND-03"
  - "docs/probes/association-inverse-probe.md + scripts/probes/association-inverse-probe.sh -- the ready-to-run FOUND-03 procedure, curl-based against the HubSpot v4 associations REST endpoints, results table deliberately empty"
affects: [ship-phase, phase-2-gateway, branch-protection, release-process]

# Tech tracking
tech-stack:
  added:
    - "googleapis/release-please-action@v4 (GitHub Action, not a composer/npm dependency)"
    - "roave/backward-compatibility-check ^8.21 -- installed per-CI-run inside the bc-check job only, never added to composer.json's own require-dev (see key-decisions)"
  patterns:
    - "Deploy/release-only workflow exclusion via an explicit, tested allowlist (requiredChecksAllowlistedWorkflowFiles()) rather than a filename-pattern guess -- proven by its own assertion, not just asserted"
    - "Job-id-level (YAML key) comparison rather than display-name comparison for required-checks parity, since job ids are the only thing mechanically parseable from workflow YAML without also re-implementing GitHub's matrix-name templating"
    - "Ephemeral, job-scoped composer require --dev inside a single CI job, never touching the project's own committed composer.json/composer.lock, for a tool whose own PHP floor has drifted upward release over release and would otherwise force every matrix leg to satisfy it"

key-files:
  created:
    - tests/Ci/PhpunitTestsuitesTest.php
    - release-please-config.json
    - .release-please-manifest.json
    - version.txt
    - .github/workflows/release-please.yml
    - .github/workflows/supply-chain.yml
    - tests/Ci/RequiredChecksTest.php
    - docs/repo/owner-gated-checklist.md
    - docs/probes/association-inverse-probe.md
    - scripts/probes/association-inverse-probe.sh
  modified:
    - phpunit.xml.dist
    - .planning/phases/01-foundation-gates/deferred-items.md

key-decisions:
  - "Research assumption A4 CONFIRMED: release-type: simple bumps a version.txt file at the package root plus CHANGELOG.md and touches nothing else -- this is release-please's own documented behavior for the 'simple' release-type (a generic strategy assuming the version lives in version.txt), not the 'php' release-type, which instead expects/updates a version key inside composer.json. composer.json still carries no version key (01-01's manifest test enforces this), so release-type: simple does not introduce a second source of truth. No live network fetch was available in this environment to re-verify the schema page directly; this is recorded as cross-checked against 01-RESEARCH.md's own citation and well-established, stable release-please documentation rather than a fresh fetch."
  - ".release-please-manifest.json and version.txt both start at 0.0.0, matching each other and the fact that `git tag -l` is empty (confirmed) -- there is no previous release for either file to reflect. release-please's first PR computes the real initial bump from whatever Conventional Commits land on main."
  - "roave/backward-compatibility-check is installed inside the bc-check job only (`composer require --dev` as a CI step), never added to composer.json's own require-dev. Confirmed empirically in a scratch copy of this project's own composer.json/composer.lock (not the tracked tree) that a plain `composer require --dev roave/backward-compatibility-check` fails to resolve at all -- this project's own lockfile already pins symfony/console to a major roave's own constraint doesn't accept -- and that `--with-all-dependencies` is required for the install to succeed. Recorded as a Rule 3 blocking fix and wired into the workflow step, not silently reasoned around."
  - "roave/backward-compatibility-check version actually installed when the job runs: 8.21.0 (confirmed via a scratch-copy composer show), requiring php ~8.4.0|~8.5.0 -- matching this plan's PHP-8.4 job pin and confirming 01-RESEARCH.md's Open Question 2 resolution (the project's own PHP floor raise to ^8.3 removed the reason to pin an older roave release for PHP 8.2)."
  - "D-17's breaking-label exception (a PR labelled `breaking` targeting the next major may deliberately keep a detected BC break) is recorded as Phase 9 follow-up work in docs/repo/owner-gated-checklist.md's supply-chain section rather than wired into supply-chain.yml now -- wiring it safely, without it becoming a permanent escape hatch on the bc-check step, needs its own design pass. Not silently dropped, per the plan's explicit instruction."
  - "tests/Ci/RequiredChecksTest.php compares job IDS (the YAML key under `jobs:`), not the human-readable `name:` display string GitHub shows in the PR checks UI. Job ids are exactly what Symfony\\Component\\Yaml\\Yaml::parseFile() returns as array keys with zero ambiguity; the display `name:` for a matrix job like `tests` expands per-combination in the real GitHub UI (e.g. 'PHP 8.3 - Laravel 11.* - prefer-stable'), which this test does not attempt to re-derive -- STANDARDS Sec.12b's own required-checks list is stated at the same job-level granularity ('tests (full matrix)' as one line item), so this matches the document's own level of precision rather than under- or over-specifying it."
  - "The 'Required status checks' section of docs/repo/owner-gated-checklist.md avoids markdown code-ticks around any word that is not itself a job id (e.g. 'level max' not 'level `max`', 'the laravel preset' not 'the `laravel` preset') -- discovered the hard way when the test's own backtick-extraction regex picked up prose words (max, laravel, TODO, FIXME, SECURITY.md, release-please, main) as false-positive 'documented' entries during RED-to-GREEN. Fixed by rewording the prose, not by loosening the regex, since a looser regex would let a genuinely stale job name hide among false positives instead of failing loudly."
  - "Pest's expect()->toContain($value, $message) is VARIADIC, not (value, message) -- a bug found and fixed mid-task: the test's third assertion originally passed a human-readable message as the second argument, which Pest/PHPUnit's assertContains interprets as a second value to search for, not a message. Every call therefore failed unconditionally (the array never contains a literal explanatory sentence), regardless of which job was actually missing. Replaced with expect(in_array(...))->toBeTrue(message), which does accept a message as its second argument."

patterns-established:
  - "Any future PR adding a pull-request-triggered workflow job must add its id to docs/repo/owner-gated-checklist.md's Required status checks list (or add the whole file to requiredChecksAllowlistedWorkflowFiles() if it's genuinely deploy/release-only), or tests/Ci/RequiredChecksTest.php fails immediately -- the same class of protection 01-04 built for architecture rules and fixtures, now extended to the required-checks list itself."
  - "A tool whose own platform-support floor drifts faster than the project's (roave/backward-compatibility-check here, matching 01-RESEARCH.md's Pitfall 3) is installed per-job inside CI, never added to the project's own composer.json -- avoids forcing every matrix leg to satisfy a constraint that has nothing to do with what that leg is testing."

requirements-completed: [FOUND-01, FOUND-03, REL-01]

coverage:
  - id: D1
    description: "release-please owns versioning and CHANGELOG.md from Conventional Commits on main, and never publishes to Packagist (D-28, D-47)"
    requirement: REL-01
    verification:
      - kind: unit
        ref: "node -e checks confirming release-type: simple present in release-please-config.json, manifest/version.txt exist, and grep -riE 'packagist' .github/workflows/release-please.yml finds nothing"
        status: pass
    human_judgment: false
  - id: D2
    description: "composer audit fails the build on any advisory (D-31), no escape hatch"
    requirement: FOUND-01
    verification:
      - kind: unit
        ref: "composer audit (exit 0, 'No security vulnerability advisories found'); grep confirms no continue-on-error/|| true anywhere in supply-chain.yml"
        status: pass
    human_judgment: false
  - id: D3
    description: "The backward-compatibility check runs as a single non-matrixed job and handles the greenfield no-previous-tag case explicitly (skip-with-a-loud-message, exit 0) rather than erroring or silently passing forever"
    requirement: FOUND-01
    verification:
      - kind: unit
        ref: "git tag -l confirmed empty against this repository; the workflow's own tag-absence guard logic re-run locally resolves to exists=false (skip path), matching the guard's intended behavior -- see Issues Encountered for the full roave-install investigation"
        status: pass
    human_judgment: false
  - id: D4
    description: "Every gate job shipped in this phase appears in the branch-protection required-checks list, and the list contains no name that is not a real job -- asserted by a test, not by reading"
    requirement: FOUND-01
    verification:
      - kind: unit
        ref: "vendor/bin/pest tests/Ci/RequiredChecksTest.php (5 passed, 46 assertions); proven non-vacuous by deliberate mutation in both directions: removing a documented job made the test fail naming it, and adding an undocumented workflow job also made it fail naming it -- both mutations reverted and confirmed byte-identical via diff before the suite was reconfirmed green"
        status: pass
    human_judgment: false
  - id: D5
    description: "The FOUND-03 association-inverse probe is a ready-to-run procedure whose only missing input is a HubSpot developer account token, with no fabricated result and no reasoned-out answer"
    requirement: FOUND-03
    verification:
      - kind: unit
        ref: "bash -n scripts/probes/association-inverse-probe.sh (parses clean); grep -qi 'developer test account' docs/probes/association-inverse-probe.md (present); results table in that file confirmed empty with the date field blank"
        status: pass
    human_judgment: true
    rationale: "Whether the probe script's HubSpot v4 API call shapes are exactly correct can only be confirmed by actually running it against a live developer test account, which requires a token this executing agent does not hold and must never fabricate a result for."
  - id: D6
    description: "A bare vendor/bin/pest run (no path argument) exercises the architecture tests, closing the gap 01-03 logged rather than fixed"
    requirement: FOUND-01
    verification:
      - kind: unit
        ref: "vendor/bin/pest tests/Ci/PhpunitTestsuitesTest.php (RED before the fix: 2 failed; GREEN after: 2 passed); bare vendor/bin/pest rose from 13 passed to 30 passed immediately after the fix (35 after this plan's own new tests were added on top)"
        status: pass
    human_judgment: false

duration: ~30min
completed: 2026-07-27
status: complete
---

# Phase 1 Plan 07: Release Plumbing, Supply-Chain Gates, and the Owner-Gated Register Summary

**release-please (release-type: simple, no publish), a greenfield-safe roave/backward-compatibility-check job installed per-CI-run rather than project-wide, a machine-checked required-checks list covering all 17 pull-request-triggered jobs across seven workflow files, the ready-to-run FOUND-03 probe, and the phpunit.xml.dist Arch-testsuite gap fixed first.**

## Performance

- **Duration:** ~30 min (commit span 01:45-01:58 UTC on 2026-07-27, plus upfront reading of all six prior SUMMARYs/deferred-items.md/STANDARDS.md, a scratch-copy dependency-resolution investigation for roave, and mid-task debugging of a Pest `toContain()` misuse)
- **Completed:** 2026-07-27
- **Tasks:** 3 plan tasks plus 1 additional task (the phpunit.xml.dist fix, done first per the executor's instructions)
- **Files created:** 10
- **Files modified:** 2

## Accomplishments

- **Fixed the phpunit.xml.dist Arch-testsuite gap first**, as instructed. `<testsuites>` listed only `Feature` and `Ci`; a bare `vendor/bin/pest` silently skipped all ten architecture rules, even though CI itself was covered (`arch.yml` invokes `vendor/bin/pest tests/Arch` explicitly). Proved this TDD-style: `tests/Ci/PhpunitTestsuitesTest.php` committed RED (both assertions failing against the shipped config), then the one-line GREEN fix, with real before/after counts — **13 passed → 30 passed** immediately after the fix (35 after this plan's own later commits added five more tests on top). `--coverage --min=95` still exits 1 for the same pre-existing empty-`src/` reason (unrelated, unaffected).
- **release-please configured** (`release-please-config.json`, `.release-please-manifest.json`, `version.txt`, `.github/workflows/release-please.yml`): `release-type: simple` against `version.txt`, confirming research assumption A4. Triggers on push to `main` only, minimum permissions, no publish step of any kind — confirmed via `grep -riE 'packagist'` finding nothing in the workflow.
- **Supply-chain gates** (`.github/workflows/supply-chain.yml`): `composer-audit` (fails on any advisory, no escape hatch) and `bc-check` (`roave/backward-compatibility-check` 8.21.0, single PHP-8.4 job, installed per-job via `composer require --dev --with-all-dependencies` rather than added to the project's own `composer.json`). The greenfield case is guarded on `git tag -l` being empty — confirmed empty against this actual repository — not on the tool's exit code, with a loud `::notice::` explaining nothing was compared. D-17's breaking-label exception recorded as Phase 9 follow-up, not silently dropped.
- **The required-checks list, machine-checked in both directions.** `tests/Ci/RequiredChecksTest.php` parses all seven pull-request-triggered `.github/workflows/*.yml` files (17 job ids total) with `Symfony\Component\Yaml\Yaml` and asserts they agree exactly with `docs/repo/owner-gated-checklist.md`'s Required status checks list. `release-please.yml` is excluded via an explicit, tested allowlist. Proven non-vacuous by deliberate mutation in **both directions**: removing a documented job made the test fail naming it; adding an undocumented workflow job also made it fail naming it. Both mutations were reverted and confirmed byte-identical via `diff` before the suite was reconfirmed green.
- **`docs/repo/owner-gated-checklist.md`**: the complete owner-gated handoff register — branch protection (including the merge-commits-not-squash setting and the private-repo plan-tier caveat), the full 17-job required-checks list, Dependabot auto-merge as a distinct repository setting, Packagist registration blocked twice over (owner deferral + private-repo requirement), GitHub Pages pointing at plan 06's `docs/repo/docs-site-deploy.md` rather than restating it, confirming the `security@reyem.tech` mailbox, and FOUND-03.
- **FOUND-03 probe**: `docs/probes/association-inverse-probe.md` (the question, procedure, safety notes, and a deliberately empty results table) and `scripts/probes/association-inverse-probe.sh` (a `curl`-based implementation against the HubSpot v4 associations REST endpoints — never `hubspot/api-client`, since `Gateway` doesn't exist until Phase 2 and R1 confines `HubSpot\*` to that layer). The association type id is looked up live from the portal (`/crm/v4/associations/deals/contacts/labels`), never hardcoded/guessed. **Not run. No result fabricated or reasoned to.**

## Task Commits

1. **Additional task: fix the phpunit.xml.dist Arch-testsuite gap (done first)**
   - `ec06c6f` (test) — `tests/Ci/PhpunitTestsuitesTest.php`; confirmed RED (both assertions fail against the shipped config)
   - `89ca792` (fix) — added the `Arch` testsuite to `phpunit.xml.dist`; confirmed GREEN, bare `vendor/bin/pest` rose from 13 to 30 passed
   - `abb120a` (docs) — marked the gap resolved in `deferred-items.md`
2. **Task 1: release-please owns the version and the changelog, and publishes nothing**
   - `065527d` (feat) — `release-please-config.json`, `.release-please-manifest.json`, `version.txt`, `.github/workflows/release-please.yml`
3. **Task 2: Supply-chain audit and a backward-compatibility check that works on a greenfield**
   - `c437a93` (feat) — `.github/workflows/supply-chain.yml` (`composer-audit`, `bc-check`)
4. **Task 3: The required-checks list, machine-checked, and the two blocked-work records**
   - `1542f37` (test) — `tests/Ci/RequiredChecksTest.php`; confirmed RED (3 of 5 assertions fail: `docs/repo/owner-gated-checklist.md` doesn't exist yet)
   - `3186bd7` (feat) — `docs/repo/owner-gated-checklist.md`, `docs/probes/association-inverse-probe.md`, `scripts/probes/association-inverse-probe.sh`, plus the `toContain()` bug fix in the RED test file; confirmed GREEN (5 passed, 46 assertions)

**Plan metadata:** committed separately as the final commit of this plan (see STATE.md/ROADMAP.md commit below).

## Files Created/Modified

- `tests/Ci/PhpunitTestsuitesTest.php` — locks `phpunit.xml.dist`'s testsuite list to `['Feature', 'Ci', 'Arch']`
- `phpunit.xml.dist` — added the `Arch` testsuite (`tests/Arch`)
- `.planning/phases/01-foundation-gates/deferred-items.md` — marked the Arch-testsuite gap resolved
- `release-please-config.json` — `release-type: simple`, `package-name`, `changelog-path`
- `.release-please-manifest.json` — `{".": "0.0.0"}`
- `version.txt` — `0.0.0`
- `.github/workflows/release-please.yml` — push-to-main only, no publish step
- `.github/workflows/supply-chain.yml` — `composer-audit`, `bc-check` (tag-absence-guarded)
- `tests/Ci/RequiredChecksTest.php` — parses real workflow YAML, locks the required-checks list bidirectionally
- `docs/repo/owner-gated-checklist.md` — branch protection + 17-job required-checks list, Dependabot auto-merge, Packagist, Pages, security mailbox, FOUND-03
- `docs/probes/association-inverse-probe.md` — the FOUND-03 procedure and empty results table
- `scripts/probes/association-inverse-probe.sh` — the curl-based FOUND-03 implementation

## Decisions Made

See `key-decisions` in the frontmatter for full reasoning on: the A4 confirmation (release-type: simple, no live network fetch available in this environment), the manifest/version.txt starting at 0.0.0, why roave is installed per-job rather than in composer.json (with the empirical `--with-all-dependencies` finding), the actually-installed roave version (8.21.0, PHP ~8.4.0|~8.5.0), why D-17's breaking-label exception is deferred to Phase 9 rather than wired now, why the required-checks test compares job ids rather than display names, why the checklist's Required-status-checks prose avoids stray backticks, and the Pest `toContain()` variadic-argument bug found and fixed.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] roave/backward-compatibility-check would not resolve without `--with-all-dependencies`**
- **Found during:** Task 2, before writing the `bc-check` job's install step
- **Issue:** A plain `composer require --dev roave/backward-compatibility-check --no-interaction --no-progress` fails to resolve at all against this project's own dependency tree: `composer.lock` already pins `symfony/console` to a major roave's own constraint across every one of its releases rejects, and Composer refuses to touch an already-locked package during a partial update without explicit permission.
- **Fix:** Verified empirically in a scratch copy of this project's `composer.json`/`composer.lock`/`vendor/` (never the tracked working tree) before writing the workflow step: `--with-all-dependencies` resolves cleanly, installing `roave/backward-compatibility-check` 8.21.0. Added the flag to the workflow step with an explanatory comment.
- **Files modified:** `.github/workflows/supply-chain.yml`
- **Committed in:** `c437a93`

**2. [Rule 1 - Bug] A workflow comment explaining "no continue-on-error, no `|| true`" tripped its own grep check**
- **Found during:** Task 2, running the plan's own `<verify>` grep command
- **Issue:** The first draft's comment above the tag-absence guard literally contained the substrings `continue-on-error` and `|| true` (as prose explaining their absence), which `grep -qE 'continue-on-error|\|\| true'` cannot distinguish from an actual escape hatch — mirrors the same self-tripping-comment pattern 01-03 and 01-05 both hit and fixed the same way.
- **Fix:** Reworded the comment to describe the same intent ("no unconditional success-on-failure step and no advisory-style opt-out") without the literal matched substrings.
- **Files modified:** `.github/workflows/supply-chain.yml`
- **Committed in:** `c437a93`

**3. [Rule 1 - Bug] A workflow comment mentioning "Packagist" tripped the release-please.yml verify check**
- **Found during:** Task 1, running the plan's own `! grep -riE 'packagist'` verify command
- **Issue:** The first draft's explanatory comments about *why* the workflow never publishes referenced "Packagist" by name twice, which the plan's own verify command (correctly) treats as a signal that a publish step might exist.
- **Fix:** Reworded the comments to describe the same rationale (a separate, owner-gated registry integration; the package looking abandoned without it) without the literal word.
- **Files modified:** `.github/workflows/release-please.yml`
- **Committed in:** `065527d`

**4. [Rule 1 - Bug] `tests/Ci/RequiredChecksTest.php`'s backtick-extraction regex picked up prose words as false-positive documented job ids**
- **Found during:** Task 3, first GREEN attempt after writing `docs/repo/owner-gated-checklist.md`
- **Issue:** The Required-status-checks section's prose used markdown code-ticks around non-job-id words for emphasis (`` `max` ``, `` `laravel` ``, `` `TODO`/`FIXME` ``, `` `SECURITY.md` ``, `` `release-please` ``, `` `main` ``), all of which matched the test's job-id-extraction regex and surfaced as spurious "stale" entries.
- **Fix:** Reworded the prose to describe the same content without code-ticks around non-job-id words (e.g. "level max" instead of "level `` `max` ``"). Deliberately did not loosen the regex instead — a looser regex would let a genuinely stale documented job name hide among false positives rather than failing loudly.
- **Files modified:** `docs/repo/owner-gated-checklist.md`
- **Committed in:** `3186bd7`

**5. [Rule 1 - Bug] Pest's `toContain($value, $message)` is variadic, not `(value, message)`**
- **Found during:** Task 3, debugging why the third test assertion failed unconditionally even after the checklist was correct
- **Issue:** `expect($documented)->toContain($job, "Expected {$filename}'s...")` passes its second argument as *another value the array must also contain*, not a human-readable failure message — every call failed regardless of which job was actually present, since the array never literally contains the explanatory sentence.
- **Fix:** Replaced with `expect(in_array($job, $documented, true))->toBeTrue($message)`, whose second argument genuinely is a message.
- **Files modified:** `tests/Ci/RequiredChecksTest.php`
- **Committed in:** `3186bd7`

---

**Total deviations:** 5 auto-fixed (1 Rule 3 blocking dependency-resolution fix, 4 Rule 1 bugs — two self-tripping grep-check comments, one false-positive regex-extraction issue, one Pest API misuse). No scope creep — all five were necessary to deliver the plan's actual stated verify commands and done criteria.

## Issues Encountered

**The roave/backward-compatibility-check dependency-resolution investigation (see Deviation 1)** required setting up a disposable scratch copy of this project's `composer.json`/`composer.lock`/`vendor/` outside the tracked working tree, running `composer require --dev roave/backward-compatibility-check` against it (confirmed failing without `--with-all-dependencies`, confirmed succeeding at 8.21.0 with it), then deleting the scratch copy entirely. The tracked repository's own `composer.json`/`composer.lock` were never touched by this investigation — confirmed via `git status --short` showing no drift before and after.

**Proving the required-checks test is non-vacuous required deliberate mutation in both directions**, each done against the real tracked files, confirmed via `diff` to restore byte-identically afterward: (1) removing the `bc-check` bullet from `docs/repo/owner-gated-checklist.md` made the test fail naming that exact job; (2) adding a throwaway `new-undocumented-job` to `.github/workflows/supply-chain.yml` made the test fail naming that job too. Both were the mutation this test exists to catch, not a hypothetical.

## User Setup Required

None required to complete this plan. Everything this plan could not do without the owner is now recorded, as a checklist, in `docs/repo/owner-gated-checklist.md`:

1. **Branch protection on `main`**, including the exact 17-job required-checks list.
2. **Dependabot auto-merge** — a repository setting distinct from `dependabot.yml`'s grouping.
3. **Packagist registration and the GitHub↔Packagist integration** — blocked twice over (owner has deferred publishing; Packagist also requires a public repository).
4. **GitHub Pages deploy** — needs a paid plan on this private repository; the procedure is already written (plan 06's `docs/repo/docs-site-deploy.md`).
5. **Confirm `security@reyem.tech` exists and is monitored.**
6. **FOUND-03**, unblocked by exactly one thing: a HubSpot developer test account access token.

## Next Phase Readiness

- **Phase 1 is complete.** All seven plans have landed. Every standards gate from `.planning/phases/01-foundation-gates/01-CONTEXT.md` is wired, proven to fire on a real violation where applicable, and required (pending the owner actually flipping branch protection on, per the checklist above).
- **Two gates remain red on the empty package for a structural reason already documented by 01-01 and 01-05, not a defect of this plan:** the `tests` job's `--coverage --min=95` and the `mutation` job's `pest --mutate --min=80` both exit 1 against the deliberately-empty `src/` (PHPUnit's `failOnPhpunitWarning` turning a "no files matched" warning into a clean failure). This plan's own fix (the `Arch` testsuite) does not change that outcome — confirmed directly, `--coverage --min=95` still exits 1 with the identical mechanism, now over 30 tests instead of 13. Both resolve automatically the moment Phase 2 adds the first real file under `src/`. **Do not lower either threshold or remove either flag** — carried forward accurately, per this plan's explicit instruction.
- **FOUND-03 remains open**, blocked on exactly the HubSpot developer test account token — the procedure and script are ready to run the moment that token exists.
- **The required-checks test is now the permanent guard** against a future phase shipping an eighth (or eighteenth) gate and never making it required — any new pull-request-triggered workflow job must be added to `docs/repo/owner-gated-checklist.md` or `tests/Ci/RequiredChecksTest.php` fails immediately.

---
*Phase: 01-foundation-gates*
*Completed: 2026-07-27*

## Self-Check: PASSED

All 10 created files and 2 modified files confirmed present on disk; all 7 task commit hashes
(`ec06c6f`, `89ca792`, `abb120a`, `065527d`, `c437a93`, `1542f37`, `3186bd7`) confirmed present in
`git log --oneline --all`.
