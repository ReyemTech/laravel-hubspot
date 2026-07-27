---
phase: 01-foundation-gates
plan: 06
subsystem: infra
tags: [astro, starlight, pnpm, github-actions, docs-site, ci]

# Dependency graph
requires:
  - phase: 01-foundation-gates
    provides: "pnpm-workspace.yaml declaring site (plan 03, inert until this plan); resources/js/ and .github/workflows/js.yml as the sibling Node workspace/CI job this plan had to stay disjoint from"
provides:
  - "site/ — a real Astro 7.1.3 + Starlight 0.41.4 site (package name hubspot-docs), one genuine landing page at src/content/docs/index.mdx, building green with no network access after install"
  - ".github/workflows/docs.yml — a named required check that builds site/ in isolation (filtered install/build) and asserts site/dist/index.html exists, closing the vacuous-pass trap (pnpm --filter against a nonexistent package exits 0, not nonzero)"
  - "docs/repo/docs-site-deploy.md — the Phase 9 SHIP-04 deploy procedure: PAT-vs-GITHUB_TOKEN rationale, docs-pages branch preserve-list mechanism, two-workflow split, both owner-gated blockers named"
  - "pnpm-workspace.yaml's allowBuilds: esbuild: true — the one addition required to install astro's transitive esbuild dependency under pnpm 11's default build-script gate, added without touching the packages: list plan 03 owns"
  - "js.yml's install step re-scoped to --filter './resources/js...' so astro's higher Node engine floor (>=22.12.0) can never break that job's Node 20 pin"
affects: [phase-9-ship, ci-required-checks]

# Tech tracking
tech-stack:
  added:
    - "astro 7.1.3 (exact-pinned, matches 01-RESEARCH.md's live-verified version, repository github.com/withastro/astro, 4.25M/week downloads)"
    - "@astrojs/starlight 0.41.4 (exact-pinned, github.com/withastro/starlight, 685K/week downloads)"
    - "typescript 7.0.2 (site/'s own devDependency; no peer constraint from astro/starlight pins it, verified current via npm view)"
  patterns:
    - "Same tracer-then-firing-proof shape as plans 03/04/05: RED commit (docs.yml asserting an artifact that cannot exist yet) precedes the GREEN commit (the site itself)"
    - "Two independent pnpm workspaces, two independent filtered installs (--filter './site...' vs --filter './resources/js...'), so neither workspace's dependency floor can ever break the other's CI job — this is the same isolation principle plan 03 established for the coverage floor, now also protecting the docs build from the JS coverage job and vice versa"

key-files:
  created:
    - site/package.json
    - site/astro.config.mjs
    - site/tsconfig.json
    - site/src/content.config.ts
    - site/src/content/docs/index.mdx
    - .github/workflows/docs.yml
    - docs/repo/docs-site-deploy.md
  modified:
    - pnpm-workspace.yaml
    - pnpm-lock.yaml
    - .gitignore
    - .github/workflows/js.yml

key-decisions:
  - "pnpm-workspace.yaml required one unavoidable addition — `allowBuilds: esbuild: true` — because pnpm 11's default build-script gate (ERR_PNPM_IGNORED_BUILDS) blocks astro's transitive esbuild dependency's postinstall (the standard platform-binary-download script every esbuild consumer runs) unless explicitly approved, and `allowBuilds` exists only at the pnpm-workspace.yaml level in pnpm v11 (confirmed against pnpm.io/settings — the pre-v11 onlyBuiltDependencies/ignoredBuiltDependencies keys were removed in favor of this single map). Approved esbuild specifically, not `dangerouslyAllowAllBuilds`; the `packages:` list plan 03 owns is untouched, confirmed via `git diff` before staging."
  - "js.yml's install step re-scoped to `--filter './resources/js...'` (was unfiltered `pnpm install --frozen-lockfile`). Astro 7.1.3 declares `engines.node: >=22.12.0` (verified via `npm view astro engines`); with `.npmrc`'s `engine-strict=true`, an unfiltered install on js.yml's Node 20 runner would now try to install astro too the moment site/ became a real workspace member, and fail outright. Filtering the install is the same isolation principle plan 03 already used to scope the coverage measurement itself — extending it to the install step keeps the two workspaces' CI jobs from ever coupling on Node major or dependency floor. This file is outside this plan's declared files_modified but the fix is a direct, unavoidable consequence of this plan's own change to the workspace; documented here rather than silently worked around."
  - "docs.yml pinned to Node 22 (not js.yml's Node 20), for the same engines-field reason above — sourced from `npm view astro engines`, not copied from the stint reference (which predates this Astro major and used Node 20 successfully for an older one)."
  - "astro.config.mjs's `site`/`base` set to `https://reyemtech.github.io` / `/laravel-hubspot` — the default GitHub Pages project-page URL, since no CNAME/custom domain is configured for this repository (unlike stint's `stint.reyem.tech`). Recorded in docs-site-deploy.md so Phase 9's preserve-list omits CNAME deliberately rather than by oversight."
  - "index.mdx deliberately has no network-dependent build step (unlike stint's index.mdx, which calls `getLatestRelease()` against the GitHub API at build time). Confirmed offline-safe by inspecting the built HTML for external URLs: only the authored GitHub links and the site's own canonical URL appear — no remote font, no OG-image service, no fetch."
  - "site/src/content.config.ts added even though not in the plan's files_modified list — it is Starlight's required content-collection loader wiring (defineCollection + docsLoader + docsSchema), without which the build fails regardless of content. Treated as necessary scaffolding implied by 'a Starlight site that builds green,' not scope creep."

patterns-established:
  - "Any future site/ dependency addition should be checked against its own `engines` field before assuming it can share a Node major with resources/js/ — the two workspaces are intentionally decoupled on install, and a future dependency bump could reopen the same gap this plan just closed."

requirements-completed: [FOUND-04]

coverage:
  - id: D1
    description: "pnpm install --frozen-lockfile && pnpm --filter './site' build succeeds from a clean clone with no network access after install"
    requirement: FOUND-04
    verification:
      - kind: integration
        ref: "pnpm install --frozen-lockfile --filter './site...' && pnpm --filter './site' build, run twice against a fully-cleared node_modules/dist (simulating a clean clone): exit 0 both times, 2 pages built, no external URLs beyond authored GitHub links found in the built HTML"
        status: pass
    human_judgment: false
  - id: D2
    description: "The documentation site build is a named required check, green on a site with no content beyond a landing page, and genuinely fails (not vacuously passes) before the site exists"
    requirement: FOUND-04
    verification:
      - kind: integration
        ref: ".github/workflows/docs.yml: RED confirmed before site/ existed (filtered install/build no-op at exit 0, but `test -f site/dist/index.html` exits 1); GREEN confirmed after site/ was created (same workflow steps, artifact check exits 0)"
        status: pass
    human_judgment: false
  - id: D3
    description: "The docs workspace is excluded from the JavaScript coverage gate — resources/js/'s 95% floor cannot be diluted by site/ content, and site/'s higher Node engine floor cannot break resources/js/'s CI job"
    requirement: FOUND-04
    verification:
      - kind: integration
        ref: "js.yml's install re-scoped to --filter './resources/js...'; re-ran pnpm install --frozen-lockfile --filter './resources/js...' && pnpm --filter './resources/js' exec vitest run --coverage after site/ existed: 100% on all four thresholds, exit 0, unaffected by site/'s astro dependency"
        status: pass
    human_judgment: false
  - id: D4
    description: "The GitHub Pages deploy is recorded as a ready-to-run procedure for SHIP-04 in Phase 9, not shipped as an inert workflow"
    requirement: FOUND-04
    verification:
      - kind: unit
        ref: "test -s docs/repo/docs-site-deploy.md && grep -qi 'RELEASE_TOKEN' docs/repo/docs-site-deploy.md && grep -qi 'docs-pages' docs/repo/docs-site-deploy.md; test ! -f .github/workflows/deploy-pages.yml — both pass"
        status: pass
    human_judgment: false
  - id: D5
    description: "Every npm package installed here was human-verified against its registry page before install, because 01-RESEARCH.md's legitimacy gate returned SUS for astro and @astrojs/starlight (too-new heuristic, false positive)"
    requirement: FOUND-04
    verification:
      - kind: manual_procedural
        ref: "npm view astro/@astrojs/starlight version + repository.url + scripts.postinstall, cross-checked against api.npmjs.org download counts — astro 7.1.3 (github.com/withastro/astro, 4.25M/week, no postinstall), @astrojs/starlight 0.41.4 (github.com/withastro/starlight, 685K/week, no postinstall); both exactly match 01-RESEARCH.md's expected names/repos. Owner pre-cleared this checkpoint on 2026-07-27 per the plan's own dispatch note; re-verified anyway before installing."
        status: pass
    human_judgment: true
    rationale: "Package-legitimacy verification for a SUS verdict is a human-facing checkpoint by protocol; the owner's pre-clearance is recorded in the orchestrator's prompt to this executor, not re-derivable purely from CI output."

duration: ~35min
completed: 2026-07-27
status: complete
---

# Phase 1 Plan 06: Astro + Starlight Docs Site Summary

**A real Astro 7.1.3 + Starlight 0.41.4 site in `site/` building green in CI via a filtered, isolated pnpm workflow, plus the Phase 9 deploy procedure recorded from the `stint` reference while it was cheap to read.**

## Performance

- **Duration:** ~35 min
- **Completed:** 2026-07-27
- **Tasks:** 2 (checkpoint pre-cleared by owner 2026-07-27, per the plan's own dispatch note; re-verified anyway before installing)
- **Files created:** 7
- **Files modified:** 4 (`pnpm-workspace.yaml`, `pnpm-lock.yaml`, `.gitignore`, `.github/workflows/js.yml`)

## Accomplishments

- **Checkpoint verification, done anyway despite the pre-clearance.** Re-ran the registry checks the checkpoint asked for: `astro`@7.1.3 and `@astrojs/starlight`@0.41.4 both resolve to their official `withastro` GitHub repositories (4.25M / 685K weekly downloads, no `postinstall` script). Both exactly match 01-RESEARCH.md, no transposed or hyphenated near-name. Proceeded with the install as approved.
- **`site/`**, a real Starlight site (package name `hubspot-docs`) with `astro.config.mjs`, `tsconfig.json`, the required `content.config.ts` collection loader, and one genuine landing page at `src/content/docs/index.mdx` describing the package from `BRIEF.md` — not a placeholder, since Starlight fails a build with zero content-collection entries.
- **`.github/workflows/docs.yml`**, a named required check that installs and builds `site/` in isolation (`--filter './site...'`), then asserts `site/dist/index.html` exists. That last assertion matters: `pnpm --filter` against a directory with no `package.json` exits **0**, not nonzero — a vacuous pass, exactly the trap this phase keeps guarding against — so the artifact check, not the build command's own exit code, is what proves the RED state and what proves the GREEN state.
- **The RED-before-GREEN sequence, confirmed, not assumed.** `docs.yml` was written and committed while `site/` did not yet exist; ran the workflow's own steps locally and confirmed the filtered install/build no-op at exit 0 while the artifact check exits 1. Only then was the site scaffolded, and the same steps re-run to confirm the artifact check flips to exit 0.
- **A real, unavoidable cross-workflow fix.** Astro 7.1.3 declares `engines.node: >=22.12.0`; with `.npmrc`'s `engine-strict=true`, `js.yml`'s previously-unfiltered `pnpm install --frozen-lockfile` (Node 20) would now try to install `astro` too and fail outright the moment `site/` became a real workspace member. Re-scoped `js.yml`'s install to `--filter './resources/js...'`, mirroring `docs.yml`'s own site-only filtered install in the opposite direction. Confirmed both filtered installs and their downstream commands (`vitest run --coverage`, `astro build`) run green independently, from a fully-cleared `node_modules/`/`dist/` state, simulating a clean clone.
- **One unavoidable `pnpm-workspace.yaml` addition**, disclosed rather than worked around: pnpm 11's default build-script gate blocks `astro`'s transitive `esbuild` dependency's postinstall unless approved, and `allowBuilds` (the only mechanism pnpm v11 offers for this) lives exclusively in `pnpm-workspace.yaml`. Added `allowBuilds: esbuild: true` — nothing else in the file changed; the `packages:` list plan 03 owns is untouched, confirmed via `git diff` before every commit that touched this file.
- **`docs/repo/docs-site-deploy.md`**, written directly from `apps/stint`'s `deploy-docs.yml`, `deploy-pages.yml` and `publish-docs.sh` while they were being read for this plan. Records the PAT-vs-`GITHUB_TOKEN` rationale and its silent-failure mode, the `docs-pages` branch preserve-list mechanism (and why this package's list is shorter than stint's — no `install.sh`/`CNAME`), the two-workflow split with concurrency groups, and both owner-gated blockers (paid Pages plan, `RELEASE_TOKEN` secret) with what unblocks each. No `deploy-docs.yml`, `deploy-pages.yml` or `publish-docs.sh` created — confirmed absent.

## Task Commits

1. **Checkpoint (blocking-human, pre-cleared by owner 2026-07-27):** verify `astro` and `@astrojs/starlight` before install — re-verified anyway, no action needed beyond the re-check documented above.
2. **Task 1, RED:** `27d3f06` (test) — `.github/workflows/docs.yml`; confirmed RED: filtered install/build no-op at exit 0, `test -f site/dist/index.html` exits 1
3. **Task 1, GREEN:** `5d768a0` (feat) — `site/package.json`, `site/astro.config.mjs`, `site/tsconfig.json`, `site/src/content.config.ts`, `site/src/content/docs/index.mdx`, `pnpm-lock.yaml`, `pnpm-workspace.yaml` (`allowBuilds` addition), `.gitignore`; confirmed GREEN: build exit 0, `site/dist/index.html` exists
4. **Task 1, fix (unavoidable consequence):** `9afad1f` (fix) — `.github/workflows/js.yml`, re-scoped install to `--filter './resources/js...'`
5. **Task 2:** `28cd944` (docs) — `docs/repo/docs-site-deploy.md`

**Plan metadata:** committed separately as the final commit of this plan (see STATE.md/ROADMAP.md commit below).

## Files Created/Modified

- `site/package.json` — `hubspot-docs` workspace, `astro`/`@astrojs/starlight` pinned exact at `7.1.3`/`0.41.4`, `dev`/`build`/`preview`/`astro` scripts
- `site/astro.config.mjs` — Starlight integration, `site`/`base` set to the GitHub Pages project-page URL, minimal sidebar
- `site/tsconfig.json` — `astro/tsconfigs/strict`
- `site/src/content.config.ts` — Starlight's required `docs` collection loader wiring
- `site/src/content/docs/index.mdx` — the real landing page, package description from `BRIEF.md`
- `.github/workflows/docs.yml` — the isolated, filtered, artifact-asserting required CI check
- `docs/repo/docs-site-deploy.md` — the Phase 9 SHIP-04 procedure
- `pnpm-workspace.yaml` — `allowBuilds: esbuild: true` added; `packages:` list unchanged
- `pnpm-lock.yaml` — updated to include `site`'s dependency tree
- `.gitignore` — added `site/.astro/` (Astro's generated type-definition cache)
- `.github/workflows/js.yml` — install step re-scoped to `--filter './resources/js...'`

## Decisions Made

See `key-decisions` in the frontmatter for full reasoning on: the `allowBuilds` addition and why it's unavoidable at the pnpm-workspace.yaml level, the `js.yml` filter fix and why it's a necessary consequence rather than scope creep, the Node-22 pin for `docs.yml` sourced from astro's own `engines` field, the `site`/`base` GitHub Pages URL choice, the deliberate absence of any network-dependent build step, and `content.config.ts` as necessary Starlight wiring.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] `pnpm-workspace.yaml` required one addition despite the plan's "do not modify" instruction**
- **Found during:** Task 1, first `pnpm install --filter './site...'` attempt
- **Issue:** pnpm 11's default build-script gate (`ERR_PNPM_IGNORED_BUILDS`) blocked `astro`'s transitive `esbuild` dependency's postinstall script (`node install.js`, the standard platform-binary-download script every `esbuild` consumer runs). `allowBuilds` — the only mechanism pnpm v11 provides to approve this — is a `pnpm-workspace.yaml`-only setting (confirmed against `pnpm.io/settings`: the pre-v11 `onlyBuiltDependencies`/`ignoredBuiltDependencies` keys were removed in v11 in favor of this single map). Tested and confirmed no `.npmrc`-level or CLI-flag alternative exists.
- **Fix:** `pnpm approve-builds esbuild` (scoped to exactly `esbuild`, not `pnpm approve-builds --all` or `dangerouslyAllowAllBuilds`), which added exactly one line — `allowBuilds: esbuild: true` — to `pnpm-workspace.yaml`. The `packages:` list plan 03 owns is untouched; verified via `git diff pnpm-workspace.yaml` before every commit touching this file.
- **Files modified:** `pnpm-workspace.yaml`
- **Commit:** `5d768a0`

**2. [Rule 1 - Bug] `js.yml`'s unfiltered install would break once `site/` became a real workspace member**
- **Found during:** Task 1, after confirming `site/`'s build works standalone
- **Issue:** `astro@7.1.3` declares `engines.node: >=22.12.0` (verified via `npm view astro engines`). With `.npmrc`'s `engine-strict=true`, `js.yml`'s existing unfiltered `pnpm install --frozen-lockfile` step (pinned to Node 20 by plan 03) installs every workspace member — including `site/` once it has a real `package.json`. That install would now fail outright on Node 20, breaking a previously-green required check as a direct side effect of this plan's own change.
- **Fix:** Re-scoped `js.yml`'s install step to `--filter './resources/js...'`, mirroring `docs.yml`'s own site-only filtered install in the opposite direction. Confirmed both jobs' install-then-run sequences pass independently from a fully-cleared `node_modules/` state.
- **Files modified:** `.github/workflows/js.yml`
- **Commit:** `9afad1f`

**3. [Rule 2 - Missing Critical] Added `site/src/content.config.ts`, not listed in the plan's files_modified**
- **Found during:** Task 1, first build attempt
- **Issue:** Starlight requires a content-collection loader (`defineCollection` + `docsLoader` + `docsSchema`) to resolve `src/content/docs/*`; without it the build fails regardless of content, independent of the "ship a real landing page, not a placeholder" requirement the plan does state.
- **Fix:** Added the file, sourced verbatim from the `stint` reference implementation (framework wiring, not project-specific content — verbatim reuse is correct here).
- **Files modified:** `site/src/content.config.ts`
- **Commit:** `5d768a0`

### Out-of-scope discovery (logged, not fixed)

None new. `phpunit.xml.dist`'s `<testsuites>` gap (logged by plan 03) remains untouched and unrelated to this plan's files.

---

**Total deviations:** 3 auto-fixed (1 Rule 3 blocking install-gate, 1 Rule 1 bug in a sibling workflow, 1 Rule 2 missing-critical framework file). No scope creep — all three are files/config directly necessitated by making `site/` a genuine, buildable workspace member, and all are documented rather than silently worked around.

## Issues Encountered

None beyond the three deviations above, all resolved during Task 1. Task 2 (the deploy procedure doc) had no issues — all three `stint` reference files were read directly and their content transcribed with rationale.

## User Setup Required

None in this phase. `docs/repo/docs-site-deploy.md` records two owner-gated blockers for **Phase 9** (GitHub Pages needs a paid plan on this private repository; `RELEASE_TOKEN` must be created and added as a repository secret) — neither is actionable now, both are recorded with what unblocks each.

## Next Phase Readiness

- `site/` builds green, isolated from `resources/js/`'s coverage floor in both directions (neither workspace's dependency floor, Node major, or CI job can affect the other).
- `docs/repo/docs-site-deploy.md` gives Phase 9's SHIP-04 a wiring job, not a rediscovery: the PAT rationale, preserve-list mechanism, two-workflow split, and both blockers are all recorded with sources.
- `pnpm-workspace.yaml`'s `allowBuilds` addition and `js.yml`'s filter fix are both disclosed, minimal, and shouldn't need revisiting unless a future dependency introduces another engine-floor or build-script gap.
- No blockers for the rest of Phase 1 — this plan touched `site/*`, `docs/repo/docs-site-deploy.md`, `.github/workflows/docs.yml`, `pnpm-workspace.yaml`, `pnpm-lock.yaml`, `.gitignore`, and (as a necessary fix) `.github/workflows/js.yml`, disjoint from plan 01/02/04/05's files.

---
*Phase: 01-foundation-gates*
*Completed: 2026-07-27*

## Self-Check: PASSED

All 7 created/modified files confirmed present on disk; all 4 task commit hashes
(`27d3f06`, `5d768a0`, `9afad1f`, `28cd944`) confirmed present in `git log --oneline --all`.
