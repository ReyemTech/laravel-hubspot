---
gsd_state_version: 1.0
milestone: v1.1
milestone_name: milestone
current_phase: 2
current_phase_name: Gateway Layer
status: executing
stopped_at: Completed 02-05-PLAN.md
last_updated: "2026-07-27T21:32:06.614Z"
last_activity: 2026-07-27
last_activity_desc: "executed 02-04-PLAN.md (the directed pair and the unlabelled association path): `ObjectRef`, `AssociationPair(from, to)` with its names and order pinned by reflection, the unlabelled `createDefault()` write that sends no body and therefore no type id, dissociate, and a read that emits one row per reported association type. GW-02 is **half** delivered; 02-05 owns the labelled write and the never-the-inverse throw. GW-04 remains **partially** delivered until 02-06."
progress:
  total_phases: 2
  completed_phases: 1
  total_plans: 13
  completed_plans: 12
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-07-26)

**Core value:** A developer runs `composer require`, adds one trait to one model, and syncs any
HubSpot CRM object type — with no per-type code, no migration step, and no chance of writing an
association backwards. Extended 2026-07-26: …and records intent signals against an anonymous visitor
that become attributed contact properties the moment an email appears, with no API call in the
request lifecycle.
**Current focus:** Phase 2 — Gateway Layer

## Current Position

Phase: 2 of 9 (Gateway Layer)
Plan: 6 of 6 in current phase
Status: Plans 02-01, 02-02 and 02-03 merged (PRs #8, #14, #15). Plan 02-04 (the directed pair and the unlabelled association path) complete on branch `feat/02-04-directed-pair-unlabelled-associations` — PR open, awaiting GitHub checks. 2 plans remain in Phase 2 (02-05, 02-06).
Last activity: 2026-07-27 — executed 02-04-PLAN.md (the directed pair and the unlabelled association path): `ObjectRef`, `AssociationPair(from, to)` with its names and order pinned by reflection, the unlabelled `createDefault()` write that sends no body and therefore no type id, dissociate, and a read that emits one row per reported association type. GW-02 is **half** delivered; 02-05 owns the labelled write and the never-the-inverse throw. GW-04 remains **partially** delivered until 02-06.

Progress: [█████████░] 92%

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
| Phase 01 P05 | 40min | 3 tasks | 9 files |
| Phase 01 P03 | 20min | 2 tasks | 7 files |
| Phase 01-foundation-gates P06 | ~35min | 2 tasks | 11 files |
| Phase 01 P07 | 30min | 4 tasks | 12 files |
| Phase 02 P01 | 45min | 3 tasks | 18 files |
| Phase 02 P02 | 22min | 3 tasks | 13 files |
| Phase 02 P04 | ~20min | 2 tasks | 19 files |
| Phase 02 P05 | ~75min | 3 tasks | 19 files |

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
- [Phase ?]: PHPStan level resolved to max (=10 under phpstan/phpstan 2.2.5), confirmed empirically via the installed phar's config.level*.neon files rather than trusted from STANDARDS' stale 'level 9' text
- [Phase ?]: tests/Arch/Fixtures/* excluded from phpstan.neon and phpcs.xml: plan 04's deliberately rule-violating architecture fixtures are never production code and are outside this plan's ownership
- [Phase ?]: Review targets (300/40/5) left as a PR-description convention, not encoded as PHPCS warnings: neither sniff used exposes a second, lower warning-only threshold
- [Phase ?]: pnpm installed locally via npm --prefix (corepack shim crashed on this machine's Node 22.22.1 + corepack 0.24.0); CI uses pnpm/action-setup@v4 and is unaffected
- [Phase ?]: JS coverage floor (95%, Vitest) scoped to resources/js/ workspace only, proven to reject a build at 23.07% coverage before being wired as a required CI check (js.yml)
- [Phase ?]: CI Node major pinned to 20, read from installed vitest 4.1.10's own engines field (^20.0.0 || ^22.0.0 || >=24.0.0), not copied from the stint reference
- [Phase ?]: pnpm-workspace.yaml gained allowBuilds: esbuild: true (pnpm 11's only mechanism to approve astro's transitive esbuild postinstall) — packages: list untouched
- [Phase ?]: js.yml install re-scoped to --filter './resources/js...' since astro's engines.node >=22.12.0 would otherwise break that job's Node 20 pin once site/ became real
- [Phase ?]: docs.yml pinned to Node 22 (astro's own engines floor), decoupled from js.yml's Node 20 via filtered installs on both sides
- [Phase ?]: release-please configured (release-type: simple, version.txt, no publish); confirmed A4
- [Phase ?]: roave/backward-compatibility-check installed per-CI-job only (not composer.json), 8.21.0, PHP 8.4, requires --with-all-dependencies
- [Phase ?]: Required-checks list (17 jobs) machine-checked bidirectionally against docs/repo/owner-gated-checklist.md
- [Phase ?]: MockHandler callable-per-request routing (self-appending queue entry, inspecting the request's own object-type path) correctly serves per-object-type canned responses regardless of call order — retires 02-RESEARCH.md's one unverified finding
- [Phase ?]: ObjectGatewayContract bound non-shared (transient), not singleton — lets Hubspot::fake() swap the HubspotClientFactory instance without needing Container::forgetInstance(), which isn't on the Illuminate Container contract this package is typed against
- [Phase ?]: AssociationTypeException extends RuntimeException (registry resolution failure, ApiException's own family); ConfigurationException/ObjectTypeException extend LogicException/InvalidArgumentException (caller mistake detectable before any I/O)
- [Phase ?]: ExceptionTranslator::recognisedSdkApiExceptions() is public static so the arch coverage guard reads the real list, not a hand-copied duplicate; the guard is one-directional (referenced-by-Gateway implies recognised, not the reverse)
- [Phase ?]: Retry-middleware presence on the production Guzzle handler stack is asserted via HandlerStack::__toString() with explicitly-named pushes, not a mock 429-then-200 sequence, since RetryMiddlewareFactory's decider/delay functions are already-tested SDK code
- [Phase ?]: AssociationPair(from, to) rejects a self-pair and ObjectRef rejects a blank/whitespace-only object type or id — all three raise ObjectTypeException, the hierarchy's pre-I/O caller-mistake member, because STANDARDS §9 forbids a fifth member and AssociationTypeException documents itself as a runtime registry-lookup failure
- [Phase ?]: AssociationPair::reversed() added in 02-04 (not deferred) because 02-05's bidirectional option needs the reversed pair as a value; it returns a new pair and carries no type id — reversal is not a claim about the inverse id
- [Phase ?]: An association read emits one AssociationRow per reported association TYPE, not per related record — FOUND-03 observed one record carrying both a USER_DEFINED label and the HUBSPOT_DEFINED default in a non-guaranteed order, so "the first type" would pass regardless of which id was written
- [Phase ?]: ExceptionTranslator gained a shared static unexpectedResponseShape(); ObjectGateway's private helper delegates to it, so the message has one implementation across both gateways (STANDARDS §6b)
- [Phase ?]: config/hubspot.php transport defaults: 10s timeout, 5s connect timeout, retries enabled -- honest for a queued job, unbounded default explicitly rejected in the inline comment
- [Phase ?]: The association category is a backed enum, not a validated string: an enum case makes the invalid value unrepresentable past construction, so no consumer downstream re-checks or trusts a string
- [Phase ?]: Four association categories, not three — the enum's case set is asserted equal to the pinned SDK major's own allow-list at runtime, so narrower cannot reject a valid category and wider cannot leak a raw SDK exception
- [Phase ?]: The labelled write is its own pair of methods rather than associate($pair, ?string $label = null): a nullable label would make the HTTP route and whether a type id is resolved at all depend on a parameter default
- [Phase ?]: bidirectional ships as a plain non-nullable bool defaulting to false — FOUND-03's measured answer, pinned by reflection so reverting to ?bool cannot happen quietly
- [Phase ?]: Bidirectional writes resolve every direction before issuing any request, so an unresolvable reverse direction writes nothing and a caller's retry is safe

### Pending Todos

None yet.

### Blockers/Concerns

**Blocked work — do not plan as executable.** Details and unblock conditions in ROADMAP.md →
*Blocked & Owner-Gated Work*.

- ~~**FOUND-03, the §6.4 association-inverse probe (Phase 1).**~~ **UNBLOCKED AND ANSWERED
  2026-07-27** — owner supplied a developer test account Service Key and the probe was run. The
  inverse IS automatic and carries its own distinct typeId (`3 → 4` unlabelled, `1 → 2` labelled),
  so `associate()` is one write and `inverse_type_id` stays read/verification-only. Full results in
  `docs/probes/association-inverse-probe.md`. Note for Phase 3+: an association **read** returns a
  *list* of `associationTypes` in no guaranteed order, so the read-response parsers
  (`associate(..., verify: true)`, `hubspot:associations:doctor`) must search it rather than take
  the first entry. This does NOT apply to `assertAssociated()`, which parses the outgoing request

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

- Mutation required check (quality.yml) will report red for the remainder of Phase 1: pest --mutate --min=80 over the deliberately-empty src/ triggers PHPUnit's failOnPhpunitWarning path (WARN + exit 1, no score computed), mirroring plan 01's already-flagged coverage-floor gap. Resolves automatically once Phase 2 adds the first mutable file under src/.
- Git-history attribution defect: commit 022b9e6 (plan 05) accidentally includes three of plan 04's task-2 files (tests/Arch/LayerBoundariesTest.php, SecretLoggingTest.php, StrictTypesTest.php) due to concurrent git staging in a shared (non-worktree) working directory. Content is correct and both plans are green; plan 04 lacks a dedicated GREEN commit for its rule implementations in git log. See 01-05-SUMMARY.md Issues Encountered.
- Phase 3 blocker found during 02-05: architecture rules R2-R5 forbid a non-Gateway layer from naming ReyemTech\Hubspot\Exceptions, which Phase 3's registry must do to throw AssociationTypeException. Reproduced with a throwaway fixture; logged in 02-gateway-layer/deferred-items.md. Fix is one allow-list entry per rule plus a violation fixture each.

## Deferred Items

| Category | Item | Status | Deferred At |
|----------|------|--------|-------------|
| Compatibility | Delete `Compat\Tapp` (deprecated from day one) | v2 | 2026-07-26 |
| Scope | Marketing / CMS / Conversations APIs (Meetings embed is a recorded exception, not a precedent) | v2 | 2026-07-26 |
| Process | Commit signing | v2 | 2026-07-26 |
| ~~Docs~~ | ~~A `docs/` site~~ → **promoted to v1 as SHIP-04, Phase 9** | v1 | promoted 2026-07-26 |

## Session Continuity

Last session: 2026-07-27T21:31:51.128Z
Stopped at: Completed 02-05-PLAN.md
Resume file: None
