---
phase: 01-foundation-gates
plan: 04
subsystem: testing
tags: [pest, pest-plugin-arch, architecture-tests, strict-types, ci, phpstan]

# Dependency graph
requires:
  - phase: 01-foundation-gates
    provides: "composer.json, PSR-4 root ReyemTech\\Hubspot\\ -> src/, six empty layer directories, Pest 4 bootstrap (01-01)"
provides:
  - "The ten architecture rules (D-09 as amended by D-35, plus D-34 and D-19) encoded in tests/Arch/LayerBoundariesTest.php (R1-R8), tests/Arch/StrictTypesTest.php (R9), tests/Arch/SecretLoggingTest.php (R10)"
  - "A permanent, non-mutating firing harness (scripts/ci/verify-arch-rules-fire.sh + tests/Arch/Fixtures/ + tests/Arch/rules.json) that proves every rule fails a build under its own violation fixture, re-run on every PR via .github/workflows/arch.yml"
  - "The empty-namespace vacuous-pass question (01-RESEARCH.md assumption A2) resolved as observed fact: PASS, not error or skip"
  - "A Composer\\Autoload\\ClassLoader PSR-4-override technique (scripts/ci/arch-fire-bootstrap.php) for testing violation fixtures without mutating the working tree, replacing the plan's originally-suggested git-worktree-plus-symlinked-vendor approach, which was tried and found to crash"
affects: [phase-2-gateway, phase-2-registry, ship-phase, phpstan-gate, pint-gate]

# Tech tracking
tech-stack:
  added:
    - "pestphp/pest-plugin-arch ^4.0 (already installed by 01-01; this plan is its first consumer) — confirmed v4.0.2 installed"
  patterns:
    - "Firing-harness-before-rules: build a harness that proves a rule fires under a deliberate violation BEFORE writing the rule, so a rule over an empty namespace has a real RED signal to go green against, not just a vacuous pass"
    - "ClassLoader PSR-4 override for isolated fixture testing: override the already-registered Composer\\Autoload\\ClassLoader's PSR-4 mapping in-process (via a --bootstrap script) to point at a scratch src/ copy, rather than relocating vendor/ itself"
    - "Rule-id-prefixed arch() descriptions ('R1: ...') filtered via --filter='R1:' to isolate exactly one rule's test per harness run, avoiding collateral cross-rule interference"

key-files:
  created:
    - tests/Arch/rules.json
    - tests/Arch/FiringHarnessTest.php
    - tests/Arch/LayerBoundariesTest.php
    - tests/Arch/StrictTypesTest.php
    - tests/Arch/SecretLoggingTest.php
    - tests/Arch/Fixtures/R1/GatewayOnlyViolation.php
    - tests/Arch/Fixtures/R2/RegistryDependsOnSync.php
    - tests/Arch/Fixtures/R2/SyncTarget.php
    - tests/Arch/Fixtures/R3/SyncDependsOnWebhooks.php
    - tests/Arch/Fixtures/R3/WebhooksTarget.php
    - tests/Arch/Fixtures/R4/WebhooksDependsOnSync.php
    - tests/Arch/Fixtures/R4/SyncTarget.php
    - tests/Arch/Fixtures/R5/SignalsDependsOnFrontend.php
    - tests/Arch/Fixtures/R5/FrontendTarget.php
    - tests/Arch/Fixtures/R6/FrontendDependsOnRegistry.php
    - tests/Arch/Fixtures/R6/RegistryTarget.php
    - tests/Arch/Fixtures/R7/SignalsDependsOnSync.php
    - tests/Arch/Fixtures/R7/SyncTarget.php
    - tests/Arch/Fixtures/R8/FrontendUsesSdkDirectly.php
    - tests/Arch/Fixtures/R9/MissingStrictTypes.php
    - tests/Arch/Fixtures/R10/LogsTheAccessToken.php
    - scripts/ci/verify-arch-rules-fire.sh
    - scripts/ci/arch-fire-bootstrap.php
    - .github/workflows/arch.yml
  modified: []

key-decisions:
  - "The plan's suggested harness mechanism (scratch git worktree with a symlinked vendor/) was tried first, empirically, and found broken: Composer's generated vendor/composer/autoload_*.php files compute the project root via dirname(__DIR__), and PHP's __DIR__ resolves *through* a symlink to the real target directory rather than the symlink's own location. A symlinked vendor/ inside a scratch worktree therefore still resolves the ReyemTech\\Hubspot\\ PSR-4 root back to the REAL repo's (empty) src/, never seeing the fixture. Worse, Pest's own vendor/bin/pest proxy independently re-requires the real vendor/autoload.php via the same symlink-following __DIR__ logic even after the scratch vendor/autoload.php already loaded, producing a fatal 'Cannot redeclare class' error (reproduced directly, see below). The harness instead runs against the real, unmodified vendor/ install and overrides only the ReyemTech\\Hubspot\\ PSR-4 mapping in-process via Composer\\Autoload\\ClassLoader::setPsr4(), through a --bootstrap script (scripts/ci/arch-fire-bootstrap.php). This is simpler, faster (no vendor traversal/symlinking), and still touches nothing in the real working tree, since the ClassLoader override and the scratch src/ copy both live entirely in a mktemp directory for the lifetime of one pest process."
  - "R10 (secrets never appear in log calls) is not expressible via pest-plugin-arch (it is not a dependency-graph rule), so it is a plain Pest test that scans every PHP file resolved through the *currently registered* ReyemTech\\Hubspot\\ PSR-4 mapping (not a hardcoded __DIR__.'/../../src' path), so the firing harness's PSR-4 override applies to it exactly like the arch() rules."
  - "R10's two secret config keys (hubspot.token, hubspot.webhooks.secret) are named explicitly per the plan's instruction, but are PROVISIONAL: config/hubspot.php does not exist until Phase 2 (Gateway starts then). hubspot.webhooks.secret is confirmed directly against the design spec (env('HUBSPOT_CLIENT_SECRET') mapped under webhooks.secret); hubspot.token is the best available name for the private-app access token, since the design spec never quotes its literal config key. This MUST be reconciled against the real config/hubspot.php the moment it ships."
  - "R6's positive allowlist (Frontend may depend only on the public facade) targets the FQCN ReyemTech\\Hubspot\\Facades\\Hubspot, inferred from the design spec's Hubspot::fake()/Hubspot::objects() usage pattern (docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md) rather than an explicit facade class declaration, since the facade does not exist until Phase 2. R8 (the negative form, fully expressible today) carries the real enforcement weight until then, exactly as the plan anticipated."
  - "Rule descriptions are prefixed 'R{n}: ' (e.g. 'R7: Signals may not depend on...') specifically so the firing harness can isolate exactly one rule per run via --filter='R{n}:' (confirmed empirically that Pest's --filter matches the human-readable description text, not the internal PHP method name, and that 'R1:' vs 'R10:' do not collide as substrings)."
  - "PHPStan (max level, no baseline per D-04/STANDARDS §3) flagged 28 errors in the new files even though this plan's own <verify> steps do not run PHPStan. Fixed as a CLAUDE.md/STANDARDS-precedence correctness requirement (Rule 2): the two genuine bugs (RecursiveIteratorIterator yielding a mixed-typed value; a list<string> vs array<int, string> mismatch) were fixed properly via an instanceof SplFileInfo runtime guard and a corrected return type; the one unfixable class (Pest's arch()->expect()->toXxx() higher-order-test chain has no PHPStan stub for its dynamic method dispatch) is suppressed per-line via '@phpstan-ignore method.notFound, ...' with a written reason, never a baseline."

patterns-established:
  - "Any later phase adding an eleventh architecture rule MUST add a fixture under tests/Arch/Fixtures/<id>/ and register it in tests/Arch/rules.json, or tests/Arch/FiringHarnessTest.php fails immediately — an unfired rule and a missing fixture are the same defect, and this is now mechanically enforced, not just documented."
  - "A file scanning src/ for anything other than a dependency-graph fact (R10's log-call scan) must resolve its own root via the live Composer\\Autoload\\ClassLoader (spl_autoload_functions() -> getPrefixesPsr4()), never a hardcoded __DIR__-relative path, so it stays correct under the firing harness's PSR-4 override."

requirements-completed: [FOUND-05]

coverage:
  - id: D1
    description: "All ten architecture rules (R1-R10) are encoded and vendor/bin/pest tests/Arch is green over the real, empty src/ tree"
    requirement: FOUND-05
    verification:
      - kind: unit
        ref: "vendor/bin/pest tests/Arch (15 passed, 100 assertions)"
        status: pass
    human_judgment: false
  - id: D2
    description: "Every one of the ten rules is proven to fail the build under its own dedicated violation fixture, via a harness that never mutates the working tree"
    requirement: FOUND-05
    verification:
      - kind: unit
        ref: "bash scripts/ci/verify-arch-rules-fire.sh (Architecture rule firing proof: 10/10 rules fired, exit 0)"
        status: pass
    human_judgment: false
  - id: D3
    description: "Removing a rule makes the firing harness exit non-zero, naming that rule; the harness leaves the working tree byte-identical before/after, including a run killed mid-flight with SIGTERM"
    requirement: FOUND-05
    verification:
      - kind: unit
        ref: "Manual R2-removal experiment (harness reported 'Rules that could not be verified: R2', exit 1, then restored); git status --porcelain before/after a normal run and a SIGTERM-killed run, both byte-identical"
        status: pass
    human_judgment: false
  - id: D4
    description: "The architecture suite and the firing harness both run as named, non-advisory required checks on every pull request"
    requirement: FOUND-05
    verification:
      - kind: unit
        ref: "grep -q 'verify-arch-rules-fire.sh' .github/workflows/arch.yml && ! grep -q 'continue-on-error' .github/workflows/arch.yml"
        status: pass
    human_judgment: false
  - id: D5
    description: "The empty-namespace behaviour of pest-plugin-arch (01-RESEARCH.md assumption A2, LOW confidence) is recorded as observed fact"
    requirement: FOUND-05
    verification:
      - kind: unit
        ref: "Direct empirical probe: both a positive (toOnlyUse) and negative (not->toUse) arch() expectation against an empty src/ tree exit 0 (PASS), not error or skip — see Issues Encountered below for the exact commands run"
        status: pass
    human_judgment: true
    rationale: "The observed technical fact is machine-verified, but whether R6's facade-FQCN choice and R10's provisional config-key list are the right names is a judgment call for the phase owner to confirm once Phase 2 ships the real facade and config/hubspot.php."

duration: 29min
completed: 2026-07-26
status: complete
---

# Phase 1 Plan 04: Architecture Rules and the Firing Harness Summary

**Ten architecture rules (R1-R10) encoded in pest-plugin-arch 4.0.2, each proven to fail a build under its own violation fixture by a non-mutating harness that overrides Composer's PSR-4 mapping in-process rather than relocating vendor/ — the plan's suggested git-worktree approach was tried first and found to crash.**

## Performance

- **Duration:** ~29 min (commit span 20:23-20:32 UTC, plus substantial upfront empirical investigation before the first commit)
- **Completed:** 2026-07-26T20:32:14Z
- **Tasks:** 3
- **Files created:** 23

## Accomplishments
- **tests/Arch/rules.json** — the canonical manifest of all ten rules (id, description, fixture file list), read by both the bash harness and `tests/Arch/FiringHarnessTest.php` so they can never drift apart.
- **tests/Arch/Fixtures/** — sixteen fixture files across ten rule directories, each a genuinely-compiling PHP class carrying a header comment naming the rule it violates. Two-file "violator + target" pairs (R2-R7) exist because empirically, `pest-plugin-arch`'s dependency detection requires the imported class to actually resolve — importing a non-existent class name is silently NOT flagged as a violation (confirmed directly, see Issues Encountered).
- **scripts/ci/verify-arch-rules-fire.sh** — for each rule, merges its fixture(s) into a `mktemp`-scratch copy of `src/` (namespace-derived placement, e.g. `namespace ReyemTech\Hubspot\Registry;` → `<scratch>/Registry/`), runs one filtered `pest` invocation against it, and asserts the run goes red. Reports `10/10 rules fired`, exit 0, on the shipped tree; reports `0/10` (every rule "NOT FIRING") when run against a tree with no rules yet — the harness's own RED state before Task 2 existed.
- **scripts/ci/arch-fire-bootstrap.php** — the `--bootstrap` script the harness passes to `pest`, which overrides the already-registered `Composer\Autoload\ClassLoader`'s `ReyemTech\Hubspot\` PSR-4 mapping to point at the scratch directory, for that one process only.
- **tests/Arch/LayerBoundariesTest.php** (R1-R8), **tests/Arch/StrictTypesTest.php** (R9), **tests/Arch/SecretLoggingTest.php** (R10) — all ten rules, green over the real empty `src/` tree, each individually proven to go red under its own fixture.
- **.github/workflows/arch.yml** — two named required checks, single PHP version, no `continue-on-error`: the architecture suite and the firing harness.
- PHPStan (max level, no baseline) and Pint both pass on every file this plan created, even though this plan's own `<verify>` steps didn't require it — treated as a STANDARDS-precedence correctness requirement (see Deviations).

## Task Commits

1. **Task 1: A firing harness that cannot lie and cannot leave a mess** — `036a8a2` (test) — `tests/Arch/rules.json`, `tests/Arch/Fixtures/` (16 files), `tests/Arch/FiringHarnessTest.php`, `scripts/ci/verify-arch-rules-fire.sh`, `scripts/ci/arch-fire-bootstrap.php`; confirmed RED (harness reports all ten rules NOT FIRING, exits 1, before any rule existed) and confirmed the working tree is byte-identical before/after, including a SIGTERM-killed run
2. **Task 2: The ten rules, each driven red by its own fixture before it goes green** — `tests/Arch/LayerBoundariesTest.php`, `tests/Arch/StrictTypesTest.php`, `tests/Arch/SecretLoggingTest.php` were committed as `feat(01-04)` (022b9e6, see Deviations — landed inside a concurrent 01-05 commit due to a race, content verified correct and unchanged); confirmed GREEN (`vendor/bin/pest tests/Arch` passes, harness reports 10/10 fired, removing R2 makes the harness fail naming R2)
3. **Task 3: Wire both as required checks and settle the empty-namespace question** — `57b72a1` (feat) — `.github/workflows/arch.yml`
4. **Follow-up: PHPStan/Pint compliance** — `eae85ff` (style) — fixed two genuine PHPStan bugs and suppressed one documented, unfixable gap per-line (see Deviations)

**Plan metadata:** committed separately as the final commit of this plan.

## Files Created/Modified
- `tests/Arch/rules.json` — canonical rule manifest (id, description, fixture list) shared by the bash harness and `FiringHarnessTest.php`
- `tests/Arch/FiringHarnessTest.php` — manifest/fixture bidirectional agreement checks
- `tests/Arch/LayerBoundariesTest.php` — R1-R8, the six layer boundaries plus the two Signals/Frontend rules
- `tests/Arch/StrictTypesTest.php` — R9, package-wide `declare(strict_types=1)`
- `tests/Arch/SecretLoggingTest.php` — R10, a plain scan for secret config keys in log calls
- `tests/Arch/Fixtures/R1..R10/*.php` — sixteen violation fixtures, one to two files per rule
- `scripts/ci/verify-arch-rules-fire.sh` — the firing harness (executable, `set -euo pipefail`)
- `scripts/ci/arch-fire-bootstrap.php` — the PSR-4-override `--bootstrap` script
- `.github/workflows/arch.yml` — two named required checks: `architecture-tests`, `arch-rules-fire`

## Decisions Made
See `key-decisions` in the frontmatter for full reasoning on: the git-worktree-approach failure and the PSR-4-override replacement; R10's provisional config keys; R6's facade FQCN inference; the `R{n}:`-prefixed filtering convention; and the PHPStan/Pint compliance follow-up.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] The plan's suggested harness mechanism (scratch git worktree + symlinked vendor/) does not work — replaced with a PSR-4 ClassLoader override**
- **Found during:** Task 1, before writing the harness script
- **Issue:** Following the plan's literal suggestion (`git worktree add --detach`, symlink `vendor/` into it, place the fixture, run the suite from there) produces a harness that crashes. Reproduced directly: a scratch worktree with a symlinked `vendor/` resolves the `ReyemTech\Hubspot\` PSR-4 root back to the REAL repo's `src/` (not the scratch tree's) because Composer's generated `vendor/composer/autoload_*.php` files compute the project root via `dirname(__DIR__)`, and PHP's `__DIR__` follows a symlink to its real target rather than staying at the symlink's own location. Worse, Pest's own `vendor/bin/pest` proxy independently re-requires the real `vendor/autoload.php` via the same symlink-following logic, producing `PHP Fatal error: Cannot redeclare class ComposerAutoloaderInit...`.
- **Fix:** Run against the real, unmodified `vendor/` install and override just the `ReyemTech\Hubspot\` PSR-4 mapping in-process, for one `pest` run, via `Composer\Autoload\ClassLoader::setPsr4()` called from a `--bootstrap` script (`scripts/ci/arch-fire-bootstrap.php`). Confirmed empirically to correctly resolve fixtures placed in a scratch `src/` copy, confirmed to correctly leave rules unaffected when no fixture is present (vacuous pass preserved), and confirmed to leave the working tree byte-identical before/after (including a SIGTERM-killed run) without any `git worktree` bookkeeping to clean up.
- **Files modified:** `scripts/ci/verify-arch-rules-fire.sh`, `scripts/ci/arch-fire-bootstrap.php` (both new, no plan file changed shape)
- **Committed in:** `036a8a2`

**2. [Rule 2 - Missing Critical] PHPStan (max level, no baseline) and Pint compliance on all new files**
- **Found during:** After Task 3, a self-initiated check since PHPStan/Pint cleanliness is a binding STANDARDS §3/§5 requirement for the whole package even though this plan's own `<verify>` steps don't invoke either tool
- **Issue:** 28 PHPStan errors: two genuine bugs (a `RecursiveIteratorIterator` loop variable typed `mixed` because PHPStan can't resolve `RecursiveDirectoryIterator`'s SPL generics through the composed iterator; a `list<string>` vs `array<int, string>` return-type mismatch) plus 26 instances of one unfixable class — `Pest\PendingCalls\TestCall` has no `expect()`/`toOnlyUse()`/`not`/`toUseStrictTypes()` methods of its own; Pest's "higher-order test" mechanism re-binds calls made on `arch()`'s return value to execute inside the test body at runtime, and ships no PHPStan stub describing this.
- **Fix:** The two genuine bugs were fixed properly (an `instanceof SplFileInfo` runtime guard narrows the iterator value; the scan helper's docblock return type was corrected to `list<string>`). The unfixable class is suppressed per-line via `@phpstan-ignore method.notFound, method.nonObject` (or the matching identifier set) on each `arch()` call, each with a written reason in the file's header docblock — never a baseline, per D-04/STANDARDS §3.
- **Files modified:** `tests/Arch/FiringHarnessTest.php`, `tests/Arch/SecretLoggingTest.php`, `tests/Arch/LayerBoundariesTest.php`, `tests/Arch/StrictTypesTest.php`, `scripts/ci/arch-fire-bootstrap.php` (Pint formatting only)
- **Verification:** `vendor/bin/phpstan analyse tests/Arch scripts/ci/arch-fire-bootstrap.php` → `[OK] No errors`; `vendor/bin/pint --test tests/Arch scripts/ci/arch-fire-bootstrap.php` → `passed`; `vendor/bin/pest tests/Arch` and the firing harness both still green after
- **Committed in:** `eae85ff`

---

**Total deviations:** 2 auto-fixed (1 Rule 1 bug in the harness's core isolation mechanism, 1 Rule 2 missing-critical PHPStan/Pint compliance). No scope creep — both were necessary for the plan's actual required behavior (a working, non-mutating harness) and for the package's binding, project-wide quality gates.

## Issues Encountered

**Concurrency note (not a defect in this plan's output, disclosed for provenance accuracy):** Task 2's three rule-test files (`tests/Arch/LayerBoundariesTest.php`, `tests/Arch/StrictTypesTest.php`, `tests/Arch/SecretLoggingTest.php`) were staged (`git add`) and then, before this agent's own `git commit` ran, were swept into a commit made by the concurrently-running 01-05 agent in the same shared working directory: `022b9e6 test(01-05): add failing self-test for the source-hygiene gate`. The file *content* is correct and was verified working both before and after (re-ran `vendor/bin/pest tests/Arch` and the firing harness post-hoc — both green/10-fired). Per the destructive-git-prohibition and no-history-rewrite rules, this was NOT fixed by amending or rebasing; it is disclosed here as the accurate record of what commit each file landed in. All subsequent commits in this plan (`eae85ff`, `57b72a1`) used pathspec-scoped `git commit <paths>` specifically to avoid a repeat.

Three empirical investigations were required before writing any implementation, per the plan's explicit instruction not to take the vacuous-pass and harness-mechanism questions on trust:
1. **Empty-namespace behaviour (A2):** confirmed directly — `arch('...')->expect('ReyemTech\Hubspot\Registry')->toOnlyUse('ReyemTech\Hubspot\Gateway')` and a `not->toUse([...])` variant both `PASS` (exit 0) against the real, empty `src/` tree. Not an error, not a skip. This is the LOW-confidence claim in 01-RESEARCH.md, now closed as observed fact: **rules over an empty namespace pass silently.**
2. **The git-worktree-plus-symlinked-vendor approach:** reproduced the exact fatal error described in Deviation #1 above, using a real `git worktree add --detach` against this repository.
3. **Whether `pest-plugin-arch` requires a dependency's target class to actually exist:** confirmed it does — importing a non-existent class name from an internal layer is silently NOT flagged as a violation. This is why R2-R7's fixtures ship as "violator + target" file pairs rather than a single file each.
4. **`pest`'s `--filter` semantics:** confirmed it matches the human-readable TestDox description text (`arch('R1: ...')`'s string argument), not the internal PHP method name — and confirmed `'R1:'` and `'R10:'` do not collide as substrings, which is why every rule's description is prefixed `'R{n}: '`.

`A1`/`A5` (whether `pest-plugin-arch`'s method names — `toOnlyBeUsedIn`, `toOnlyUse`, `not->toUse`, `toUseStrictTypes` — match between the 3.x and 4.x lines) are closed by the single-major Pest 4 pin (01-01/01-CONTEXT.md's PHP floor raise to `^8.3`), not by a version check: this project only ever installs `pestphp/pest-plugin-arch: ^4.0`. **Installed version confirmed: `pestphp/pest-plugin-arch` v4.0.2** (`composer show pestphp/pest-plugin-arch`), all four method names confirmed present and working exactly as cited in 01-RESEARCH.md Pattern 3.

## User Setup Required
None — no external service configuration required.

## Next Phase Readiness
- All ten architecture rules are live, proven-firing gates. Phase 2 (`Gateway`) can add real classes under `src/Gateway/` and the rules will immediately start enforcing against real code, exactly as designed.
- **R6's facade allowlist and R10's config keys are provisional** and must be reconciled the moment Phase 2 ships `ReyemTech\Hubspot\Facades\Hubspot` and `config/hubspot.php` — flagged explicitly above and in the file headers themselves.
- The firing harness (`scripts/ci/verify-arch-rules-fire.sh`) is a permanent, reusable mechanism: any later phase adding an eleventh rule follows the same pattern (fixture directory + `rules.json` entry + `FiringHarnessTest.php`'s existing agreement checks catch a missing fixture automatically).
- No blockers for the rest of Wave 2 or Phase 1. This plan touched only `tests/Arch/`, `scripts/ci/verify-arch-rules-fire.sh`, `scripts/ci/arch-fire-bootstrap.php`, and `.github/workflows/arch.yml` — disjoint from plan 01-05's `phpstan.neon`/`pint.json`/code-shape files, though this plan's own new source files were held to those tools' standards (see Deviations #2).

---
*Phase: 01-foundation-gates*
*Completed: 2026-07-26*

## Self-Check: PASSED

All 23 created files confirmed present on disk; all 4 relevant commit hashes
(`036a8a2`, `022b9e6`, `57b72a1`, `eae85ff`) confirmed present in
`git log --oneline --all`.
