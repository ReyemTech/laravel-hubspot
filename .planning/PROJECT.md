# reyemtech/laravel-hubspot

## What This Is

A standalone public Composer package giving Laravel applications a complete HubSpot CRM
integration: **every** CRM object type — contacts, companies, deals, products, line items,
tickets, quotes and custom (`p_*`) objects — through one generic core built on the official SDK's
generic objects API, with **directional associations** as a first-class concept and **inbound
webhooks** done properly. It is for Laravel developers who currently have two bad options: the
official SDK with no Laravel glue, or `tapp/laravel-hubspot`, which handles contacts and companies
only and whose per-type-client design cannot extend past them without a rewrite.

## Core Value

A developer runs `composer require`, adds one trait to one model, and syncs any HubSpot CRM object
type — with no per-type code, no migration step, and no chance of writing an association backwards.

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
- **Strategy notes**: Ecosystem measurement in `BRIEF.md`; the tapp audit in design spec §1.1.

## Requirements

Full, ID'd list with acceptance criteria and phase traceability: `.planning/REQUIREMENTS.md`
(24 v1 requirements across 7 categories).

### Validated

<!-- Shipped and confirmed valuable. -->

(None yet — nothing has been built. The repository holds `BRIEF.md`, `STANDARDS.md`, `CLAUDE.md`,
the design spec, and no code.)

### Active

- [ ] **Foundation (FOUND-01..03)** — repository, full CI matrix, branch protection with every
      standards gate green on an empty package; `SECURITY.md` from day one; the §6.4
      association-inverse empirical probe
- [ ] **Release (REL-01..02)** — Packagist name claimed and the GitHub↔Packagist integration wired;
      first tagged release verified end to end
- [ ] **Gateway (GW-01..04)** — generic object core, directional `AssociationGateway`, typed error
      hierarchy, `Hubspot::fake()`
- [ ] **Registry (REG-01..04)** — object type normalisation, directional association registry with
      cache and database stores, zero-migration install, diagnostics commands
- [ ] **Sync (SYNC-01..05)** — model binding, `PropertyMapper`, `SyncsToHubspot` + observer + job,
      delete policy, escape hatches
- [ ] **Webhooks (HOOK-01..03)** — signature verification, dispatch, idempotency, typed events,
      subscription sync, optional audit trail
- [ ] **Adoption (SHIP-01..03)** — `hubspot:install`, tapp compat shim, documentation set

### Out of Scope

- **A CRM-agnostic driver layer** — CRM abstractions leak, and nobody has asked for one.
- **Replacing the official SDK** — the package wraps it; `Gateway` is the only layer naming
  `HubSpot\*`, which is what makes it swappable.
- **Marketing / CMS / Conversations APIs** — CRM only, until someone asks.
- **Building on `tapp/laravel-hubspot`** — its per-type-client design is the exact thing being
  removed; compatibility is a shim, never a design input.
- **`spatie/laravel-package-tools`** — a runtime dependency to save ~80 lines of service provider.
- **`spatie/laravel-webhook-client`** — forces its `webhook_calls` migration on every consumer,
  contradicting zero-migration install. Its good ideas are mirrored without its schema.
- **`fakerphp/faker` in production** — `require-dev` only, every call site guarded by `class_exists()`.
- **100% coverage, Rector in CI, commit signing, a `docs/` site** — rejected deliberately in
  STANDARDS.md so nobody re-proposes them in month three.

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

**Origin.** `ReyemTech/laravel` syncs `Lead`, `Contact` and `HealthCheckIntake` all to HubSpot
contacts — a many-to-one binding tapp's global `contact_id_column` cannot express. That application
is the first consumer.

**Five things that bite if the spec is skipped.** (1) Association type ids are directional and
different in each direction — writing the inverse silently associates the wrong way. (2) There is no
unarchive endpoint; the SDK's delete is `archive()`. (3) `$request->fullUrl()` breaks webhook
signatures — Symfony sorts query params, HubSpot signs byte-for-byte. (4) The webhook secret is the
app's client secret, not the PAT. (5) Bindings are many-to-one.

**Known agent hazard.** An agent working in this workspace will read `apps/laravel`'s CLAUDE.md,
which mandates PHPUnit and says to convert Pest to PHPUnit, and will try to convert the suite. That
rule is app-scoped and does not carry here. Pest is locked (D-08).

**Documents.** `BRIEF.md` is the entry point. `docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md`
is the design. `STANDARDS.md` is binding. `CLAUDE.md` carries the workspace-default overrides.
Ingest artefacts: `.planning/intel/` and `.planning/INGEST-CONFLICTS.md`.

## Constraints

- **Tech stack**: PHP `^8.2`; Laravel 11.x and 12.x (Laravel 10 is past EOL); `hubspot/api-client:^14.1`.
  Every supported combination runs in CI against both `prefer-stable` and `prefer-lowest`. A version
  not tested is not supported, and the README says so.
- **Tooling**: Standalone public package — **not** part of the ReyemTech Laravel application. No Sail,
  no Docker. Run `vendor/bin/pest`, `vendor/bin/pint`, `vendor/bin/phpstan` directly; test the matrix
  via `orchestra/testbench`.
- **Dependencies**: Production `require` is exactly six packages — `php`, `hubspot/api-client`,
  `illuminate/contracts`, `illuminate/support`, `illuminate/database`, `laravel/prompts`. A seventh
  needs written justification in the PR description and the reviewer's default answer is no.
- **Protocol — association direction**: Association type ids are directional and differ per direction
  (Contact→Company 279, Company→Contact 280; Deal→LineItem 19, LineItem→Deal 20; Note→Contact 202,
  Contact→Note 201). The primitive is a directed pair. A registry miss **throws**, naming the
  direction, and **never** falls back to the inverse id.
- **Protocol — webhook signature**: Reconstruct the raw request URI; never `$request->fullUrl()`.
  Delegate HMAC to `HubSpot\Utils\Signature::isValid()`. Fails closed by default. The key is the app
  client secret, not the PAT.
- **Architecture**: `Gateway` → `hubspot/api-client`; `Registry` → `Gateway`; `Sync` → `Registry`,
  `Gateway`; `Webhooks` → `Registry`, `Gateway`. `Gateway` is the only layer permitted to name
  `HubSpot\*`. Anything reaching upward fails the build.
- **Compatibility**: Zero-migration install — `composer require` plus a trait works with no publish
  step and no `migrate`. `loadMigrationsFrom()` fires only when a database store is active.
- **Testing**: No test performs real network I/O in the default suite; it runs green with no
  credentials and no internet. Integration tests are a separate, opt-in, secret-gated suite, never
  required to merge.
- **Process**: TDD — the RED test commit precedes the GREEN implementation commit, and the sequence
  is visible in `git log` on `main`. No PHPStan baseline, ever.
- **Phasing**: Phase 0 of the design spec (Roadmap Phase 1) is non-optional and comes first. Each
  phase ships green against the full matrix with coverage and MSI floors met. No phase merges with a
  gate disabled "temporarily" — turning gates on later never happens.

## Locked Decisions

Extracted from `STANDARDS.md` (ADR, precedence 0), treated as binding per the ingest direction.
Full text and rationale: `.planning/intel/decisions.md`. The `DEC-*` name after each entry is the
intel-file key.

<decisions>

### Support matrix and dependencies

- **D-01:** Support Laravel 11.x and 12.x and `hubspot/api-client:^14.1`; every supported combination runs in the CI matrix against both `prefer-stable` and `prefer-lowest`. A version not tested is not supported and the README says so. (DEC-support-matrix)
- **D-02:** Production `require` is exactly six packages — `php`, `hubspot/api-client`, `illuminate/contracts`, `illuminate/support`, `illuminate/database`, `laravel/prompts`. A seventh requires written justification in the PR description; the reviewer's default answer is no. The rule being encoded is no third-party runtime dependencies. (DEC-runtime-dependencies)
- **D-03:** Three packages are excluded deliberately — `spatie/laravel-package-tools` (hand-roll the service provider instead), `spatie/laravel-webhook-client` (forces its `webhook_calls` migration on every consumer), and `fakerphp/faker` from production (`require-dev` only, every call site guarded by `class_exists()`). (DEC-excluded-dependencies)

### Static analysis, style, and code shape

- **D-04:** PHPStan + Larastan at level 9 (max) with `checkModelProperties: true`. A baseline file is **forbidden** — suppression is per-line, never per-file, and always carries a written reason. CI fails on any new error; there is no "fix it later" mode. (DEC-phpstan-level-9)
- **D-05:** Pint with the `laravel` preset and a committed `pint.json` so the ruleset is explicit rather than implied. CI runs `pint --test` and fails on any diff. (DEC-style-pint)
- **D-06:** File length hard fail at 500 lines (review target 300) and cyclomatic complexity hard fail at 10 (review target 5), enforced by a CI script. Extract behaviour, not shape — two functions answering the same question become one immediately, not on the third occurrence. (DEC-code-shape-limits)
- **D-07:** No `TODO`/`FIXME` reaches `main`. CI greps for them; they become issues instead. (DEC-no-todo-on-main)

### Testing

- **D-08:** Pest is the test framework, deliberately. The `apps/laravel` rule mandating PHPUnit and converting Pest to PHPUnit is app-scoped and does **not** carry here. Reason is tooling: `pest --mutate` and `pest-plugin-arch` are first-class Pest features but need Infection plus deptrac/phpat under PHPUnit. Pest runs on PHPUnit, so PHPUnit-style test classes work unmodified. (DEC-test-framework-pest)
- **D-09:** Architecture tests enforce four layer boundaries — `Gateway` may depend on `hubspot/api-client`; `Registry` on `Gateway`; `Sync` on `Registry` and `Gateway`; `Webhooks` on `Registry` and `Gateway`. `Gateway` is the only layer permitted to reference `HubSpot\*`. Anything reaching upward fails the build. (DEC-architecture-layer-boundaries)
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
- **D-18:** A typed exception hierarchy rooted at a package-owned `HubspotException` interface — `ConfigurationException`, `AssociationTypeException`, `ObjectTypeException`, `ApiException`. A raw `HubSpot\Client\...\ApiException` must never reach userland. Every exception message names the fix, not just the fault. (DEC-exception-hierarchy)

### Security

- **D-19:** Tokens and client secrets are never logged, never in exception messages, never in `dd()`-able state. An architecture test greps for the config keys in log calls. (DEC-no-secret-logging)
- **D-20:** Webhook signature verification fails **closed** by default. `enforce => false` exists for transitions and logs loudly on every request. (DEC-webhook-fail-closed)
- **D-21:** Signature comparison uses `hash_equals`, delegated to `HubSpot\Utils\Signature::isValid()`. The package does not hand-roll HMAC. (DEC-no-hand-rolled-hmac)
- **D-22:** `SECURITY.md` with a private disclosure address is published **from day one**. Dependabot enabled; security advisories are patch releases within 48 hours. This overrides design spec §13, which schedules `SECURITY.md` in its Phase 5 — ADR precedence places it in the first phase. (DEC-security-md-day-one)

### Performance

- **D-23:** Batch endpoints are used wherever HubSpot offers one — syncing a collection issues one batch request, not N. N+1 API calls are a test failure, not a code smell: `Hubspot::fake()` counts requests and the sync tests assert exact call counts. No API call in a request lifecycle by default; sync is queued unless explicitly told otherwise. (DEC-performance-batching)

### Git, CI, and release

- **D-24:** Every feature branch starts from a freshly pulled `main`. Branching from a branch is strictly forbidden — if work depends on unmerged work, the dependency merges first. Stale branches rebase onto a fresh `main` with `--force-with-lease`, never `--force`. Branch names: `feat/`, `fix/`, `chore/`, `docs/` plus a short slug. (DEC-branching)
- **D-25:** **Signed off 2026-07-26.** Merge commits stay (not squash), and commitlint on every commit is therefore **mandatory** and a required CI check. The deciding argument is D-13: preserving the RED→GREEN sequence into `main` only works with merge commits. Accepted costs: contributor friction from commitlint, and a stray `feat:` inside a branch bumping the minor version, which review must catch. (DEC-merge-commits-vs-release-please)
- **D-26:** Conventional Commits on every commit, not merely the PR title, enforced by commitlint in CI. (DEC-conventional-commits)
- **D-27:** Every PR states what was verified and how — "tests pass" is not a verification statement; naming the command and its result is. PRs are reviewable in one sitting; over ~400 changed lines the description must say why it could not be split. No PR merges red — not "it's unrelated", not "it's flaky". (DEC-pr-standards)
- **D-28:** release-please owns versioning and `CHANGELOG.md`; nobody edits the changelog by hand. `main` is always releasable. **Packagist is wired, not manual** — release-please cuts the tag and GitHub release but does not publish; the GitHub↔Packagist App/webhook turns a tag into an installable version. Without it, tags land and Packagist never notices, so the package looks abandoned while `main` is green. The Packagist name is claimed in the first phase, before the vendor namespace, README and docs are written against it; the accepted trade-off is a public `dev-main` with no functionality until the first tag. (DEC-releases)
- **D-29:** `main` is protected — PR required, CI required, no direct pushes, no force-push. Required checks: tests (full matrix), Pint, PHPStan, `pest --mutate`, architecture tests, `composer audit`, BC check, **commitlint**, and **`composer validate --strict`**. Plus `CODEOWNERS` and PR and issue templates, with the PR template carrying the Definition of Done. (DEC-branch-protection)
- **D-30:** Definition of Done — seven boxes ticked before review is requested: (1) started as a RED test, (2) full matrix green, (3) coverage ≥95% and MSI ≥80%, (4) Pint and PHPStan clean with no new baseline, (5) docs and `UPGRADE.md` updated in this PR, (6) no new runtime dependency or justified in the description, (7) public API changes are semver-assessed. (DEC-definition-of-done)
- **D-31:** `composer audit` runs in CI and fails the build on any advisory. Dependabot weekly, with patch and minor dev-dependency bumps auto-merging on green. Dependencies are updated at the start of a work cycle, never mixed into a feature PR. (DEC-dependency-audits)

### Documentation and rejections

- **D-32:** The README opens with a 60-second quickstart — install, one model, one sync. Every public method has a usage example; signature-only reference is not documentation. The association direction table (279 vs 280, 19 vs 20, 201 vs 202) is documented prominently as the single most common source of HubSpot integration bugs. `CONTRIBUTING.md` states these standards and that CI enforces them, so nobody discovers the mutation-score floor from a red build. (DEC-documentation)
- **D-33:** Four practices rejected deliberately so nobody re-proposes them in month three — commit signing (revisit only if the package gains maintainers beyond ReyemTech), 100% coverage, Rector in CI (run deliberately at version bumps, not as a gate), and a `docs/` site (README plus inline examples until there is enough surface to justify one). (DEC-explicit-rejections)

</decisions>

## Open Decisions — Awaiting Sign-Off

These five are **proposed, not locked**. Each carries the value stated in the `STANDARDS.md` body as
its working default, and all three source documents agree **none of them gate the first phase**.
`BRIEF.md`'s instruction is explicit: *"Ask Mario rather than assuming."* Do not silently promote
these to locked.

| # | Item | Working default | Why it is open |
|---|------|-----------------|----------------|
| 1 | PHP floor (DEC-php-floor) | `^8.2` | Diverges from `apps/laravel`'s `^8.3`. A library excluding 8.2 excludes a large share of installs for no nameable benefit. |
| 3 | `declare(strict_types=1)` everywhere (DEC-strict-types) | On, enforced by an architecture test | Justification is specific: HubSpot object ids are strings that look like integers; coercive typing makes `"0"`, `0` and `""` silent equivalents, and a wrong id writes to the wrong CRM record. |
| 4 | Coverage / mutation floors (DEC-coverage-and-mutation-floors) | 95% line coverage, 80% MSI, both CI-enforced | Real floors that will occasionally block a merge. Lower them now rather than under deadline. |
| 5 | `final` by default (DEC-final-by-default) | Every class `final` unless extension is documented | Unsealing later is a patch; sealing later is a breaking change. Costs consumer flexibility; the escape hatch is the interfaces the layer design already provides. |
| 6 | Function length ceiling (DEC-function-length-limit) | Hard fail 150 lines, review target 40 | The source itself notes a 150-line function should never survive review — 40 is the number that will actually operate. |

**Also open, and empirical rather than deliberative:** the design spec §6.4 association-inverse
question — whether creating an association from A to B makes it readable from B to A. HubSpot's docs
do not state it. It is settled by **running the probe** (FOUND-03), not by reasoning, and a developer
test account is confirmed available.

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Build new rather than extend `tapp/laravel-hubspot` | tapp uses the SDK's per-type clients, so each object type costs a hand-written service (601 + 405 lines of near-duplicates for two types). Adding the five missing types their way is ~2,500 lines, and associations are unmodelled. | — Pending validation |
| Generic objects API as the core | One set of model classes serves any object type including custom `p_*`, making deals, products, line items, tickets and custom objects nearly free instead of ~500 lines each. | — Pending validation |
| Associations modelled as a directed pair, registry miss throws | Directional type ids differ per direction; a silent inverse fallback is how 202 gets written where 201 belongs, and nobody notices for months. | — Pending validation |
| Merge commits, not squash; commitlint mandatory (D-25) | Only merge commits preserve the RED→GREEN sequence into `main`, which is the point of D-13. | ✓ Signed off 2026-07-26 |
| Pest, not PHPUnit (D-08) | `pest --mutate` + `pest-plugin-arch` deliver the mutation floor and layer-boundary tests in one runner; PHPUnit needs four tools. | ✓ Settled |
| Phase 0 non-optional and first | It contains an empirical probe whose answer changes an API default, plus every standards gate green on an empty package. Turning gates on later never happens. | — Pending validation |
| Roadmap phases renumbered 1-6 (spec §13 numbers them 0-5) | GSD's `roadmap.analyze` silently drops a `### Phase 0:` header — verified 2026-07-26, `phase_count` came back 1 for a two-phase roadmap. Structure and ordering are unchanged; only the label shifts. Mapping table in `ROADMAP.md`. | ✓ Verified empirically |

---
*Last updated: 2026-07-26 after `new-project-from-ingest` (source: `.planning/intel/`, `INGEST-CONFLICTS.md`)*
