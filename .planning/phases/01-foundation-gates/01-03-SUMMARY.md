---
phase: 01-foundation-gates
plan: 03
subsystem: infra
tags: [pnpm, vitest, coverage-v8, github-actions, javascript, workspace]

# Dependency graph
requires:
  - phase: 01-foundation-gates
    provides: "composer.json, Pest bootstrap, six empty src/ layer directories (plan 01); nothing JS-side existed before this plan"
provides:
  - "pnpm-workspace.yaml at the repo root declaring both resources/js and site (site declared inert now so plan 06 never has to edit this file)"
  - "resources/js/ — a standalone pnpm workspace (package name hubspot-frontend), separate from the docs site, so the coverage gate that guards the Phase 8 origin-validating listener can never be diluted by documentation content"
  - "resources/js/vitest.config.ts — v8 coverage provider, thresholds 95 on lines/functions/branches/statements, coverage.include scoped to src/**/*.ts only, coverage.all: true"
  - "resources/js/src/index.ts + resources/js/tests/index.test.ts — a trivial, 100%-covered placeholder module (isNonEmptyString) proving the coverage mechanism works honestly, not vacuously"
  - "the 95% floor proven to reject a real build: a temporary uncovered export dropped coverage to 23.07% and vitest exited non-zero naming all four thresholds, verbatim output recorded below"
  - ".github/workflows/js.yml — a required CI check running the coverage gate with no advisory escape hatch"
affects: [phase-8-booking-listener, ci-matrix, ship-phase]

# Tech tracking
tech-stack:
  added:
    - "vitest 4.1.10 (exact-pinned, save-exact via .npmrc)"
    - "@vitest/coverage-v8 4.1.10 (exact-pinned)"
    - "pnpm 11.17.0 (installed locally via npm --prefix into ~/.local, since corepack's generated pnpm.cjs shim crashed with ERR_VM_DYNAMIC_IMPORT_CALLBACK_MISSING on this Node 22.22.1 install; CI uses pnpm/action-setup@v4 instead, which is unaffected)"
  patterns:
    - "Same tracer-then-firing-proof shape as plan 04 (arch rules) and plan 05 (quality gates): Task 1 ships one honestly-covered module and proves the gate passes for a real reason; Task 2 proves the same gate rejects a real violation before wiring it as a required CI check"
    - "A firing-proof mutation is made directly against the tracked file (not a scratch copy), the failing run's exact output is captured, then the file is restored byte-for-byte and reconfirmed green before anything is staged — mirrors 01-04/01-05's fixture-and-revert pattern but for a single already-committed file rather than a throwaway fixture directory"

key-files:
  created:
    - pnpm-workspace.yaml
    - .npmrc
    - resources/js/package.json
    - resources/js/vitest.config.ts
    - resources/js/tests/index.test.ts
    - resources/js/src/index.ts
    - .github/workflows/js.yml
  modified:
    - .gitignore

key-decisions:
  - "pnpm 11.17.0 installed via `npm install -g pnpm@11.17.0 --prefix ~/.local` rather than corepack, because `corepack enable`/`corepack prepare` produced a pnpm.cjs shim that crashed immediately with `ERR_VM_DYNAMIC_IMPORT_CALLBACK_MISSING` on this machine's Node 22.22.1 + corepack 0.24.0 combination. This is a local-environment workaround only (Rule 3, blocking) — CI's `pnpm/action-setup@v4` installs pnpm independently and is unaffected."
  - "Package legitimacy re-verified directly against the npm registry before install, per the plan's checkpoint and the owner's 2026-07-27 approval: `vitest`@4.1.10 and `@vitest/coverage-v8`@4.1.10 both resolve to `github.com/vitest-dev/vitest` with no postinstall script and 82.3M/31.7M weekly downloads respectively; `pnpm`@11.17.0 resolves to `github.com/pnpm/pnpm` with no postinstall script and 140.4M weekly downloads. All three names match 01-RESEARCH.md exactly, no transposed or hyphenated near-name. Nothing looked wrong; installed as approved."
  - "Node major for CI pinned to 20 — the lowest version resources/js/node_modules/vitest's own installed package.json `engines` field permits (`^20.0.0 || ^22.0.0 || >=24.0.0`, read via `npm view vitest engines` against the actually-installed 4.1.10), not copied from the stint reference (which pins Node 20 for an older Vitest major that happens to agree here, but was not the source of truth used)."
  - "The .npmrc comment documenting the intentional absence of an advisory escape hatch on the CI coverage step was reworded to avoid the literal substring the plan's own grep check searches for (mirrors 01-05's concatenated-fragment pattern for check-source-hygiene.sh) — the first draft's comment text itself matched `grep -q 'continue-on-error'` even though it was prose explaining the step does NOT have one."

patterns-established:
  - "Any future Vitest config change should keep coverage.include scoped to this workspace's own src/**/*.ts — widening it to catch site/ would silently dilute the floor that guards the Phase 8 listener (see workspace_placement_decision in 01-03-PLAN.md)."

requirements-completed: [FOUND-04]

coverage:
  - id: D1
    description: "pnpm install --frozen-lockfile then pnpm --filter './resources/js' test runs green from a clean clone with no network access after install"
    requirement: FOUND-04
    verification:
      - kind: unit
        ref: "pnpm install --frozen-lockfile && pnpm --filter './resources/js' test (3 tests passed, exit 0)"
        status: pass
    human_judgment: false
  - id: D2
    description: "The Vitest 95% line-coverage floor is proven to FAIL on an uncovered line, not merely observed to pass on a fully covered one"
    requirement: FOUND-04
    verification:
      - kind: unit
        ref: "vitest run --coverage against a temporary uncovered isAllowedOrigin export: exit 1, ERROR: Coverage for lines (23.07%) does not meet global threshold (95%) (plus functions/statements/branches, each named); verbatim output recorded in this SUMMARY"
        status: pass
    human_judgment: false
  - id: D3
    description: "The JavaScript coverage gate measures the frontend workspace only — coverage.include is scoped to resources/js/src/**/*.ts, and the docs site (declared in pnpm-workspace.yaml but not yet created) cannot dilute it"
    requirement: FOUND-04
    verification:
      - kind: unit
        ref: "resources/js/vitest.config.ts coverage.include: ['src/**/*.ts'] (workspace-relative, resolves only within resources/js/)"
        status: pass
    human_judgment: false
  - id: D4
    description: "Every npm package installed here was human-verified against its registry page before install, because 01-RESEARCH.md's legitimacy gate returned SUS for all of them"
    requirement: FOUND-04
    verification:
      - kind: manual_procedural
        ref: "npm view vitest/@vitest/coverage-v8/pnpm version + repository.url + scripts.postinstall, cross-checked against weekly download counts via api.npmjs.org — all three match 01-RESEARCH.md's expected names, repos and no-postinstall finding; owner approved proceeding with the install on 2026-07-27 (see plan's Checkpoint cleared note)"
        status: pass
    human_judgment: true
    rationale: "Package-legitimacy verification for a SUS verdict is a human-facing checkpoint by protocol; the owner's approval is recorded in the orchestrator's prompt to this executor, not re-derivable purely from CI output."

duration: ~20min
completed: 2026-07-27
status: complete
---

# Phase 1 Plan 03: Node/pnpm Toolchain and the JavaScript Coverage Floor Summary

**A standalone `resources/js/` pnpm workspace with Vitest at a 95% line-coverage floor, proven to reject a real build (23.07% lines, exit 1, all four thresholds named) before being wired as a required, non-advisory CI check.**

## Performance

- **Duration:** ~20 min (commit span 01:12–01:15 UTC on 2026-07-27, plus upfront package-legitimacy re-verification against the live npm registry and a local pnpm-install workaround)
- **Completed:** 2026-07-27
- **Tasks:** 2 (plus the preceding checkpoint, cleared by the owner's 2026-07-27 approval per the plan's own note)
- **Files created:** 7
- **Files modified:** 1 (`.gitignore`)

## Accomplishments

- **Checkpoint verification, done anyway despite the pre-clearance.** Re-ran the registry checks the checkpoint asked for: `vitest`@4.1.10 and `@vitest/coverage-v8`@4.1.10 both resolve to `github.com/vitest-dev/vitest` (82.3M / 31.7M weekly downloads, no `postinstall` script); `pnpm`@11.17.0 resolves to `github.com/pnpm/pnpm` (140.4M weekly downloads, no `postinstall` script). All three names exactly match 01-RESEARCH.md, no transposed or hyphenated near-name found. Proceeded with the install as the owner approved.
- **`pnpm-workspace.yaml`** at the repo root, declaring both `resources/js` and `site` — `site` does not exist yet, is inert, and plan 06 (wave 2) will never need to edit this file.
- **`resources/js/`**, a workspace of its own (package name `hubspot-frontend`), separate from `site/`, per the plan's explicit placement decision — the coverage number that gates a merge measures only this workspace's own `src/`.
- **`resources/js/vitest.config.ts`**: `coverage.provider: 'v8'`, `coverage.include: ['src/**/*.ts']`, `coverage.all: true`, `thresholds` of 95 on lines/functions/branches/statements.
- **`resources/js/src/index.ts` + `resources/js/tests/index.test.ts`**: `isNonEmptyString`, a trivial but genuinely-branching placeholder for the Phase 8 `postMessage` listener, 100% covered by 3 real test cases (true case, empty-string case, non-string case) — not a bare constant.
- **The 95% floor proven to fail a real build (not just configured).** A temporary second export (`isAllowedOrigin`, never committed) with no test reaching it dropped coverage to 23.07% lines / 20% branches / 50% functions / 23.07% statements; `vitest run --coverage` exited 1 and printed all four threshold failures by name. The file was then restored to its committed state and reconfirmed at a clean 100%/exit 0 before anything further was staged.
- **`.github/workflows/js.yml`**: `permissions: contents: read`, `pull_request` + push-to-`main` triggers, `pnpm/action-setup@v4`, `actions/setup-node@v4` pinned to Node 20 (the lowest major the installed `vitest`'s own `engines` field permits), `pnpm install --frozen-lockfile`, then the coverage run scoped to `resources/js` with no advisory escape hatch on that step.

## Task Commits

Task 1 followed RED-then-GREEN; Task 2's firing proof is documented here rather than as a separate commit, since the deliberate violation was never committed (mirrors plan 04/05's fixture-and-revert pattern, but against the tracked file directly):

1. **Scaffolding (chore, precedes the TDD pair):** `a6f4772` — `pnpm-workspace.yaml`, `.npmrc`, `resources/js/package.json`, `resources/js/vitest.config.ts`, `.gitignore`, `pnpm-lock.yaml`
2. **Task 1, RED:** `8558385` (test) — `resources/js/tests/index.test.ts`; confirmed RED: `vitest run` exits 1, `Cannot find module '../src/index'`
3. **Task 1, GREEN:** `ece98ae` (feat) — `resources/js/src/index.ts`; confirmed GREEN: 3 tests pass, coverage 100% on all four metrics
4. **Task 2:** `953abbf` (feat) — `.github/workflows/js.yml`, wired after the firing proof below was observed and the file restored to green

**Plan metadata:** committed separately as the final commit of this plan (see STATE.md/ROADMAP.md commit below).

## Files Created/Modified

- `pnpm-workspace.yaml` — declares `resources/js` and `site`
- `.npmrc` — `engine-strict=true`, `save-exact=true`
- `resources/js/package.json` — `hubspot-frontend` workspace, `vitest`/`@vitest/coverage-v8` pinned at `4.1.10`, `engines.node` matching vitest's own constraint
- `resources/js/vitest.config.ts` — v8 coverage provider, 95% thresholds, `include` scoped to `src/**/*.ts`
- `resources/js/tests/index.test.ts` — 3 test cases covering both branches of `isNonEmptyString`
- `resources/js/src/index.ts` — the placeholder module
- `.github/workflows/js.yml` — the required, non-advisory coverage CI check
- `.gitignore` — added `node_modules/`, `resources/js/coverage/`, `site/dist/`
- `pnpm-lock.yaml` — committed (application-shaped JS, not a published library; CI `--frozen-lockfile` depends on it)

## Decisions Made

See `key-decisions` in the frontmatter for full reasoning on: the local pnpm-install workaround (corepack's generated shim crashed on this machine, CI is unaffected), the re-verified package legitimacy, the Node-20 CI pin sourced from vitest's own installed `engines` field, and the reworded `continue-on-error`-adjacent comment.

### The failing coverage run, verbatim (Task 2 firing proof)

```
 RUN  v4.1.10 /home/mariomeyer/code/ReyemTech/packages/laravel-hubspot/resources/js
      Coverage enabled with v8


 Test Files  1 passed (1)
      Tests  3 passed (3)
   Start at  01:13:46
   Duration  781ms (transform 105ms, setup 0ms, import 155ms, tests 13ms, environment 0ms)

 % Coverage report from v8
----------|---------|----------|---------|---------|-------------------
File      | % Stmts | % Branch | % Funcs | % Lines | Uncovered Line #s
----------|---------|----------|---------|---------|-------------------
All files |   23.07 |       20 |      50 |   23.07 |
 index.ts |   23.07 |       20 |      50 |   23.07 | 34-52
----------|---------|----------|---------|---------|-------------------

=============================== Coverage summary ===============================
Statements   : 23.07% ( 3/13 )
Branches     : 20% ( 2/10 )
Functions    : 50% ( 1/2 )
Lines        : 23.07% ( 3/13 )
================================================================================
ERROR: Coverage for lines (23.07%) does not meet global threshold (95%)
ERROR: Coverage for functions (50%) does not meet global threshold (95%)
ERROR: Coverage for statements (23.07%) does not meet global threshold (95%)
ERROR: Coverage for branches (20%) does not meet global threshold (95%)
undefined
/home/mariomeyer/code/ReyemTech/packages/laravel-hubspot/resources/js:
[ERR_PNPM_RECURSIVE_EXEC_FIRST_FAIL] Command failed with exit code 1: vitest run --coverage
```

The uncovered branch (a second export, `isAllowedOrigin`) was then removed and the run
reconfirmed clean:

```
 % Coverage report from v8
----------|---------|----------|---------|---------|-------------------
File      | % Stmts | % Branch | % Funcs | % Lines | Uncovered Line #s
----------|---------|----------|---------|---------|-------------------
----------|---------|----------|---------|---------|-------------------

=============================== Coverage summary ===============================
Statements   : 100% ( 3/3 )
Branches     : 100% ( 2/2 )
Functions    : 100% ( 1/1 )
Lines        : 100% ( 3/3 )
================================================================================
```
(exit 0)

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Installed pnpm via `npm --prefix` instead of corepack**
- **Found during:** initial toolchain setup, before Task 1
- **Issue:** `corepack enable`/`corepack prepare pnpm@11.17.0 --activate` produced a `pnpm.cjs` shim that crashed immediately with `TypeError [ERR_VM_DYNAMIC_IMPORT_CALLBACK_MISSING]` on this machine's Node 22.22.1 + corepack 0.24.0. `corepack enable` also failed outright first with `EACCES` trying to symlink into `/usr/bin`.
- **Fix:** `npm install -g pnpm@11.17.0 --prefix "$HOME/.local"`, with `~/.local/bin` on `PATH`. This is a local-environment-only workaround; `.github/workflows/js.yml` uses `pnpm/action-setup@v4` in CI, which does not go through this machine's corepack installation and is unaffected.
- **Files modified:** none (system-level, not part of the repository)

**2. [Rule 1 - Bug] Reworded a CI-step comment that accidentally matched the plan's own grep check**
- **Found during:** Task 2, running the plan's `<verify>` grep check
- **Issue:** The first draft of `.github/workflows/js.yml` had a comment reading "No continue-on-error: if this is red, it is red" directly above the coverage step — prose *documenting* the absence of the escape hatch, but the literal substring `continue-on-error` inside a comment still matched `grep -q 'continue-on-error' .github/workflows/js.yml`, which the plan's own verification treats as a failure signal regardless of context.
- **Fix:** Reworded to "No advisory escape hatch on this step" — same meaning, no longer contains the literal grepped string.
- **Files modified:** `.github/workflows/js.yml`
- **Commit:** `953abbf`

### Out-of-scope discovery (logged, not fixed)

`phpunit.xml.dist`'s `<testsuites>` block lists only `Feature` and `Ci` — `tests/Arch/*` (added by plan 04) is not a declared testsuite, so a bare `vendor/bin/pest` does not execute the architecture tests (they are run separately via `scripts/ci/verify-arch-rules-fire.sh` and, presumably, a dedicated CI job in `arch.yml`). This predates this plan, is unrelated to the JS toolchain, and was not touched. Logged to `deferred-items.md` per the scope-boundary rule rather than fixed here.

---

**Total deviations:** 2 auto-fixed (1 Rule 3 local-environment blocker, 1 Rule 1 bug in a CI-step comment). No scope creep.

## Issues Encountered

None beyond the two deviations above. The npm registry re-verification, the pnpm install workaround, and the coverage firing-proof-and-revert were all completed without further blockers.

## User Setup Required

None — no external service configuration required. (The install checkpoint was pre-cleared by the owner's 2026-07-27 approval recorded in this plan's dispatch; this executor additionally re-verified all three packages against the live npm registry before installing, per the plan's "still apply normal judgement" instruction.)

## Next Phase Readiness

- `resources/js/` is a real, isolated pnpm workspace with a proven-honest 95% coverage floor, ready for Phase 8 to drop the real `postMessage`-origin-validating listener into `src/index.ts` (or a new file under `src/`) without any gate configuration changing.
- `pnpm-workspace.yaml` already declares `site`, so plan 06 (the docs-site build, wave 2) can create that directory without editing this file.
- `.github/workflows/js.yml` is a separate, independent required check from `.github/workflows/ci.yml`/`quality.yml`/`arch.yml` — it does not touch PHP tooling or the existing 20-job matrix.
- No blockers for the rest of Wave 1/2 — this plan touched only `pnpm-workspace.yaml`, `.npmrc`, `resources/js/*`, `.github/workflows/js.yml`, and `.gitignore`, disjoint from every other Phase 1 plan's files.

---
*Phase: 01-foundation-gates*
*Completed: 2026-07-27*

## Self-Check: PASSED

All 7 created/modified files confirmed present on disk; all 4 task commit hashes
(`a6f4772`, `8558385`, `ece98ae`, `953abbf`) confirmed present in `git log --oneline --all`.
