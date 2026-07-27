# reyemtech/laravel-hubspot

## What This Is

A standalone public Composer package giving Laravel applications a complete HubSpot integration:
**every** CRM object type — contacts, companies, deals, products, line items, tickets, quotes and
custom (`p_*`) objects — through one generic core built on the official SDK's generic objects API,
with **directional associations** as a first-class concept and **inbound webhooks** done properly.

Since 2026-07-26 it also covers the three things that came out of instrumenting a real funnel ahead
of paid acquisition spend: **intent signals** (recording behavioural signals against HubSpot records
the way a `dataLayer` push records them for GA4), **attribution properties** (paid click ids and
first-touch landing data surviving a 3-10 week sales cycle, so ad spend can be traced to pipeline),
and a **Meetings embed component** (a Blade component wrapping HubSpot's meetings iframe with a
nonce-aware, origin-validating booking listener). All three are currently hand-rolled in every
Laravel app doing HubSpot-backed lead capture.

It is for Laravel developers who currently have two bad options: the official SDK with no Laravel
glue, or `tapp/laravel-hubspot`, which handles contacts and companies only and whose per-type-client
design cannot extend past them without a rewrite.

## Core Value

A developer runs `composer require`, adds one trait to one model, and syncs any HubSpot CRM object
type — with no per-type code, no migration step, and no chance of writing an association backwards.

**Extended 2026-07-26:** …and records intent signals against an anonymous visitor that become
attributed contact properties the moment an email appears, without a single API call in the request
lifecycle.

## Business Context

- **Customer**: Laravel developers integrating HubSpot CRM — no payment, adoption is the currency.
- **Revenue model**: None directly. Open-source; the return is ReyemTech engineering reputation and
  the package being the one its own application depends on.
- **Success metric**: ⚠️ **ASSUMED — confirm before this drives prioritisation.** Derived from
  BRIEF.md's "Why it exists", not stated by any ingested document: *the package becomes the
  maintained Laravel HubSpot option that covers every CRM object type through one generic core,
  with directional associations and inbound webhooks — the three things `tapp/laravel-hubspot`
  (6,203 installs) structurally cannot do.* Concretely: `composer require`, one trait on a model,
  any CRM object type synced, no per-type code and no migration step.
- **Strategy notes**: Ecosystem measurement in `BRIEF.md`; the tapp audit in core design spec §1.1.
  The signals/attribution case is grounded in ~$1,500-2,500/month of planned paid acquisition spend
  in the first consuming application, which is what made "ad spend traced to pipeline" a real
  requirement rather than a nice-to-have.

## Requirements

Full, ID'd list with acceptance criteria and phase traceability: `.planning/REQUIREMENTS.md`
(44 v1 requirements across 10 categories, up from 24 across 7 on 2026-07-26).

### Validated

<!-- Shipped and confirmed valuable. -->

(None yet — nothing has been built. The repository holds `BRIEF.md`, `STANDARDS.md`, `CLAUDE.md`,
two design specs, one candidate brief, and no code.)

### Active

- [ ] **Foundation (FOUND-01..05)** — repository, the full 12-job CI matrix, branch protection with
      every standards gate green on an empty package; `SECURITY.md` from day one; the Node/pnpm
      toolchain with the Vitest floor and docs build; six-layer architecture rules; the §6.4
      association-inverse empirical probe
- [ ] **Release (REL-01..02)** — local release plumbing (`composer validate --strict`,
      release-please); Packagist and the first public release, **owner-gated**
- [ ] **Gateway (GW-01..04)** — generic object core, directional `AssociationGateway`, typed error
      hierarchy, `Hubspot::fake()`
- [ ] **Registry (REG-01..04)** — object type normalisation, directional association registry with
      cache and database stores, zero-migration install, diagnostics commands
- [ ] **Sync (SYNC-01..05)** — model binding, `PropertyMapper`, `SyncsToHubspot` + observer + job,
      delete policy, escape hatches
- [ ] **Webhooks (HOOK-01..03)** — signature verification, dispatch, idempotency, typed events,
      subscription sync, optional audit trail
- [ ] **Signals (SIG-01..08)** — buffer + gated migration, `SignalRecorder`, `identify()`,
      `RollUpCalculator`, the four merge verbs, `FlushSignalsJob`, the `local` store, signal
      assertions
- [ ] **Signal stores & attribution (STORE-01..03, ATTR-01, RES-01)** — `custom_object` and
      `timeline` drivers, `hubspot:signals:prune`, the attribution naming convention, the four
      HubSpot capability verifications
- [ ] **Frontend (FE-01..04)** — `<x-hubspot::meetings>`, the origin-validating listener, CSP nonce
      and `frame-src` recipe, the isolated publishable namespace
- [ ] **Adoption (SHIP-01..04)** — `hubspot:install`, tapp compat shim, the documentation set, the
      Astro/Starlight site

### Out of Scope

- **A CRM-agnostic driver layer** — CRM abstractions leak, and nobody has asked for one.
- **Replacing the official SDK** — the package wraps it; `Gateway` is the only layer naming
  `HubSpot\*`, which is what makes it swappable.
- **Marketing / CMS / Conversations APIs** — CRM only, **with one recorded exception**: the Meetings
  embed (core spec §2 as amended 2026-07-26, signals spec §10.1). It is a frontend widget rather than
  a CRM API call, adopted on the reading that this package's differentiator is *inbound* signal — and
  a booking confirmation is inbound signal whose trust problem is the same class as webhook signature
  verification. The `Frontend` layer's isolation is what keeps the exception from spreading.
- **Building on `tapp/laravel-hubspot`** — its per-type-client design is the exact thing being
  removed; compatibility is a shim, never a design input.
- **`spatie/laravel-package-tools`** — a runtime dependency to save ~80 lines of service provider.
- **`spatie/laravel-webhook-client`** — forces its `webhook_calls` migration on every consumer,
  contradicting zero-migration install. Its good ideas are mirrored without its schema.
- **`fakerphp/faker` in production** — `require-dev` only, every call site guarded by `class_exists()`.
- **An eighth production dependency** — seven is the list.
- **A cache-backed signal buffer** — cache is evictable by definition; the `li_fat_id` case needs 90
  days, and losing the buffer loses the attribution the feature exists to protect. Better explicitly
  off than silently lossy.
- **The package reading cookies, session or request state for signals** — the app supplies the visitor
  id, which is what keeps `Signals` a clean peer layer.
- **Capture and persistence of paid click ids** — app-side by design, not an omission.
- **Autonomous publishing** — Packagist registration, the GitHub↔Packagist webhook and the first
  public release are owner-gated.
- **100% coverage, Rector in CI, commit signing** — rejected deliberately in STANDARDS.md so nobody
  re-proposes them in month three. *(A `docs/` site was on this list and was **adopted** on
  2026-07-26 — the rejection was explicitly conditional on surface area, and the condition fired.)*

## Context

**Ecosystem gap (measured 2026-07-26).** `hubspot/api-client` (official) ~16M installs, the API with
no Laravel glue. `tapp/laravel-hubspot` 6,203 installs / 2 stars — contacts and companies only,
outbound only. `stechstudio/laravel-hubspot` — Eloquent-style reads, 0.x. `concept7/hubspot-webhook-client`
105 installs / 0 stars — inbound via `spatie/laravel-webhook-client`. The leading package has 6k
installs and inbound webhooks are effectively unoccupied.

**Why tapp is not a foundation.** It is well maintained (246 commits, 154 in the last 12 months, 0
open issues, real CI) and still not extensible. `HubspotContactService` (601 lines) and
`HubspotCompanyService` (405 lines) are independently hand-written near-duplicates with diverging
method names and return types. Zero occurrences of deal, product, line item, ticket or quote in
`src/`. Config is per-object by construction (`contact_id_column`, `company_id_column`). Associations
exist only as contact↔company, buried inside the company service. `MockHubspotClient` is not a fake —
it throws on `crm()`. Root cause: the SDK's *per-type* clients, so each object type costs a
hand-written service. Adding the five missing types their way is ~2,500 lines of near-duplicate code.

**The unlock.** The official SDK ships a *generic* objects API (`crm()->objects()->basicApi()`) and
v4 associations (`crm()->associations()->v4()`). One set of model classes serves any object type,
including custom `p_*` objects, which makes deals, products, line items, tickets and custom objects
nearly free instead of ~500 lines each.

**The signals insight (2026-07-26).** GA4 and HubSpot are not the same kind of sink. GA4 accepts
unlimited fire-and-forget events from anonymous visitors and resolves identity later. HubSpot has
hard API rate limits, requires a contact to exist before anything can be written to it, and models
records as mutable property bags rather than event streams. **A `dataLayer` push cannot map 1:1 to a
HubSpot write**, and the entire `Signals` design follows from that: record to a durable local buffer
with no API call, resolve identity when an email finally appears, then compute absolute roll-up
values from the buffer and issue **one** batch write. Because the buffer holds every signal, the
package never has to ask HubSpot what the first touch was — it already knows — which is what makes
`first_wins` correct under concurrency and a queue retry unable to double-count.

**Origin.** `ReyemTech/laravel` syncs `Lead`, `Contact` and `HealthCheckIntake` all to HubSpot
contacts — a many-to-one binding tapp's global `contact_id_column` cannot express. That application
is the first consumer, and its paid-acquisition tracking work is where signals and attribution came
from.

**Five things that bite if the spec is skipped.** (1) Association type ids are directional and
different in each direction — writing the inverse silently associates the wrong way. (2) There is no
unarchive endpoint; the SDK's delete is `archive()`. (3) `$request->fullUrl()` breaks webhook
signatures — Symfony sorts query params, HubSpot signs byte-for-byte. (4) The webhook secret is the
app's client secret, not the PAT — and `timeline` adds a **third** credential class (app id +
developer API key). (5) Bindings are many-to-one.

**One more, added 2026-07-26.** A `postMessage` listener that does not validate `event.origin` is a
real vulnerability, not a style issue — any page can `postMessage`. The Meetings embed's booking
listener validates against `https://meetings.hubspot.com` before trusting a payload, and that is the
most security-sensitive new code in the package. PHP line coverage cannot see a single line of it,
which is why a JavaScript coverage floor exists.

**Known agent hazard.** An agent working in this workspace will read `apps/laravel`'s CLAUDE.md,
which mandates PHPUnit and says to convert Pest to PHPUnit, and will try to convert the suite. That
rule is app-scoped and does not carry here. Pest is locked (D-08).

**Documents.** `BRIEF.md` is the entry point. `docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md`
is the core design. `docs/superpowers/specs/2026-07-26-signals-attribution-and-frontend-design.md`
extends it with signals, attribution and frontend, and **overrides the core spec and the intel files
wherever they disagree**. `STANDARDS.md` is binding and was amended in five places on 2026-07-26.
`CLAUDE.md` carries the workspace-default overrides. `docs/briefs/2026-07-26-meetings-embed-and-attribution.md`
is the superseded candidate brief, kept for provenance. Ingest artefacts: `.planning/intel/` and
`.planning/INGEST-CONFLICTS.md` — **partially stale**, see below.

**Intel staleness.** `.planning/intel/` was extracted before the signals spec existed. It is reliable
for Phase 2-5 content and wrong on: the layer count (says four, now six), the dependency count (six,
now seven), the support matrix (11/12, now 12/13 — Laravel 11 dropped 2026-07-27), the required-check list (no Vitest, no docs
build), the docs-site rejection (now adopted), and publishing (now owner-gated).

## Constraints

- **Tech stack**: PHP `^8.3`; Laravel 12.x and 13.x — **Laravel 11 dropped 2026-07-27** (every
  published `11.x` release carries unpatchable security advisories: `PKSA-m5cs-t1y6-qpcs`,
  `PKSA-3r5d-mb8f-1qw9`, `PKSA-mdq4-51ck-6kdq`; Laravel 11 reached EOSS 2026-03-12); plus
  `hubspot/api-client:^14.1`. The matrix is **rectangular for the first time** — every PHP version
  supports every remaining Laravel major — giving six valid combinations, each run on
  `prefer-stable` and `prefer-lowest` for **12 CI jobs**. A version not tested is not supported,
  and the README says so.
- **Framework API ceiling**: the Illuminate constraint is `^12.0|^13.0`, so **no framework API
  introduced in Laravel 13 may be used without a compatibility shim.** Review checks this.
- **Tooling**: Standalone public package — **not** part of the ReyemTech Laravel application. No Sail,
  no Docker. Run `vendor/bin/pest`, `vendor/bin/pint`, `vendor/bin/phpstan` directly; test the matrix
  via `orchestra/testbench`. Node and pnpm are in CI for Vitest and the docs site.
- **Dependencies**: Production `require` is exactly seven packages — `php`, `hubspot/api-client`,
  `illuminate/contracts`, `illuminate/support`, `illuminate/database`, `laravel/prompts`,
  `illuminate/view`. An eighth needs written justification in the PR description and the reviewer's
  default answer is no. The rule encoded is *no third-party runtime dependencies*, and it is unchanged.
- **Protocol — association direction**: Association type ids are directional and differ per direction
  (Contact→Company 279, Company→Contact 280; Deal→LineItem 19, LineItem→Deal 20; Note→Contact 202,
  Contact→Note 201). The primitive is a directed pair. A registry miss **throws**, naming the
  direction, and **never** falls back to the inverse id.
- **Protocol — webhook signature**: Reconstruct the raw request URI; never `$request->fullUrl()`.
  Delegate HMAC to `HubSpot\Utils\Signature::isValid()`. Fails closed by default. The key is the app
  client secret, not the PAT.
- **Protocol — postMessage**: Validate `event.origin` against `https://meetings.hubspot.com` before
  trusting any payload. `meetingBookSucceeded` is community-documented, not a versioned HubSpot API —
  it is an enhancement, never the source of truth, and is deduplicated against server-side webhook
  confirmation.
- **Architecture**: Six layers. `Gateway` → `hubspot/api-client`; `Registry` → `Gateway`; `Sync` →
  `Registry`, `Gateway`; `Webhooks` → `Registry`, `Gateway`; `Signals` → `Registry`, `Gateway`;
  `Frontend` → the public facade **only**. `Gateway` is the only layer permitted to name `HubSpot\*`.
  `Signals` may not depend on `Sync` or `Webhooks` — it is a peer, not a consumer. `Frontend` may not
  reference `HubSpot\*` or any internal layer. Anything reaching upward fails the build.
- **Compatibility**: Zero-migration install — `composer require` plus a trait works with no publish
  step and no `migrate`. `loadMigrationsFrom()` fires only when a database store is active, and the
  signal buffer is gated the same way on `HUBSPOT_SIGNALS`.
- **Testing**: No test performs real network I/O in the default suite; it runs green with no
  credentials and no internet. Integration tests are a separate, opt-in, secret-gated suite, never
  required to merge. Floors: 95% PHP line coverage, 95% JS line coverage, 80% MSI.
- **Process**: TDD — the RED test commit precedes the GREEN implementation commit, and the sequence
  is visible in `git log` on `main`. No PHPStan baseline, ever.
- **Phasing**: Nine phases. Phase 0 of the source documents (Roadmap Phase 1) is non-optional and
  comes first. Each phase ships green against the full matrix with coverage and MSI floors met. No
  phase merges with a gate disabled "temporarily" — turning gates on later never happens.
- **Publishing**: Owner-gated. The repository is `ReyemTech/laravel-hubspot`, created **private**.
  Packagist registration, the GitHub↔Packagist integration and the first public release are not
  autonomous steps and cannot happen at all while the repository is private. GitHub Pages needs a
  paid plan on private repositories, so the docs site may build in CI without deploying.

## Locked Decisions

Extracted from `STANDARDS.md` (ADR, precedence 0) and, from D-35 onward, from the approved
`2026-07-26-signals-attribution-and-frontend-design.md`. Treated as binding. Full text and rationale
for D-01..D-33: `.planning/intel/decisions.md`; the `DEC-*` name after those entries is the intel-file
key. D-34 onward postdate the intel files and have no key.

<decisions>

### Support matrix and dependencies

- **D-01:** **Signed off 2026-07-26 against verified upstream data (laravel.com, php.net); PHP floor amended the same day during Phase 1 research; Laravel 11 dropped 2026-07-27.** PHP floor `^8.3`; Laravel 12.x and 13.x; `hubspot/api-client:^14.1`. The matrix is **rectangular for the first time** — every PHP version (8.3, 8.4, 8.5) supports every remaining Laravel major — giving **six** valid combinations, each run on both `prefer-stable` and `prefer-lowest`, for **12 CI jobs**, no `exclude:` entries needed. **The PHP floor was raised from `^8.2` to `^8.3` on 2026-07-26** because Pest 4, `pest-plugin-arch` 4.x and `pest-plugin-laravel` 4.x all require 8.3, and keeping an 8.2 leg would have put the unmaintained `pest-plugin-arch` 3.1.1 on those jobs — making architecture tests and mutation scoring behave differently depending on which PHP version ran them. **Laravel 11 was dropped outright on 2026-07-27**: every published `11.x` release is blocked by live security advisories (`PKSA-m5cs-t1y6-qpcs`, `PKSA-3r5d-mb8f-1qw9`, `PKSA-mdq4-51ck-6kdq`), Laravel 11 reached end of security support on 12 March 2026, and none of those advisories will ever be patched. STANDARDS §12c fails the build on any `composer audit` advisory with no escape hatch, which put the original migration-reach rationale for keeping Laravel 11 in direct conflict with that gate — the owner chose to drop Laravel 11 rather than suppress the advisories or weaken the audit. Laravel 10 stays excluded because it is eighteen months dead, not merely because it is EOL. **Consequence:** the Illuminate constraint is `^12.0|^13.0`, so no framework API introduced in 13 may be used without a shim. A version not tested is not supported and the README says so. (DEC-support-matrix)
- **D-02:** **Amended 2026-07-26.** Production `require` is exactly **seven** packages — `php`, `hubspot/api-client`, `illuminate/contracts`, `illuminate/support`, `illuminate/database`, `laravel/prompts`, `illuminate/view`. An eighth requires written justification in the PR description; the reviewer's default answer is no. The rule being encoded is *no third-party runtime dependencies* and it is **unchanged** — the Illuminate packages are first-party Laravel that this package calls directly, declared rather than assumed. (DEC-runtime-dependencies)
- **D-03:** Three packages are excluded deliberately — `spatie/laravel-package-tools` (hand-roll the service provider instead), `spatie/laravel-webhook-client` (forces its `webhook_calls` migration on every consumer), and `fakerphp/faker` from production (`require-dev` only, every call site guarded by `class_exists()`). (DEC-excluded-dependencies)

### Static analysis, style, and code shape

- **D-04:** PHPStan + Larastan at the pinned major's **maximum** level (`level: max` — PHPStan 2.x's true maximum is 10, not the literal 9 STANDARDS §3 mentions) with `checkModelProperties: true`. A baseline file is **forbidden** — suppression is per-line, never per-file, and always carries a written reason. CI fails on any new error; there is no "fix it later" mode. (DEC-phpstan-level-9)
- **D-05:** Pint with the `laravel` preset and a committed `pint.json` so the ruleset is explicit rather than implied. CI runs `pint --test` and fails on any diff. (DEC-style-pint)
- **D-06:** **Signed off 2026-07-26.** Code shape hard fails at file 500 lines, **function 150 lines**, cyclomatic complexity 10; review targets are 300 / 40 / 5. Enforced by a CI script — over the hard limit fails the build, over the review target needs a sentence in the PR saying why. The *review target* is the number that will actually operate; with everything else in the standards a 150-line function should never survive review. Extract behaviour, not shape — two functions answering the same question become one immediately, not on the third occurrence. (DEC-code-shape-limits)
- **D-07:** No `TODO`/`FIXME` reaches `main`. CI greps for them; they become issues instead. (DEC-no-todo-on-main)

### Testing

- **D-08:** Pest is the test framework, deliberately. The `apps/laravel` rule mandating PHPUnit and converting Pest to PHPUnit is app-scoped and does **not** carry here. Reason is tooling: `pest --mutate` and `pest-plugin-arch` are first-class Pest features but need Infection plus deptrac/phpat under PHPUnit. Pest runs on PHPUnit, so PHPUnit-style test classes work unmodified. (DEC-test-framework-pest)
- **D-09:** Architecture tests enforce the layer boundaries. **Amended 2026-07-26 from four layers to six — see D-35.** `Gateway` is the only layer permitted to reference `HubSpot\*`. Anything reaching upward fails the build. (DEC-architecture-layer-boundaries)
- **D-10:** Determinism is a correctness property. Time is frozen with `Carbon::setTestNow()`, never `sleep()`; randomness is seeded; ordering is never assumed. A test that passes in isolation and fails in the parallel suite is a failing test, not an environment quirk. (DEC-deterministic-tests)
- **D-11:** No skipped, incomplete or risky tests on `main` — `failOnSkipped`, `failOnIncomplete` and `failOnRisky` are enabled. Flaky tests are quarantined within 24 hours. (DEC-no-skipped-tests)
- **D-12:** No test may perform real network I/O. The default suite runs green with no HubSpot credentials and no internet. Integration tests against a live developer portal live in a separate, opt-in suite gated on a secret and are never required to merge. (DEC-no-network-io)
- **D-13:** TDD is the working method, not a preference. Every change starts as a failing (RED) test; the test commit precedes the implementation commit and, with merge-commit SDLC, that sequence is visible in `git log` on `main` forever. Every bug fix opens with a test reproducing the bug and the PR names the commit where it was red. Review checks the sequence because CI cannot. (DEC-tdd)

### Install and configuration

- **D-14:** The package works after `composer require` with no publish step and no `migrate`. Database-backed stores are opt-in via one env var. (DEC-zero-migration-install)
- **D-15:** `hubspot:install` is optional and never required. A package that breaks without an install step gets abandoned at the README. (DEC-install-optional)
- **D-16:** Every key in `config/hubspot.php` carries a comment stating what it does and what breaks if it is wrong. Env vars are namespaced `HUBSPOT_*` and listed in the README with their defaults. (DEC-config-surface)

### Public API and errors

- **D-17:** Semantic versioning strictly; the public API is everything not marked `@internal`. `roave/backward-compatibility-check` runs on every PR to `main` and a detected break fails CI unless the PR is labelled `breaking` and targets the next major. Deprecations live for two minor versions minimum, emit `E_USER_DEPRECATED`, and name their replacement. `UPGRADE.md` is updated in the same PR as the breaking change, not at release time. (DEC-semver-and-bc)
- **D-18:** A typed exception hierarchy rooted at a package-owned `HubspotException` interface — `ConfigurationException`, `AssociationTypeException`, `ObjectTypeException`, `ApiException`, and (2026-07-26) `SignalException`. A raw `HubSpot\Client\...\ApiException` must never reach userland. Every exception message names the fix, not just the fault. (DEC-exception-hierarchy)

### Security

- **D-19:** Tokens and client secrets are never logged, never in exception messages, never in `dd()`-able state. An architecture test greps for the config keys in log calls. (DEC-no-secret-logging)
- **D-20:** Webhook signature verification fails **closed** by default. `enforce => false` exists for transitions and logs loudly on every request. (DEC-webhook-fail-closed)
- **D-21:** Signature comparison uses `hash_equals`, delegated to `HubSpot\Utils\Signature::isValid()`. The package does not hand-roll HMAC. (DEC-no-hand-rolled-hmac)
- **D-22:** `SECURITY.md` with a private disclosure address is published **from day one**. Dependabot enabled; security advisories are patch releases within 48 hours. This overrides core design spec §13, which schedules `SECURITY.md` in its Phase 5 — ADR precedence places it in the first phase. (DEC-security-md-day-one)

### Performance

- **D-23:** Batch endpoints are used wherever HubSpot offers one — syncing a collection issues one batch request, not N. N+1 API calls are a test failure, not a code smell: `Hubspot::fake()` counts requests and the sync tests assert exact call counts. No API call in a request lifecycle by default; sync is queued unless explicitly told otherwise. (DEC-performance-batching)

### Git, CI, and release

- **D-24:** Every feature branch starts from a freshly pulled `main`. Branching from a branch is strictly forbidden — if work depends on unmerged work, the dependency merges first. Stale branches rebase onto a fresh `main` with `--force-with-lease`, never `--force`. Branch names: `feat/`, `fix/`, `chore/`, `docs/` plus a short slug. (DEC-branching)
- **D-25:** **Signed off 2026-07-26.** Merge commits stay (not squash), and commitlint on every commit is therefore **mandatory** and a required CI check. The deciding argument is D-13: preserving the RED→GREEN sequence into `main` only works with merge commits. Accepted costs: contributor friction from commitlint, and a stray `feat:` inside a branch bumping the minor version, which review must catch. (DEC-merge-commits-vs-release-please)
- **D-26:** Conventional Commits on every commit, not merely the PR title, enforced by commitlint in CI. (DEC-conventional-commits)
- **D-27:** Every PR states what was verified and how — "tests pass" is not a verification statement; naming the command and its result is. PRs are reviewable in one sitting; over ~400 changed lines the description must say why it could not be split. No PR merges red — not "it's unrelated", not "it's flaky". (DEC-pr-standards)
- **D-28:** **Amended 2026-07-26.** release-please owns versioning and `CHANGELOG.md`; nobody edits the changelog by hand. `main` is always releasable. Packagist is **wired, not manual** — release-please cuts the tag and GitHub release but does not publish; the GitHub↔Packagist App/webhook turns a tag into an installable version. Without it, tags land and Packagist never notices, so the package looks abandoned while `main` is green. **The instruction to claim the Packagist name in the first phase is superseded by D-47:** the repository is private, Packagist requires a public one, and publishing is owner-gated. The first phase keeps only the local half — `composer validate --strict` as a required check, and release-please configuration. (DEC-releases)
- **D-29:** `main` is protected — PR required, CI required, no direct pushes, no force-push. Required checks: tests (full matrix), Pint, PHPStan, `pest --mutate`, architecture tests, **Vitest**, **the docs-site build**, `composer audit`, BC check, **commitlint**, and **`composer validate --strict`**. Plus `CODEOWNERS` and PR and issue templates, with the PR template carrying the Definition of Done. Configuring protection is owner action and may be limited by plan on a private repository. (DEC-branch-protection)
- **D-30:** Definition of Done — seven boxes ticked before review is requested: (1) started as a RED test, (2) full matrix green, (3) coverage ≥95% and MSI ≥80%, (4) Pint and PHPStan clean with no new baseline, (5) docs and `UPGRADE.md` updated in this PR, (6) no new runtime dependency or justified in the description, (7) public API changes are semver-assessed. (DEC-definition-of-done)
- **D-31:** `composer audit` runs in CI and fails the build on any advisory. Dependabot weekly, with patch and minor dev-dependency bumps auto-merging on green. Dependencies are updated at the start of a work cycle, never mixed into a feature PR. (DEC-dependency-audits)

### Documentation and rejections

- **D-32:** The README opens with a 60-second quickstart — install, one model, one sync. Every public method has a usage example; signature-only reference is not documentation. The association direction table (279 vs 280, 19 vs 20, 201 vs 202) is documented prominently as the single most common source of HubSpot integration bugs. `CONTRIBUTING.md` states these standards and that CI enforces them, so nobody discovers the mutation-score floor from a red build. (DEC-documentation)
- **D-33:** **Amended 2026-07-26 — now three rejections, not four.** Commit signing (revisit only if the package gains maintainers beyond ReyemTech), 100% coverage, and Rector in CI (run deliberately at version bumps, not as a gate) remain rejected so nobody re-proposes them in month three. **The `docs/` site rejection is withdrawn — see D-46.** That rejection was explicitly conditional (*"README plus inline examples until there is enough surface to justify one"*) and the condition fired. (DEC-explicit-rejections)

### Signed off 2026-07-26 — promoted from Open Decisions

- **D-34:** `declare(strict_types=1)` in every PHP file, enforced by an architecture test rather than by review. Justification is specific rather than dogmatic: this package passes HubSpot object ids around as strings that look like integers, coercive typing turns `"0"`, `0` and `""` into silent equivalents, and a wrong object id writes to the wrong CRM record. (was STANDARDS decision #3)

### Layers, signals, attribution and frontend — `2026-07-26-signals-attribution-and-frontend-design.md`

- **D-35:** **Six layers, not four.** `Gateway` → `hubspot/api-client`; `Registry` → `Gateway`; `Sync` → `Registry`, `Gateway`; `Webhooks` → `Registry`, `Gateway`; `Signals` → `Registry`, `Gateway`; `Frontend` → the public facade **only**. Two new architecture-test rules: (1) **`Signals` may not depend on `Sync` or `Webhooks`** — it is a peer, not a consumer, because signals are event-shaped and have no local model while `Sync` is model-shaped, and merging them would blur the largest boundary in the package; (2) **`Frontend` may not reference `HubSpot\*`, `Gateway`, `Registry`, `Sync`, `Webhooks` or `Signals`** — it talks to the same public facade a consumer would, which stops the frontend becoming a back door around the boundary that makes the SDK swappable. (signals spec D7, §3; STANDARDS §6 amended; core spec §3 amended)
- **D-36:** Intent signals, attribution and the frontend are **v1 scope, not a later milestone** — the owner's call, made against a stated 6→9 phase cost. Shipping v1 at phase 5 and treating 6-9 as v1.1 was offered and declined. Recorded so the trade-off is visible, not to reopen it. (signals spec D1, §15.1)
- **D-37:** The package ships frontend assets, in an **isolated namespace**. The Meetings embed needs them; isolation keeps the CRM core frontend-free and the layer boundaries meaningful. (signals spec D2)
- **D-38:** The signal buffer **requires the database store**. A cache-backed buffer was explicitly rejected: cache is evictable by definition, the `li_fat_id` case needs a 90-day window, and losing the buffer loses the attribution the feature exists to protect. Better explicitly off than silently lossy. This does not violate zero-migration install — signals are off by default, and enabling them is what requires the migration. (signals spec D6, §7)
- **D-39:** Signals land as **both** events and properties. Properties are what HubSpot workflows and lists can act on; events are what preserve history; neither alone is sufficient. The event-history half sits behind a `SignalStore` **driver contract**, mirroring the `cache`/`database` pattern of core §6.3, because portal tier and credential requirements differ per surface. Three drivers ship in v1 — `local` (default; the only one needing no new credential, tier gate or portal schema), `custom_object` (tier-gated, consumer creates the schema), `timeline` (third credential class, per-consumer developer app). (signals spec D3, D4, D5, §8)
- **D-40:** **No API call in the request lifecycle, and one batch write per flush.** The recorder validates and writes one buffer row, never calling the API; the flush is queued and batched. Roll-ups are **absolute values computed from the buffer**, never read back from HubSpot — so a flush is idempotent, a queue retry cannot double-count, and the read-then-write concurrency hazard disappears entirely because the package already knows what the first touch was. The one surviving caveat — contacts that existed before signals were enabled may hold attribution the buffer never saw — is resolved **per property, explicitly, never silently** by the `first_wins:source|reconcile` modifier, which costs one read per subject on the first flush only and is recorded on the row so it never repeats. (signals spec §5, §5.1)
- **D-41:** Roll-up is a **declarative signal→property map**, consistent with `$hubspotMap` (core §5); a class per signal type would repeat the per-type-class flaw that core §1.1 identifies as tapp's root problem. The merge vocabulary is **closed and has exactly four verbs** — `first_wins:<field>`, `last_wins:<field>`, `increment`, `sum:<field>` — plus a closure for cases the vocabulary does not cover. **`overwrite` does not exist**: an earlier draft listed it and `last_wins` as separate verbs, and they are the same operation. An unknown signal name or merge verb throws `ConfigurationException` naming the fix, and the map is validated **at boot, not at flush**, so a typo fails fast rather than silently dropping data. (signals spec D8, §6)
- **D-42:** `illuminate/view` becomes the **seventh** production dependency, added with the `Frontend` layer. It is first-party Laravel, present in every consumer via `laravel/framework`, and used directly by a component the package ships and renders itself. The list extends; the rule (*no third-party runtime dependencies*) is unchanged. (signals spec §10.2; STANDARDS §2 amended)
- **D-43:** Coverage floors, **signed off 2026-07-26 and extended the same day**: 95% PHP line coverage (Pest + Xdebug/PCOV), **95% JS line coverage (Vitest)**, 80% MSI (`pest --mutate`). These are real floors that will occasionally block a merge; that is the point. The JS floor exists because the booking listener validates `event.origin` before trusting a `postMessage` — the most security-sensitive code in the package — and PHP line coverage cannot see a single line of it. It is affordable because the documentation site already brings Node into CI; on its own, for ~30 lines, it would have been hard to justify. (was STANDARDS decision #4, extended by §6 amendment)
- **D-44:** **The consuming app supplies the visitor id**; the package never reads cookies, session or request state. Any stable string works — a GA4 client id, a first-party cookie value, a session id. This keeps `Signals` free of request-scoped state, which is what lets it stay a clean peer layer. (signals spec D9, §7)
- **D-45:** `SignalException` extends the error hierarchy by exactly one member, rooted at the same `HubspotException` interface, raised when a visitor id is already bound to a different subject. Flush failures surface as `ApiException` and are retried by the queue. A raw SDK exception still never reaches userland. (signals spec §11)
- **D-46:** **A documentation site is adopted** — Astro + Starlight in `site/`, built on push to `main` and published to a `docs-pages` branch, following the pattern proven in `ReyemTech/apps/stint`. One inherited detail carried over deliberately: push with a **PAT rather than `GITHUB_TOKEN`**, because Actions suppresses workflow triggers for commits authored by `GITHUB_TOKEN` and without it the Pages deploy silently never fires. This reverses D-33's conditional rejection because the condition fired, not because the reasoning changed. (signals spec D10, §13; STANDARDS *"Not standards, deliberately"* amended)
- **D-47:** **Publishing is owner-gated. Decided 2026-07-26.** The repository is `ReyemTech/laravel-hubspot`, created **private**. Packagist registration, the GitHub↔Packagist integration and the first public release are deferred until the owner has reviewed the package; publishing is **not an autonomous step** and will not be performed without explicit approval — and cannot happen at all while the repository is private, since Packagist requires a public one. Two further consequences: GitHub Pages needs a paid plan on private repositories, so the docs site may build in CI without deploying; and branch protection rules may be limited by plan. (signals spec §15.1)
- **D-48:** The **Meetings embed is a recorded exception** to the "CRM only" non-goal, adopted deliberately rather than left to contradict it. The reading: this package's differentiator is *inbound* signal, and a booking confirmation is inbound signal whose trust problem — validating that a message genuinely came from HubSpot — is the same class as webhook signature verification. The `Frontend` layer's isolation is what keeps it from eroding the CRM core. Core design spec §2 was amended the same day so the two documents do not contradict each other. (signals spec §10.1; core spec §2 amended)
- **D-49:** **Garbage collection is not optional.** `hubspot_signals` is fed at page-view grain and anonymous visitors who never identify accumulate forever. `php artisan hubspot:signals:prune` deletes flushed rows and unidentified rows older than `retention_days`, defaulting to the 90 days the `li_fat_id` case requires. (signals spec §7)

</decisions>

## Open Decisions — Awaiting Sign-Off

**None remain.** All seven `STANDARDS.md` decisions are now signed off and in the `<decisions>`
block above: #0 merge commits + mandatory commitlint (D-25), #1 PHP `^8.3` and Laravel 12/13
(D-01, Laravel 11 dropped 2026-07-27), #2 Pest (D-08), #3 `strict_types` (D-34), #4 coverage and
MSI floors (D-43), #6 code shape limits (D-06), and — signed off 2026-07-27, during plan 01-08 —
**#5, `final` by default** (DEC-final-by-default): every class is `final` unless extension is an
explicit, documented feature. First applied to `ReyemTech\Hubspot\ServiceProvider`, the package's
first shipped class.

**Still open, and empirical rather than deliberative:**

- **The core spec §6.4 association-inverse question** — whether creating an association from A to B
  makes it readable from B to A. HubSpot's docs do not state it. Settled by **running the probe**
  (FOUND-03), not by reasoning. **Currently blocked**: it needs a HubSpot developer account token the
  executing agent does not hold.
- **The signals spec §8.1 verifications** (RES-01, Phase 7) — HubSpot custom-object tier gates,
  behavioural-event tier gates, current rate limits, and Timeline API credential and scope
  requirements. These are answered **against live HubSpot documentation during Phase 7, explicitly not
  from model recall**, with sources and dates recorded.

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Build new rather than extend `tapp/laravel-hubspot` | tapp uses the SDK's per-type clients, so each object type costs a hand-written service (601 + 405 lines of near-duplicates for two types). Adding the five missing types their way is ~2,500 lines, and associations are unmodelled. | — Pending validation |
| Generic objects API as the core | One set of model classes serves any object type including custom `p_*`, making deals, products, line items, tickets and custom objects nearly free instead of ~500 lines each. | — Pending validation |
| Associations modelled as a directed pair, registry miss throws | Directional type ids differ per direction; a silent inverse fallback is how 202 gets written where 201 belongs, and nobody notices for months. | — Pending validation |
| Merge commits, not squash; commitlint mandatory (D-25) | Only merge commits preserve the RED→GREEN sequence into `main`, which is the point of D-13. | ✓ Signed off 2026-07-26 |
| Pest, not PHPUnit (D-08) | `pest --mutate` + `pest-plugin-arch` deliver the mutation floor and layer-boundary tests in one runner; PHPUnit needs four tools. | ✓ Settled |
| PHP `^8.3`, Laravel 12/13, 12-job matrix (D-01) | Verified against laravel.com and php.net. Laravel 11 was kept initially (2026-07-26) for migration reach, then dropped outright (2026-07-27) once verified as blocked by unpatchable security advisories that conflict with the zero-tolerance `composer audit` gate. | ✓ Signed off 2026-07-26; amended 2026-07-27 |
| Signals as a peer layer, not part of `Sync` (D-35) | Signals are event-shaped and have no local model; `Sync` is model-shaped. Merging them blurs the largest boundary in the package. | ✓ Approved in the signals spec |
| Buffer-first, one batch write per flush (D-40) | A `dataLayer` push cannot map 1:1 to a HubSpot write: HubSpot has hard rate limits, needs a contact to exist first, and stores property bags rather than event streams. Buffering also makes the flush idempotent and removes the read-then-write concurrency hazard. | ✓ Approved in the signals spec |
| Database-backed buffer, cache explicitly rejected (D-38) | The pre-identity window is where attribution value lives, and cache is evictable by definition. Better explicitly off than silently lossy. | ✓ Approved in the signals spec |
| A `Frontend` layer, and the Meetings embed as a recorded exception (D-37, D-48) | A booking confirmation is inbound signal whose trust problem is the same class as webhook signature verification. Isolation keeps the CRM core frontend-free. | ✓ Approved; core spec §2 amended the same day |
| Docs site adopted, reversing a deliberate rejection (D-46) | The rejection was explicitly conditional on surface area. Signals, attribution, `Frontend` and a public `identify()` are that condition firing — recorded so it does not read as drift. | ✓ Approved 2026-07-26 |
| Publishing is owner-gated (D-47) | The repository is private and Packagist requires a public one. Publishing is not an autonomous step. | ✓ Decided 2026-07-26 |
| Phase 0 non-optional and first | It contains an empirical probe whose answer changes an API default, plus every standards gate green on an empty package. Turning gates on later never happens — which is also why the JS gate and docs build land in Phase 1, before anything uses them. | — Pending validation |
| Roadmap phases renumbered 1-9 (sources number them 0-8) | GSD's `roadmap.analyze` silently drops a `### Phase 0:` header — verified 2026-07-26, `phase_count` came back 1 for a two-phase roadmap. Structure and ordering are unchanged; only the label shifts. Mapping table in `ROADMAP.md`. | ✓ Verified empirically |

---
*Last updated: 2026-07-26 — regenerated to nine phases after
`docs/superpowers/specs/2026-07-26-signals-attribution-and-frontend-design.md` and five STANDARDS
amendments. Original bootstrap: `new-project-from-ingest` from `.planning/intel/` +
`INGEST-CONFLICTS.md`.*
