---
gsd_state_version: 1.0
milestone: v1.1
milestone_name: milestone
current_phase: 3
current_phase_name: Registry & Stores
status: complete
stopped_at: "Phase 3 plans all executed; 03-03 is on a pushed PR awaiting merge by the orchestrator. Next action after merge is planning Phase 4 (Model Sync)"
last_updated: "2026-07-29T00:00:00.000Z"
last_activity: 2026-07-29
last_activity_desc: "executed 03-03-PLAN.md (definitions read, sync and the two doctors): Gateway\AssociationDefinitionsGateway wraps the Schema-namespaced DefinitionsApi (the phase's only new HubSpot\* reference), hubspot:associations:sync reconciles both directions of each configured pair and leaves inverse_type_id null because the two responses share no join key, hubspot:doctor reports stores/resolver/reconciliation state and NAMES the absent bound-model section, hubspot:associations:doctor searches every reported association type for the expected directional id and records a pairing only when both directions were observed. 625 tests, 100.0% coverage. REG-02 and REG-03 tick; REG-01 and REG-04 stay OPEN with only their Phase 3 halves done."
progress:
  total_phases: 3
  completed_phases: 3
  total_plans: 16
  completed_plans: 16
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-07-26)

**Core value:** A developer runs `composer require`, adds one trait to one model, and syncs any
HubSpot CRM object type — with no per-type code, no migration step, and no chance of writing an
association backwards. Extended 2026-07-26: …and records intent signals against an anonymous visitor
that become attributed contact properties the moment an email appears, with no API call in the
request lifecycle.
**Current focus:** Phase 3 — Registry & Stores

## Current Position

Phase: 3 of 9 (Registry & Stores) — **all three plans executed**
Plan: 3 of 3 in current phase; 03-01 and 03-02 merged (PRs #24, #27), 03-03 on a pushed PR awaiting the orchestrator's merge
Status: **Phase 3's work is done, and two of its four requirements deliberately do not close.** 03-01 shipped object-type normalisation, the seeded HubSpot-defined baseline and the store seam (array + cache) with `AssociationTypeRegistry` bound on the Phase 2 resolver key; 03-02 the database store, its dated migration and the directed missing-table error, with the unique key corrected to a collation-proof `lookup_hash`; 03-03 the Gateway-owned association-definitions read, `hubspot:associations:sync`, `hubspot:doctor` and `hubspot:associations:doctor`. **REG-02 and REG-03 tick. REG-01 and REG-04 stay OPEN** — their local-id-resolution and bound-model halves need model binding (SYNC-01) and are Phase 4's as REG-01b and REG-04b. `hubspot:doctor` NAMES its absent model section rather than omitting it, and a test holds that, precisely so nobody reads the command's existence as the requirement being met.
Last activity: 2026-07-29 — executed 03-03-PLAN.md. `Gateway\AssociationDefinitionsGateway` wraps `Schema\Api\DefinitionsApi` (the phase's only new `HubSpot\*` reference, and a third namespace for `ExceptionTranslator`); the sync reads both directions of each configured pair and leaves `inverse_type_id` null; the associations doctor searches every reported association type rather than taking the first, and records a pairing only when both directions were observed. 625 tests, 100.0% coverage.

Progress: [██████████] 100%

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
| Phase 02 P06 | ~95min | 3 tasks | 10 files |
| Phase 03 P03 | ~110min | 3 tasks | 20 files |

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
- [Phase ?]: bidirectional ships as a plain non-nullable bool defaulting to false on the UNLABELLED associate() only — FOUND-03's measured answer, pinned by reflection so reverting to ?bool cannot happen quietly. Amended 2026-07-27: the two LABELLED writes take the inverse direction's own labels (inverseLabel / inverseLabels) instead, because FOUND-03 run 2 measured a paired label carrying a different NAME in each direction (Deals forward, People inverse), so a boolean could only resolve the reversed pair under the forward label — the label-level form of falling back to the inverse typeId. A reverse write is therefore inexpressible without naming that direction's labels
- [Phase ?]: Two-direction writes resolve every direction before issuing any request, so an unresolvable reverse direction writes nothing and a caller's retry is safe
- [Phase ?]: Architecture rules R2-R5 allow ReyemTech\Hubspot\Exceptions as of 2026-07-27: the package exception hierarchy is a cross-cutting namespace, not a layer, so every layer must be able to throw it or STANDARDS §9's single shared hierarchy and its no-raw-SDK-exception rule are mutually impossible. No layer boundary moved and R6/R8 are untouched; tests/Arch/ResolverSeamTest.php pins the permission with a committed fixture per layer, and all ten rules still fire
- [Phase ?]: assertSynced takes the object type as a string and Phase 4 widens it to accept a bound model; widening a parameter is caller-safe and semver-safe on a final class (D-17)
- [Phase ?]: assertAssociated takes an AssociationPair, not two object references — an assertion whose arguments can be transposed cannot be trusted about a direction; Phase 4 adds a model-pair factory instead
- [Phase ?]: No fake assertion reads a response; RecordedRequest holds none, because an association read returns associationTypes in no guaranteed order and proves nothing about what was written
- [Phase ?]: With no frozen clock the fake stamps one fixed instant rather than the real one, and never mutates the global clock — determinism must hold across processes, not only within one
- [Phase ?]: assertWebhookHandled deferred to Phase 5 with recorded reasoning: no webhook path exists, a no-op stub would pass and prove nothing, and adding it later is semver-safe
- [Phase ?]: assertSynced's property subset must be carried by ONE record, never assembled from several — Codex P1 on PR #20; the per-property search stays only as the diagnosis producing the useful message

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

- **One unsigned decision remains: #5, `final` by default** (working default: `final`). Six of the
  seven are signed and locked. **Now overdue rather than upcoming:** this decision said "confirm
  before or during Phase 2", and Phase 2 has shipped under the working default — every class in
  `src/Gateway/`, `src/Testing/` and `src/Exceptions/` is `final`, with extension provided through
  interfaces rebound in the container (`ObjectGatewayContract`, `AssociationGatewayContract`,
  `AssociationTypeResolver`). Reversing it later is not free: dropping `final` is semver-safe, but
  consumers who worked around it by wrapping rather than rebinding would be stranded. Owner
  confirmation is wanted before Phase 3 widens the surface further

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

- ~~Mutation required check (quality.yml) will report red for the remainder of Phase 1: pest --mutate --min=80 over the deliberately-empty src/ triggers PHPUnit's failOnPhpunitWarning path (WARN + exit 1, no score computed), mirroring plan 01's already-flagged coverage-floor gap. Resolves automatically once Phase 2 adds the first mutable file under src/.~~ **RESOLVED as predicted** — the gate has computed a real score since 02-01 and ends Phase 2 at MSI 98.84% against a floor of 80, with 7 documented equivalent survivors (string casts the SDK re-coerces, and unreachable `?? ''` fallbacks). Do not chase them.
- Git-history attribution defect: commit 022b9e6 (plan 05) accidentally includes three of plan 04's task-2 files (tests/Arch/LayerBoundariesTest.php, SecretLoggingTest.php, StrictTypesTest.php) due to concurrent git staging in a shared (non-worktree) working directory. Content is correct and both plans are green; plan 04 lacks a dedicated GREEN commit for its rule implementations in git log. See 01-05-SUMMARY.md Issues Encountered.
- [Phase 3]: `DefinitionsApi` lives in `HubSpot\Client\Crm\Associations\V4\Schema\Api`, not the `V4\Api` REG-02 named — so the definitions read is a Gateway-owned collaborator (`Gateway\AssociationDefinitionsGateway`) with its own contract, not a fifth method on `AssociationGatewayContract` whose every method takes a record pair
- [Phase 3]: `ExceptionTranslator` gained a THIRD SDK namespace (`Associations\V4\Schema`) only once something called it — Phase 2 excluded it deliberately, and the arch test that requires every referenced SDK `ApiException` to be recognised is what forced it at the right moment
- [Phase 3]: `hubspot:associations:sync` leaves `inverse_type_id` NULL on every row. The two directional `getPage()` responses share no join key, and no read model in the pinned SDK exposes the pairing (`PublicAssociationDefinitionCreateRequest::$inverseLabel` is a WRITE model) — so it is populated only by observation, in `hubspot:associations:doctor`
- [Phase 3]: The sync SKIPS definitions HubSpot returns with a null label rather than writing them. `AssociationTypeResolver::resolve()` takes a non-nullable label and the unlabelled write path never consults the registry, so such a row is unreachable — and two HubSpot-defined types on one direction would share the `default:` key and overwrite each other
- [Phase 3]: `hubspot:doctor` ships REG-04a only and NAMES its absent bound-model section in three lines rather than omitting it; REG-01 and REG-04 stay open at the end of Phase 3 with only their Phase 3 halves done
- [Phase 3]: `Testing\HubspotFake` keys canned responses by ROUTE, not object type: the definitions route is keyed `definitions:{from}>{to}` because reconciling a pair reads both directions and each returns its own labels. The default-response family moved out to `Testing\DefaultResponses`, the extraction 02-06's deferred items named

## Deferred Items

| Category | Item | Status | Deferred At |
|----------|------|--------|-------------|
| Compatibility | Delete `Compat\Tapp` (deprecated from day one) | v2 | 2026-07-26 |
| Scope | Marketing / CMS / Conversations APIs (Meetings embed is a recorded exception, not a precedent) | v2 | 2026-07-26 |
| Process | Commit signing | v2 | 2026-07-26 |
| ~~Docs~~ | ~~A `docs/` site~~ → **promoted to v1 as SHIP-04, Phase 9** | v1 | promoted 2026-07-26 |

## Session Continuity

Last session: 2026-07-29
Stopped at: Completed 03-03-PLAN.md — Phase 3's three plans all executed; 03-03 on a pushed PR awaiting the orchestrator's merge
Resume file: None
