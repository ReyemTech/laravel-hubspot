---
phase: 01-foundation-gates
plan: 01
subsystem: infra
tags: [composer, pest, orchestra-testbench, github-actions, ci-matrix, code-coverage, psr-4]

# Dependency graph
requires: []
provides:
  - "composer.json with exactly seven production requires (php ^8.3, hubspot/api-client, four illuminate packages, laravel/prompts) and the full Pest 4 / PHPStan / PHPCS+Slevomat / testbench require-dev stack"
  - "PSR-4 root ReyemTech\\Hubspot\\ -> src/, proven live via Composer\\Autoload\\ClassLoader::getPrefixesPsr4() rather than trusted from the manifest text"
  - "Six empty layer directories under src/ (Gateway, Registry, Sync, Webhooks, Signals, Frontend) for plan 03's architecture tests and plan 04's PHPStan analysis to resolve through"
  - "tests/Pest.php + tests/TestCase.php (Orchestra\\Testbench\\TestCase) — the Pest bootstrap every later test file in this repo binds to"
  - ".github/workflows/ci.yml with a tests job running the full 16-job matrix (php x laravel x stability, include/exclude for the non-rectangular support matrix), a composer-validate job, and a manifest job"
  - "tests/Ci/MatrixShapeTest.php — parses the shipped ci.yml with Symfony\\Component\\Yaml\\Yaml::parseFile() and re-derives the 16-job expansion, so the job count is proven against real YAML, not a comment"
  - "tests/Ci/ComposerManifestTest.php — locks the manifest to exactly seven production requires, the exact key set, the exact illuminate constraint, php 8.3-admits/8.2-rejects, and no version key"
affects: [ci-matrix, architecture-tests, phpstan-gate, release-please, ship-phase]

# Tech tracking
tech-stack:
  added:
    - "pestphp/pest ^4.0, pestphp/pest-plugin-laravel ^4.0, pestphp/pest-plugin-arch ^4.0 (single-major, no dual constraint — floor is PHP ^8.3)"
    - "phpstan/phpstan ^2.2, larastan/larastan ^3.10, phpstan/extension-installer ^1.4"
    - "orchestra/testbench ^9.0|^10.0|^11.0"
    - "squizlabs/php_codesniffer ^4.0, slevomat/coding-standard ^8.31 (pulls in dealerdirect/phpcodesniffer-composer-installer transitively)"
    - "laravel/pint ^1.0"
    - "symfony/yaml ^7.0|^8.0 (require-dev; parses the real ci.yml in tests/Ci/MatrixShapeTest.php)"
    - "composer/semver ^3.4 (require-dev; added mid-plan, see Decisions Made — used directly by tests/Ci/ComposerManifestTest.php to evaluate the php version constraint)"
  patterns:
    - "Tracer-first CI: task 1 ships a single runnable job before task 2 expands to the full matrix, so a broken spine surfaces after one commit rather than after sixteen"
    - "Live-registration assertions over hardcoded strings: the PSR-4 test reads ClassLoader::getPrefixesPsr4() and the CI-shape test parses the real ci.yml with a Symfony Yaml-based matrix-expansion re-implementation, rather than asserting against string literals"
    - "Manual violation-and-revert to prove a locking test is non-vacuous, used in place of a literal RED commit when the underlying fact already holds from an earlier task's own TDD cycle (see Decisions Made)"

key-files:
  created:
    - composer.json
    - phpunit.xml.dist
    - tests/Pest.php
    - tests/TestCase.php
    - tests/Feature/PackageSkeletonTest.php
    - tests/Ci/MatrixShapeTest.php
    - tests/Ci/ComposerManifestTest.php
    - src/Gateway/.gitkeep
    - src/Registry/.gitkeep
    - src/Sync/.gitkeep
    - src/Webhooks/.gitkeep
    - src/Signals/.gitkeep
    - src/Frontend/.gitkeep
    - .github/workflows/ci.yml
  modified: []

key-decisions:
  - "composer/semver added to require-dev (not in 01-RESEARCH.md's original list) because tests/Ci/ComposerManifestTest.php uses it directly to validate the php constraint's version range; it was already present transitively via orchestra/testbench's chain, so this only makes an existing implicit dependency explicit. Verified legitimate directly against Packagist: official composer/* org package, 532M total downloads — not a research-doc gap, a justified mid-plan addition."
  - "tests/Ci/ComposerManifestTest.php could not be committed with a literal RED-then-GREEN history, because composer.json already satisfied every assertion in it from task 1's own commit. Per the fail-fast rule (\"the feature may already exist\"), this was investigated rather than silently accepted: the test was run against a live, uncommitted mutation of composer.json (an eighth dependency added, then reverted) and confirmed to fail with the exact expected diagnostic before being confirmed green against the real file and committed. See the test commit message (4834e4b) for the full verbatim investigation."
  - "The coverage floor (--min=95) on the deliberately empty src/ was investigated by running it, not by reasoning about it, per the plan's explicit instruction. The observed behavior is a third outcome distinct from the two the plan named as acceptable ('100%' or '0%'): PHPUnit's own runner-level source-filter check finds zero .php files under src/, emits a warning, and deactivates coverage collection before Pest's Coverage plugin ever computes a percentage; PHPUnit's failOnPhpunitWarning default (true) turns that into a clean, deterministic exit 1 with all real tests still reported as passing. Two alternative 'fixes' were evaluated and rejected: (1) setting failOnPhpunitWarning=false does not make the command complete — it makes Pest crash with an uncaught Pest\\Exceptions\\ShouldNotHappen, since the coverage report file is never generated when collection was deactivated; (2) adding a placeholder .php file under src/ does produce a graceful vacuous-100% pass (verified experimentally, then discarded/not committed) but would violate CONTEXT.md's locked instruction that the six layer directories 'stay empty' this phase. The current, committed behavior (clean WARN + deterministic exit 1, --min=95 unchanged) was kept as the more honest of the three outcomes. This is flagged explicitly below as an open item, since it means the tests required check will show red in CI until Phase 2 lands the first file under src/ — in tension with CONTEXT.md's 'get every gate green on an empty package' framing, and worth the phase owner's attention before Phase 2 starts."

patterns-established:
  - "Any future test asserting a fact about ci.yml or composer.json should parse the real file (Yaml::parseFile / json_decode), not assert against a copied string, so plan 03/04's gates fail if the shipped config drifts from what's documented."
  - "When a locking test is written for a property an earlier task's TDD cycle already made true, prove non-vacuousness via a documented live-mutation-and-revert rather than skipping the RED requirement silently."

requirements-completed: [FOUND-01, REL-01]

coverage:
  - id: D1
    description: "A contributor clones the repository, runs composer install, runs vendor/bin/pest, and it is green with no HubSpot credentials, no .env file and no network access"
    requirement: FOUND-01
    verification:
      - kind: unit
        ref: "rm -rf vendor composer.lock && composer install --no-interaction && vendor/bin/pest (13 passed, exit 0, no .env present)"
        status: pass
    human_judgment: false
  - id: D2
    description: "The tests check runs across exactly 16 jobs (eight valid PHP x Laravel combinations, prefer-stable and prefer-lowest), with the Laravel-11-on-PHP-8.5 cell absent, proven against the real .github/workflows/ci.yml"
    requirement: FOUND-01
    verification:
      - kind: unit
        ref: "tests/Ci/MatrixShapeTest.php (5 tests, 56 assertions, pass)"
        status: pass
    human_judgment: false
  - id: D3
    description: "composer validate --strict exits 0"
    requirement: REL-01
    verification:
      - kind: unit
        ref: "composer validate --strict (with and without composer.lock present)"
        status: pass
    human_judgment: false
  - id: D4
    description: "composer.json declares exactly seven production requires and the illuminate constraint ^11.0|^12.0|^13.0"
    requirement: FOUND-01
    verification:
      - kind: unit
        ref: "tests/Ci/ComposerManifestTest.php (5 tests, 19 assertions, pass)"
        status: pass
    human_judgment: false
  - id: D5
    description: "The observed behavior of pest --coverage --min=95 against an empty src/ is recorded as fact, and --min=95 remains in the workflow"
    requirement: FOUND-01
    verification:
      - kind: unit
        ref: "vendor/bin/pest --coverage --min=95 (observed: WARN + exit 1, all 8 real tests pass, no coverage percentage computed)"
        status: pass
    human_judgment: true
    rationale: "The observed behavior itself is machine-verified and recorded verbatim, but whether the resulting red 'tests' CI check for the remainder of Phase 1 is an acceptable interim state (vs. a gap in 'every gate green on an empty package') is a judgment call for the phase owner — see Decisions Made and Next Phase Readiness."

duration: 20min
completed: 2026-07-26
status: complete
---

# Phase 1 Plan 01: Package Skeleton, Pest Bootstrap, and the 16-Job CI Matrix Summary

**composer.json with exactly seven production requires, a live-verified PSR-4 root, six empty layer directories, and a 16-job GitHub Actions matrix proven against the shipped YAML rather than a comment.**

## Performance

- **Duration:** ~20 min (commit span 19:44–19:59 UTC; includes upfront Packagist re-verification of every pin)
- **Completed:** 2026-07-26T19:59:07Z
- **Tasks:** 3
- **Files created:** 14

## Accomplishments
- `composer.json` for `reyemtech/laravel-hubspot`: exactly seven production requires (`php: ^8.3`, `hubspot/api-client: ^14.1`, four `illuminate/*` packages at `^11.0|^12.0|^13.0`, `laravel/prompts`), full Pest 4 / PHPStan / PHPCS+Slevomat / testbench dev stack, no `version` key. Every pin re-verified against the live Packagist API before committing (not from 01-RESEARCH.md's cache or recall) — see the raw `p2` API queries run at the start of this session for `pestphp/pest`, `pestphp/pest-plugin-arch`, `pestphp/pest-plugin-laravel`, `phpstan/phpstan`, `larastan/larastan`, `orchestra/testbench`, `squizlabs/php_codesniffer`, `slevomat/coding-standard`, `laravel/pint`, `symfony/yaml`, `hubspot/api-client`, `laravel/prompts`, `illuminate/contracts`.
- `tests/Feature/PackageSkeletonTest.php` proves three things live rather than asserting hardcoded strings: a Laravel app boots under `orchestra/testbench` and resolves from the container; the `ReyemTech\Hubspot\` PSR-4 prefix is registered at `src/` per `Composer\Autoload\ClassLoader::getPrefixesPsr4()` (found via `spl_autoload_functions()`, with `..`-segment path normalization since Composer's generated path literally contains `vendor/composer/../../src`); and all six layer directories exist.
- Six empty `src/` layer directories (`Gateway`, `Registry`, `Sync`, `Webhooks`, `Signals`, `Frontend`), each with only a `.gitkeep` — no domain code, per CONTEXT.md's explicit "Gateway starts in Phase 2" instruction.
- `.github/workflows/ci.yml` `tests` job expands to exactly the 16-job matrix from STANDARDS §1: `php: ['8.3','8.4','8.5']` x `laravel: ['11.*','12.*','13.*']` x `stability: [prefer-lowest, prefer-stable]`, `include` mapping each Laravel major to its testbench major (11→9, 12→10, 13→11), one `exclude` entry removing the single invalid cell (PHP 8.5 x Laravel 11), `fail-fast: false`. Proven against the real YAML by `tests/Ci/MatrixShapeTest.php`, which re-implements GitHub Actions' cross-product/exclude/include expansion in PHP and parses the shipped file with `Symfony\Component\Yaml\Yaml::parseFile()`.
- `composer-validate` and `manifest` jobs added as their own named required checks (D-29): the former runs `composer validate --strict`, the latter installs dependencies and runs `tests/Ci/ComposerManifestTest.php`, which locks the manifest to exactly the seven approved packages, the exact `illuminate/*` constraint string, a php constraint that admits `8.3.0` and rejects `8.2.0` (via `composer/semver`), and no `version` key.
- Coverage floor wired into the `tests` job (`vendor/bin/pest --coverage --min=95`, `pcov` as the driver via `shivammathur/setup-php`), with its exact behavior on the empty `src/` observed and recorded rather than assumed (see Decisions Made and the coverage `D5` entry above).

## Task Commits

Each task followed RED-then-GREEN; task 3's manifest test is a documented exception (see Decisions Made):

1. **Task 1: One green path — skeleton, Pest, and a single CI job wired end to end**
   - `989bba2` (chore) — composer.json, phpunit.xml.dist, tests/Pest.php, tests/TestCase.php (test-runner infrastructure, not the behavior under test)
   - `5732206` (test) — `tests/Feature/PackageSkeletonTest.php`; confirmed RED against `src/` absent (the six-directories assertion failed while app-boot and PSR-4-registration already passed)
   - `9155eb3` (feat) — six `src/` layer directories + one-job `ci.yml`; confirmed GREEN
2. **Task 2: Expand to the full 16-job matrix and wire the 95% coverage floor**
   - `b5403d3` (test) — `tests/Ci/MatrixShapeTest.php`; confirmed RED against the single-job workflow (TypeError, no `strategy.matrix` existed yet)
   - `d066180` (feat) — full 16-job matrix + coverage floor in `ci.yml`; confirmed GREEN (5 tests, 56 assertions)
3. **Task 3: Lock the manifest — seven production requires and `composer validate --strict`**
   - `0f4b813` (chore) — `composer/semver` added to require-dev
   - `4834e4b` (test) — `tests/Ci/ComposerManifestTest.php`; already green against the existing manifest (documented non-vacuousness check, see Decisions Made)
   - `f845708` (feat) — `composer-validate` and `manifest` jobs added to `ci.yml`; confirmed GREEN

**Plan metadata:** committed separately as the final commit of this plan (see STATE.md/ROADMAP.md commit below).

## Files Created/Modified
- `composer.json` — seven production requires, full dev-tooling stack, PSR-4 `ReyemTech\Hubspot\` → `src/`, no `version` key
- `phpunit.xml.dist` — `failOnRisky`/`failOnSkipped`/`failOnIncomplete` enabled, coverage `<source>` pointed at `src/`
- `tests/Pest.php` — binds `TestCase` across `Feature`/`Unit`
- `tests/TestCase.php` — extends `Orchestra\Testbench\TestCase`
- `tests/Feature/PackageSkeletonTest.php` — app-boot, live PSR-4 registration, six-directory existence
- `tests/Ci/MatrixShapeTest.php` — parses and re-derives the 16-job matrix expansion from the real `ci.yml`
- `tests/Ci/ComposerManifestTest.php` — locks the manifest shape
- `src/{Gateway,Registry,Sync,Webhooks,Signals,Frontend}/.gitkeep` — six empty layer directories
- `.github/workflows/ci.yml` — `tests` (16-job matrix + coverage floor), `composer-validate`, `manifest` jobs

## Decisions Made
See `key-decisions` in the frontmatter for the full, detailed reasoning on:
1. Adding `composer/semver` to require-dev mid-plan (legitimacy-verified against Packagist directly).
2. The documented non-vacuousness check used in place of a literal RED commit for `tests/Ci/ComposerManifestTest.php`.
3. The empty-`src/` coverage investigation and the decision to keep the observed WARN+exit-1 behavior rather than either of the two rejected "fixes."

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Fixed a test bug in the PSR-4 registration assertion**
- **Found during:** Task 1, writing `tests/Feature/PackageSkeletonTest.php`
- **Issue:** First draft used `require_once dirname(__DIR__, 2).'/vendor/autoload.php'` to obtain the `ClassLoader` instance; since `vendor/autoload.php` was already required by the test bootstrap, `require_once` returned `true` instead of the loader, causing `Call to a member function getPrefixesPsr4() on true`.
- **Fix:** Retrieve the already-registered `ClassLoader` from `spl_autoload_functions()` instead of re-requiring the bootstrap file.
- **Files modified:** `tests/Feature/PackageSkeletonTest.php`
- **Committed in:** `5732206` (part of the RED test commit — this was fixed before the RED commit was made, so the committed test is already correct)

**2. [Rule 1 - Bug] Fixed a path-normalization bug in the same assertion**
- **Found during:** Task 1, same test
- **Issue:** Composer's generated PSR-4 path is `.../vendor/composer/../../src` (contains literal `..` segments); a naive string comparison against `dirname(__DIR__, 2).'/src'` failed even though both paths point at the same directory.
- **Fix:** Added a small `normalizePath()` helper that collapses `.`/`..` segments without touching the filesystem (deliberately not `realpath()`, since the target directory does not exist yet at RED time and `realpath()` would return `false`).
- **Files modified:** `tests/Feature/PackageSkeletonTest.php`
- **Committed in:** `5732206`

**3. [Rule 3 - Blocking] Installed the `pcov` PHP extension locally**
- **Found during:** Task 2, running `vendor/bin/pest --coverage`
- **Issue:** No coverage driver (pcov or Xdebug) was installed in the local environment; `--coverage` failed immediately with "No code coverage driver is available."
- **Fix:** `sudo DEBIAN_FRONTEND=noninteractive apt-get install -y php8.5-pcov` (already present in the system's package cache, not a new/unverified source). This was necessary to actually observe the empty-`src/` coverage behavior the plan requires be recorded as fact rather than assumed.
- **Files modified:** none (system-level, not part of the repository)

---

**Total deviations:** 3 auto-fixed (2 Rule 1 bugs in test code, 1 Rule 3 blocking local-environment fix). No scope creep — all three were necessary to deliver the plan's actual required behavior and observations.

## Issues Encountered
- Investigated the empty-`src/` coverage-floor behavior in depth (see Decisions Made #3): read PHPUnit's `Runner\CodeCoverage::warnIfFilterIsNotConfigured()` and `ShellExitCodeCalculator` source directly, and Pest's `Plugins\Coverage::addOutput()`, to understand precisely why the command exits 1 without printing a coverage percentage. This was necessary because the observed behavior (a clean warning-driven exit 1) is a third outcome the plan's text did not explicitly enumerate (it named "100%", "0%", or "errors"), and choosing the right reaction required understanding the actual mechanism rather than guessing.

## User Setup Required
None — no external service configuration required.

## Next Phase Readiness
- `composer.json`, the Pest bootstrap, and the `src/` PSR-4 mapping — the load-bearing spine every other Phase 1 plan and Phase 2 depend on — are in place and proven live (not just declared).
- **Open item for the phase owner:** the `tests` required check in `.github/workflows/ci.yml` will report red for the remainder of Phase 1 specifically because of the `--min=95` coverage floor against an empty `src/` (see Decisions Made #3 for the full investigation and the two rejected alternatives). This resolves itself automatically the moment Phase 2 adds the first file under `src/`, at which point Pest's real coverage-percentage evaluation takes over unchanged. Flagging this explicitly since CONTEXT.md's phase mission is "get every gate green on an empty package," and this is the one gate where that could not be achieved without either violating the "`src/` stays empty" decision or accepting a crash instead of a clean failure.
- Plan 03 (architecture tests) and plan 04 (PHPStan) can now resolve `Gateway`/`Registry`/`Sync`/`Webhooks`/`Signals`/`Frontend` as real paths under the PSR-4 root.
- No blockers for the rest of Wave 1 — this plan touched only `composer.json`, `phpunit.xml.dist`, `tests/`, `src/*/.gitkeep`, and `.github/workflows/ci.yml`, disjoint from plan 01-02's governance/security files.

---
*Phase: 01-foundation-gates*
*Completed: 2026-07-26*

## Self-Check: PASSED

All 15 created files confirmed present on disk; all 8 task commit hashes (`989bba2`, `5732206`,
`9155eb3`, `b5403d3`, `d066180`, `0f4b813`, `4834e4b`, `f845708`) confirmed present in
`git log --oneline --all`.
