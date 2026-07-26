---
gsd_state_version: 1.0
milestone: v1.1
milestone_name: milestone
current_phase: 1
current_phase_name: Foundation & Gates
status: executing
stopped_at: Completed 01-04-PLAN.md
last_updated: "2026-07-26T20:35:30.822Z"
last_activity: 2026-07-26
last_activity_desc: roadmap **regenerated from six phases to nine** after the approval of `docs/superpowers/specs/2026-07-26-signals-attribution-and-frontend-design.md` and five `STANDARDS.md` amendments. PROJECT.md, REQUIREMENTS.md, ROADMAP.md and STATE.md rewritten. Requirements went from 24 to 44.
progress:
  total_phases: 1
  completed_phases: 0
  total_plans: 7
  completed_plans: 4
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-07-26)

**Core value:** A developer runs `composer require`, adds one trait to one model, and syncs any
HubSpot CRM object type — with no per-type code, no migration step, and no chance of writing an
association backwards. Extended 2026-07-26: …and records intent signals against an anonymous visitor
that become attributed contact properties the moment an email appears, with no API call in the
request lifecycle.
**Current focus:** Phase 1 — Foundation & Gates

## Current Position

Phase: 1 of 9 (Foundation & Gates)
Plan: 4 of 7 in current phase
Status: Ready to execute
Last activity: 2026-07-26 — roadmap **regenerated from six phases to nine** after the approval of `docs/superpowers/specs/2026-07-26-signals-attribution-and-frontend-design.md` and five `STANDARDS.md` amendments. PROJECT.md, REQUIREMENTS.md, ROADMAP.md and STATE.md rewritten. Requirements went from 24 to 44.

Progress: [████░░░░░░] 43%

## Performance Metrics

**Velocity:**

- Total plans completed: 0
- Average duration: —
- Total execution time: —

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| - | - | - | - |

**Recent Trend:**

- Last 5 plans: —
- Trend: —

*Updated after each plan completion*
**Per-Plan Metrics:**

| Plan | Duration | Tasks | Files |
|------|----------|-------|-------|
| Phase 01 P02 | 20min | 3 tasks | 10 files |
| Phase 01 P01 | 20min | 3 tasks | 14 files |
| Phase 01 P04 | 29min | 3 tasks | 23 files |

## Accumulated Context

### Decisions

49 locked decisions live in PROJECT.md `<decisions>` (D-01..D-49) — 33 extracted from `STANDARDS.md`
at ingest, one promoted on sign-off (D-34), and 15 added from the signals/attribution/frontend spec
(D-35..D-49). Highest downstream weight for current work:

- **D-35: six layers, not four.** `Signals` is a peer that may **not** depend on `Sync` or
  `Webhooks`; `Frontend` may not reference `HubSpot\*` or any internal layer. Both rules are
  architecture tests that ship in Phase 1

- **D-01: the matrix is not rectangular.** L11 = PHP 8.2-8.4, L12 = 8.2-8.5, L13 = 8.3+. Ten
  combinations, 20 jobs. Illuminate constraint `^11.0|^12.0|^13.0`, so **no Laravel 12 or 13 API
  without a shim**

- **D-02 / D-42: production `require` is seven packages now**, `illuminate/view` added with the
  `Frontend` layer. The no-third-party-runtime-dependency rule is unchanged

- **D-43: three coverage floors** — 95% PHP line, **95% JS line (Vitest)**, 80% MSI
- **D-40: buffer-first.** Roll-ups are absolute values computed from the buffer, never read back from
  HubSpot — which is what makes a flush idempotent and a queue retry unable to double-count

- **D-41: exactly four merge verbs.** `first_wins`, `last_wins`, `increment`, `sum`, plus closures.
  **There is no `overwrite`** — an earlier draft listed it separately and it is the same operation as
  `last_wins`

- **D-47: publishing is owner-gated.** Not autonomous, and impossible while the repo is private
- **D-13 / D-25:** RED commit precedes GREEN, preserved by merge commits; commitlint mandatory
- **D-12:** default suite does zero network I/O — green with no credentials, no internet
- **D-04:** no PHPStan baseline, ever
- **D-22:** `SECURITY.md` from day one — ADR precedence moves it out of the spec's final phase into
  Phase 1

- [Phase ?]: Private disclosure address set to security@reyem.tech; owner must confirm mailbox exists and is monitored
- [Phase ?]: Research assumption A3 confirmed: wagoid/commitlint-github-action@v6 needs no root package.json; commitlint.config.mjs alone is sufficient
- [Phase ?]: composer/semver added to require-dev mid-plan (used directly by tests/Ci/ComposerManifestTest.php; verified legitimate against Packagist directly)
- [Phase ?]: Coverage floor (--min=95) on empty src/ observed to fail with a clean PHPUnit runner warning rather than crash or vacuous pass; kept as-is rather than adding a placeholder file to src/ (would violate the locked 'src/ stays empty' decision) or disabling failOnPhpunitWarning (causes an uncaught crash instead)
- [Phase ?]: PSR-4 ClassLoader override (not git-worktree+symlinked-vendor) proves arch rules fire without mutating the working tree — the worktree approach was tried and crashed
- [Phase ?]: Empty-namespace pest-plugin-arch rules PASS vacuously (confirmed empirically), closing 01-RESEARCH.md assumption A2
- [Phase ?]: R6's Frontend-may-depend-only-on-facade rule targets ReyemTech\Hubspot\Facades\Hubspot, a class Phase 2 introduces; R10's secret config keys (hubspot.token, hubspot.webhooks.secret) are provisional until config/hubspot.php ships

### Pending Todos

None yet.

### Blockers/Concerns

**Blocked work — do not plan as executable.** Details and unblock conditions in ROADMAP.md →
*Blocked & Owner-Gated Work*.

- **FOUND-03, the §6.4 association-inverse probe (Phase 1).** Needs a HubSpot developer account token
  the executing agent does not hold. **Phase 1 can do everything else.** The answer is not derivable
  by reasoning — HubSpot's docs do not state it. `associate(..., verify: true)` and
  `hubspot:associations:doctor` ship regardless; the probe sets a default, it does not block the design

- **Branch protection configuration (Phase 1).** Owner action; may be limited by plan on a private
  repository. Defining the required checks is executable; switching protection on is not

- **REL-02, Packagist and the first public release (Phase 9).** Owner-gated by decision **and**
  impossible while `ReyemTech/laravel-hubspot` is private — Packagist requires a public repository.
  REL-01 was shrunk accordingly and now covers only `composer validate --strict` as a required check
  plus release-please configuration

- **SHIP-04's GitHub Pages deploy (Phase 9).** Pages needs a paid plan on private repositories. The
  site may build in CI and publish the `docs-pages` branch; the deploy waits

**Open questions and discipline notes.**

- **One unsigned decision remains: #5, `final` by default** (working default: `final`). It shapes
  Phase 2 onward, **not Phase 1**. Six of the seven are now signed and locked. `BRIEF.md` still says
  "Ask Mario rather than assuming" — confirm before or during Phase 2

- **RES-01 (Phase 7) is research, not recall.** The four §8.1 questions — custom-object tier gates,
  behavioural-event tier gates, current rate limits, Timeline API credentials — are answered against
  **live HubSpot documentation**, with source URLs and dates recorded. Answering from model recall is
  a defect, not a shortcut. This is not blocked work; it is executable research

- **REG-01 and HOOK-02 still have no acceptance criteria in any source document.** The 2026-07-26 spec
  review did not change that. Derive during `/gsd-plan-phase` for Phases 3 and 5; do not invent them
  at roadmap level

- **`.planning/intel/` is partially stale.** Extracted before the signals spec existed. Reliable for
  Phase 2-5 content; wrong on layer count (four vs six), dependency count (six vs seven), support
  matrix (11/12 vs 11/12/13), the required-check list, the docs-site rejection, and publishing. The
  signals spec and the amended `STANDARDS.md` override it everywhere they disagree

- **Agent hazard:** an agent reading `apps/laravel`'s CLAUDE.md will try to convert this suite from
  Pest to PHPUnit. That rule is app-scoped. Pest is locked (D-08)

- **Phase numbering shifted.** The core spec §13, the signals spec §15 prose, `BRIEF.md` and
  `.planning/intel/` say "Phase 0"; this roadmap says "Phase 1". GSD's `roadmap.analyze` silently
  drops a `### Phase 0:` header (verified 2026-07-26). Mapping table at the top of ROADMAP.md

## Deferred Items

| Category | Item | Status | Deferred At |
|----------|------|--------|-------------|
| Compatibility | Delete `Compat\Tapp` (deprecated from day one) | v2 | 2026-07-26 |
| Scope | Marketing / CMS / Conversations APIs (Meetings embed is a recorded exception, not a precedent) | v2 | 2026-07-26 |
| Process | Commit signing | v2 | 2026-07-26 |
| ~~Docs~~ | ~~A `docs/` site~~ → **promoted to v1 as SHIP-04, Phase 9** | v1 | promoted 2026-07-26 |

## Session Continuity

Last session: 2026-07-26T20:35:04.519Z
Stopped at: Completed 01-04-PLAN.md
Resume file: None
